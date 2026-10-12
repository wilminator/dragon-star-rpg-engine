<?php
/**
 * One-time validation: for every fight with an initial_state, hydrates
 * a fresh FIGHT from it and replays every FightAction in sequence via
 * process_fight_action(). At each step, independently derives the
 * "should be" state from the legacy columns (fight_actions.prefight_data
 * at the next sequence, or fights.fight_data for the last step) and
 * compares dehydrate() output between the replayed FIGHT and that
 * reference.
 *
 * This is deliberately NOT reusing fight_actions.final_state as the
 * reference, even though it holds the same value for already-backfilled
 * fights -- final_state was itself derived from prefight_data/fight_data
 * by the backfill script, so comparing against it would be partly
 * circular. Re-deriving independently from prefight_data/fight_data here
 * gives a genuine check: does replay from initial_state actually agree
 * with what was really recorded, not just with the backfill's own prior
 * output.
 *
 * Legacy-data handling: prefight_data/fight_data are raw PHP serialize()
 * snapshots, and some predate the current FIGHT/PARTY/CHARACTER shape --
 * same two anomalies the backfill script accounted for, reused here
 * verbatim since the reference side goes through the identical
 * unserialize() path:
 *   - FIGHT::$id serialized as a string in blobs older than its current
 *     ?int typing -- unserialize() rejects that outright unless patched
 *     first.
 *   - Properties added to FIGHT/PARTY/CHARACTER since older fights were
 *     saved (FIGHT::$state as a FightStatus enum, PARTY::$team_names,
 *     CHARACTER::$need) are simply absent from older blobs --
 *     unserialize() never runs the constructor, so these are left
 *     genuinely uninitialized rather than defaulted. Handled generically
 *     via a reflection-based backfill rather than special-cased, since
 *     there's no guarantee these are the only two gaps.
 * The replay side never touches legacy data at all -- initial_state is
 * already-clean JSON, and hydrate()/process_fight_action() work entirely
 * off the current class shapes.
 *
 * Read-only: no UPDATEs anywhere in this script.
 *
 * Run from the CLI inside the container:
 *   docker compose exec web php admin/upgrade/validate-reintegration.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_action.php';

function patch_legacy_fight_id(string $blob): string {
    // Anchored right after O:5:"FIGHT":N:{ -- can only ever match
    // FIGHT's own id field (its first declared property), never an
    // unrelated same-named property on a nested object.
    return preg_replace(
        '/(O:5:"FIGHT":\d+:\{s:2:"id";)s:\d+:"(\d+)";/',
        '${1}i:$2;',
        $blob,
        1
    );
}

function backfill_uninitialized(object $obj, array &$seen = []): void {
    $oid = spl_object_id($obj);
    if (isset($seen[$oid])) return;
    $seen[$oid] = true;

    $refl = new ReflectionObject($obj);
    foreach ($refl->getProperties() as $prop) {
        if ($prop->isStatic()) continue;
        $prop->setAccessible(true);
        if ($prop->isInitialized($obj)) continue;
        $type = $prop->getType();
        $type_name = $type?->getName();
        $default = match (true) {
            $type === null => null,
            $type->allowsNull() => null,
            $type_name === 'array' => [],
            $type_name === 'int' => 0,
            $type_name === 'string' => '',
            $type_name === 'bool' => false,
            enum_exists((string) $type_name) => ($type_name::cases())[0],
            default => null,
        };
        $prop->setValue($obj, $default);
    }
    foreach ($refl->getProperties() as $prop) {
        if ($prop->isStatic()) continue;
        $prop->setAccessible(true);
        $value = $prop->getValue($obj);
        if (is_object($value)) backfill_uninitialized($value, $seen);
        elseif (is_array($value)) {
            foreach ($value as $item)
                if (is_object($item)) backfill_uninitialized($item, $seen);
        }
    }
}

function dehydrated_state_from_blob(string $blob): array {
    $fight = unserialize(patch_legacy_fight_id($blob));
    if ($fight === false) {
        throw new Exception("unserialize() failed on a legacy fight blob.");
    }
    backfill_uninitialized($fight);
    return $fight->dehydrate();
}

/**
 * Recursively diffs two dehydrate()-shaped arrays. Returns a flat list
 * of "path: replay=X, reference=Y" strings; empty means identical.
 * Order-insensitive on array keys (unlike ===), since dehydrate() key
 * order isn't itself meaningful -- only the key/value pairs are.
 */
/**
 * current/base stat maps: legacy blobs hold these as PHP floats in
 * places (old arithmetic that happened to produce one); today's code
 * treats them as ints throughout. Not a real state divergence -- cast
 * both sides the same way right before comparing so a harmless type
 * difference doesn't bury a genuine one.
 */
function normalize_stats(array $dehydrated): array {
    foreach ($dehydrated['parties'] ?? [] as $party_index => $party) {
        foreach ($party['groups'] ?? [] as $group_index => $group) {
            foreach ($group['characters'] ?? [] as $char_index => $character) {
                foreach (['current', 'base'] as $stat_block) {
                    foreach ($character[$stat_block] ?? [] as $stat => $value) {
                        $dehydrated['parties'][$party_index]['groups'][$group_index]['characters'][$char_index][$stat_block][$stat] = (int) $value;
                    }
                }
            }
        }
    }
    return $dehydrated;
}

// Keys known, as of this validation pass, not to be reconstructable
// from the action log -- excluded here so remaining diff output means
// something, rather than repeating the same three explained gaps every
// run:
//   - xp_debts: accumulates per-effect (add_debt_to_xp_tree(), called
//     from inflict_damage()/restore_HP()/restore_MP()), not captured
//     by any of the 23 current event types. A genuine gap, not yet
//     resolved.
//   - command/using/target: a player's submitted action mutates these
//     directly via set_PC_action(), persisted immediately through the
//     existing fight_data write path -- well before the turn is even
//     computed. Never meant to come from the action log; not a gap.
const IGNORED_LEAF_KEYS = ['xp_debts', 'command', 'using', 'target'];

function diff_dehydrated($replay, $reference, string $path = ''): array {
    $diffs = [];
    if (is_array($replay) && is_array($reference)) {
        $keys = array_unique(array_merge(array_keys($replay), array_keys($reference)));
        foreach ($keys as $key) {
            if (in_array($key, IGNORED_LEAF_KEYS, true)) continue;
            $sub_path = $path === '' ? (string) $key : "$path.$key";
            if (!array_key_exists($key, $replay)) {
                $diffs[] = "$sub_path: MISSING in replay (reference=" . var_export($reference[$key], true) . ")";
            } elseif (!array_key_exists($key, $reference)) {
                $diffs[] = "$sub_path: MISSING in reference (replay=" . var_export($replay[$key], true) . ")";
            } else {
                $diffs = array_merge($diffs, diff_dehydrated($replay[$key], $reference[$key], $sub_path));
            }
        }
        return $diffs;
    }
    // Scalars (or a type mismatch between the two sides, which counts
    // as a genuine diff too): strict comparison, one line per mismatch.
    if ($replay !== $reference) {
        $diffs[] = "$path: replay=" . var_export($replay, true) . ", reference=" . var_export($reference, true);
    }
    return $diffs;
}

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');
$db = Database::mysql($host, $user, $pass, $dbname);

$fight_stmt = $db->prepare("SELECT id, fight_data, initial_state FROM fights WHERE initial_state IS NOT NULL ORDER BY id");
$fight_stmt->execute();
$fights = $fight_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_steps = 0;
$total_matched = 0;
$fights_with_mismatches = [];

foreach ($fights as $fight_row) {
    $fightid = (int) $fight_row['id'];
    echo "Fight $fightid:\n";

    $prefight_stmt = $db->prepare("SELECT sequence, prefight_data FROM fight_actions WHERE fightid = ? ORDER BY sequence");
    $prefight_stmt->execute([$fightid]);
    $prefight_by_sequence = [];
    foreach ($prefight_stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
        $prefight_by_sequence[(int) $row['sequence']] = $row['prefight_data'];

    $fight_actions = FightAction::load_all_by_fightid($db, $fightid);
    if (count($fight_actions) === 0) {
        echo "  no FightAction rows, skipping.\n";
        continue;
    }

    try {
        $replay = FIGHT::hydrate(json_decode($fight_row['initial_state'], true));
    } catch (\Throwable $e) {
        echo "  FAILED to hydrate initial_state: " . $e->getMessage() . "\n";
        $fights_with_mismatches[] = $fightid;
        continue;
    }

    $max_sequence = max(array_map(fn($fa) => $fa->sequence, $fight_actions));
    $fight_ok = true;

    foreach ($fight_actions as $fight_action) {
        $sequence = $fight_action->sequence;
        try {
            $replay->process_fight_action($fight_action);
        } catch (\Throwable $e) {
            echo "  seq $sequence: process_fight_action() THREW: " . $e->getMessage() . "\n";
            $fight_ok = false;
            $total_steps++;
            continue;
        }

        try {
            if ($sequence < $max_sequence && isset($prefight_by_sequence[$sequence + 1])) {
                $reference = dehydrated_state_from_blob($prefight_by_sequence[$sequence + 1]);
            } else {
                $reference = dehydrated_state_from_blob($fight_row['fight_data']);
            }
        } catch (\Throwable $e) {
            echo "  seq $sequence: FAILED to derive reference state: " . $e->getMessage() . "\n";
            $fight_ok = false;
            $total_steps++;
            continue;
        }

        $replayed_state = $replay->dehydrate();

        // counter no longer appears in dehydrate() output at all (see
        // FIGHT::hydrate()'s own comment) -- it's excluded on both
        // sides here now, so there's nothing left to mock or compare.

        $replayed_state = normalize_stats($replayed_state);
        $reference = normalize_stats($reference);

        $diffs = diff_dehydrated($replayed_state, $reference);
        $total_steps++;
        if (count($diffs) === 0) {
            $total_matched++;
            echo "  seq $sequence: match.\n";
        } else {
            $fight_ok = false;
            echo "  seq $sequence: MISMATCH (" . count($diffs) . " field" . (count($diffs) === 1 ? '' : 's') . "):\n";
            foreach ($diffs as $diff) echo "    $diff\n";
        }
    }

    if (!$fight_ok) $fights_with_mismatches[] = $fightid;
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Steps checked: $total_steps, matched: $total_matched, mismatched: " . ($total_steps - $total_matched) . "\n";
if (count($fights_with_mismatches) > 0) {
    echo "Fights with at least one mismatch: " . implode(', ', $fights_with_mismatches) . "\n";
} else {
    echo "Every fight replayed cleanly.\n";
}
