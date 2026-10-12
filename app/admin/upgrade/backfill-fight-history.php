<?php
/**
 * One-time backfill for fights that predate initial_state/action_json/
 * final_state (fights created before FightAction and FIGHT::save()'s
 * initial_state write existed). Derives all three from data already on
 * hand -- nothing new is computed, only reformatted/relocated:
 *
 *   - fights.initial_state <- fight_actions.prefight_data at sequence=1
 *     (the fight's state captured before any turn was applied -- exactly
 *     what initial_state means for a freshly-created fight).
 *
 *   - fight_actions.final_state at sequence N <- prefight_data at
 *     sequence N+1 for the same fightid (prefight_data at N+1 is "the
 *     state right before turn N+1", which is identically "the state
 *     right after turn N").
 *
 *   - fight_actions.final_state at the highest sequence for a fightid
 *     (no N+1 row exists) <- fights.fight_data, the fight's current,
 *     live state -- which is exactly the state after that last turn.
 *
 *   - fight_actions.action_json <- action_data, reformatted from PHP
 *     serialize() to JSON. Plain arrays, no class-shape concerns.
 *
 * Legacy-data handling: prefight_data/fight_data are PHP serialize()
 * snapshots of a full FIGHT object from whenever they were created, and
 * some fights predate parts of FIGHT/PARTY/CHARACTER's current shape:
 *   - FIGHT::$id used to be untyped/string; it's now ?int, so
 *     unserialize() rejects old blobs outright unless patched first.
 *   - Properties added to FIGHT/PARTY/CHARACTER since (state as a
 *     FightStatus enum, PARTY::$team_names, CHARACTER::$need) are
 *     simply absent from older blobs -- unserialize() only restores
 *     properties actually present in the string and never runs the
 *     constructor, so these are left genuinely uninitialized rather
 *     than defaulted.
 * Both are handled generically below (regex patch for $id; a reflection
 * pass backfilling any uninitialized typed property with a type-legal
 * default) rather than special-cased per fight, since there's no
 * guarantee these are the only two gaps across every historical blob.
 * None of this touches the live FIGHT/PARTY/CHARACTER classes -- it's
 * scoped entirely to reading old data in this one script.
 *
 * Safe to re-run: every UPDATE recomputes the same value from the same
 * source columns each time.
 *
 * Run from the CLI inside the container:
 *   docker compose exec web php admin/upgrade/backfill-fight-history.php
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
            // Enum-typed properties predating the enum's own
            // introduction (e.g. FIGHT::$state, once an untyped/string
            // 'active' column) -- the specific case doesn't matter for
            // dehydrate() purposes (state isn't in
            // DEHYDRATE_PROPERTIES), this just needs to be a legal
            // value so the property is initialized at all.
            enum_exists((string) $type_name) => ($type_name::cases())[0],
            default => null,
        };
        $prop->setValue($obj, $default);
    }
    // Second pass, after every property on $obj itself is guaranteed
    // initialized: recurse into nested objects/arrays-of-objects.
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

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');
$db = Database::mysql($host, $user, $pass, $dbname);

$fight_stmt = $db->prepare("SELECT id, fight_data FROM fights WHERE initial_state IS NULL");
$fight_stmt->execute();
$fights = $fight_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($fights as $fight_row) {
    $fightid = (int) $fight_row['id'];
    echo "Fight $fightid:\n";

    $action_stmt = $db->prepare("SELECT sequence, prefight_data, action_data FROM fight_actions WHERE fightid = ? ORDER BY sequence");
    $action_stmt->execute([$fightid]);
    $actions = $action_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($actions) === 0) {
        echo "  no fight_actions rows, skipping.\n";
        continue;
    }

    // Indexed by sequence number, not array position -- next_row
    // lookups below match the stated rule literally
    // (next.sequence = this.sequence + 1) rather than assuming no
    // gaps in stored sequence numbers.
    $by_sequence = [];
    foreach ($actions as $row) $by_sequence[(int) $row['sequence']] = $row;

    $sequences = array_map(fn($r) => (int) $r['sequence'], $actions);
    $max_sequence = max($sequences);
    if (!in_array(1, $sequences, true)) {
        echo "  WARNING: no sequence=1 row -- skipping initial_state.\n";
    } else {
        try {
            $initial_state = dehydrated_state_from_blob($by_sequence[1]['prefight_data']);
            $upd = $db->prepare("UPDATE fights SET initial_state = ? WHERE id = ?");
            $upd->execute([json_encode($initial_state), $fightid]);
            echo "  initial_state backfilled.\n";
        } catch (\Throwable $e) {
            echo "  initial_state FAILED: " . $e->getMessage() . "\n";
        }
    }

    foreach ($actions as $row) {
        $sequence = (int) $row['sequence'];

        try {
            $decoded_actions = unserialize($row['action_data']);
            if ($decoded_actions === false) throw new Exception("unserialize() of action_data failed.");
            $action_json = json_encode($decoded_actions);
        } catch (\Throwable $e) {
            echo "  seq $sequence action_json FAILED: " . $e->getMessage() . "\n";
            $action_json = null;
        }

        try {
            $next_row = $by_sequence[$sequence + 1] ?? null;
            if ($next_row !== null) {
                $final_state = dehydrated_state_from_blob($next_row['prefight_data']);
            } else {
                if ($sequence !== $max_sequence) {
                    echo "  seq $sequence: WARNING, no seq " . ($sequence + 1) . " row and this isn't the max sequence ($max_sequence) -- a gap in stored sequences. Falling back to fight_data anyway.\n";
                }
                $final_state = dehydrated_state_from_blob($fight_row['fight_data']);
            }
            $final_state_json = json_encode($final_state);
        } catch (\Throwable $e) {
            echo "  seq $sequence final_state FAILED: " . $e->getMessage() . "\n";
            $final_state_json = null;
        }

        $upd = $db->prepare("UPDATE fight_actions SET action_json = ?, final_state = ? WHERE fightid = ? AND sequence = ?");
        $upd->execute([$action_json, $final_state_json, $fightid, $sequence]);
        echo "  seq $sequence: action_json=" . ($action_json !== null ? "ok" : "FAILED")
            . ", final_state=" . ($final_state_json !== null ? "ok" : "FAILED") . "\n";
    }
}

echo "Done.\n";
