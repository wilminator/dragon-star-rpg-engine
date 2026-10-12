<?php
/**
 * Re-derives initial_state/final_state/action_json for every fight,
 * unconditionally -- not just ones missing them. A modified version of
 * backfill-fight-history.php (see that file for the original,
 * fill-the-gaps version this is based on): same derivation, same
 * legacy-blob handling, but re-running it against fights that already
 * have these columns populated, to bring already-derived data in line
 * with a dehydrate() shape that's changed since they were last derived
 * (most recently: FIGHT::$counter no longer appears in dehydrate()
 * output at all, having moved out of DEHYDRATE_PROPERTIES).
 *
 *   - fights.initial_state <- fight_actions.prefight_data at sequence=1
 *
 *   - fight_actions.final_state at sequence N <- prefight_data at
 *     sequence N+1 for the same fightid, or fights.fight_data at the
 *     highest sequence
 *
 *   - fight_actions.action_json <- action_data, reformatted from PHP
 *     serialize() to JSON
 *
 * All three re-derived from the legacy prefight_data/fight_data/
 * action_data columns, not from the JSON columns being overwritten --
 * those legacy columns remain the source of truth for this script
 * specifically because they're staying in place until FIGHT_STORE's
 * migration is finished; dropping them is explicitly deferred until
 * then, so this script keeps working for as long as they're still
 * there to re-derive from. xpdebt_json is untouched: nothing here ever
 * wrote it in the first place (it's populated only by the live
 * FIGHT_STORE::update_fight() path, via FIGHT::dehydrate_xp_debts(),
 * not by dehydrate() itself), so there's no already-stored data of
 * that shape to resync.
 *
 * Legacy-data handling identical to backfill-fight-history.php -- see
 * that file's own comments for the $id-typing and uninitialized-
 * property reasoning, reused verbatim here.
 *
 * Safe to re-run, including after further dehydrate()-shape changes:
 * every UPDATE recomputes its value from the same legacy source
 * columns each time, and nothing here depends on what the JSON columns
 * currently hold.
 *
 * Run from the CLI inside the container:
 *   docker compose exec web php admin/upgrade/resync-dehydrated-fight-history.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_action.php';

function patch_legacy_fight_id(string $blob): string {
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

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');
$db = Database::mysql($host, $user, $pass, $dbname);

// Unconditional -- the one deliberate difference from
// backfill-fight-history.php's own "WHERE initial_state IS NULL".
$fight_stmt = $db->prepare("SELECT id, fight_data FROM fights");
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
            echo "  initial_state resynced.\n";
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
