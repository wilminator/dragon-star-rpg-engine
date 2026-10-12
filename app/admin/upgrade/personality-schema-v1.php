<?php
/**
 * First migration on personalities: collapses the original flat-column
 * schema into the new JSON-based PERSONALITY shape:
 *   - {field}_animation/images/sounds/times (11 field groups, 44 columns)
 *       -> {field} (AnimationEffect JSON), one column per field
 *   - overworld_stand_up/down/left/right,
 *     overworld_move_up/down/left/right (8 columns)
 *       -> overworld (JSON) -- NOT migrated from old data. The old columns
 *          only ever held a single sprite-name string each, with no
 *          equivalent of the new per-direction images/times structure to
 *          migrate into. Every row gets the schema default (empty
 *          SpriteAnimation for all 8 stand/move x direction leaves); the
 *          real data gets built once the overworld feature itself is
 *          implemented, not by this script.
 *   - (new) state -> defaults to ObjectState::ACTIVE for existing rows
 *
 * No Effect enum validation step here (unlike the ABILITY/ITEM migrations) --
 * PERSONALITY has no effect field at all.
 *
 * Safe to re-run: each step checks whether it's already been applied and
 * skips itself if so.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/personality-schema-v1.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'animation_effect.php';

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');

$db = Database::mysql($host, $user, $pass, $dbname);

function column_exists(Database $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int) $row['cnt']) > 0;
}

const ANIMATION_FIELDS = [
    'base', 'equip', 'flee', 'hit', 'die',
    'attack_close', 'attack_throw', 'attack_shoot',
    'skill', 'spell', 'item',
];

const OVERWORLD_DEFAULT_JSON = '{"stand":{"up":{"images":[],"times":[]},"down":{"images":[],"times":[]},"left":{"images":[],"times":[]},"right":{"images":[],"times":[]}},"move":{"up":{"images":[],"times":[]},"down":{"images":[],"times":[]},"left":{"images":[],"times":[]},"right":{"images":[],"times":[]}}}';

// --- Step 1: add all new columns, if not already present ---

$new_columns = [];
foreach (ANIMATION_FIELDS as $field) {
    $new_columns[$field] = "JSON NULL";
}
$new_columns['overworld'] = "JSON NOT NULL default ('" . OVERWORLD_DEFAULT_JSON . "')";
$new_columns['state'] = "TINYINT(4) UNSIGNED NOT NULL DEFAULT '0'";

foreach ($new_columns as $col => $def) {
    if (!column_exists($db, 'personalities', $col)) {
        echo "Adding $col column...\n";
        $db->prepare("ALTER TABLE personalities ADD COLUMN `$col` $def")->execute();
    } else {
        echo "$col column already exists, skipping.\n";
    }
}

// --- Step 2: migrate the 11 animation-effect field groups, per row ---

if (column_exists($db, 'personalities', 'base_animation')) {
    echo "Migrating personality animation data to the new schema...\n";

    $select = $db->prepare("SELECT * FROM personalities");
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $set_clause = implode(', ', array_map(fn($f) => "`$f` = ?", ANIMATION_FIELDS));
    $update = $db->prepare("UPDATE personalities SET $set_clause WHERE personalityid = ?");
    $bind_types = str_repeat('s', count(ANIMATION_FIELDS)) . 'i';

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $encoded = [];
            foreach (ANIMATION_FIELDS as $field) {
                $effect = new AnimationEffect(
                    animation: $row["{$field}_animation"] ?? 'static_individual_impact',
                    images: is_null($row["{$field}_images"]) || $row["{$field}_images"] === '' ? [] : explode(',', $row["{$field}_images"]),
                    sounds: is_null($row["{$field}_sounds"]) || $row["{$field}_sounds"] === '' ? [] : explode(',', $row["{$field}_sounds"]),
                    times: is_null($row["{$field}_times"]) || $row["{$field}_times"] === '' ? [100, 100] : explode(',', $row["{$field}_times"]),
                );
                $encoded[] = json_encode($effect);
            }
            $bind_args = array_merge($encoded, [$row['personalityid']]);
            $update->bind_param($bind_types, ...$bind_args);
            $update->execute();
        }
        $db->commit();
        echo "Migrated " . count($rows) . " row(s).\n";
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Migration failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    // --- Step 3: drop all the old columns now that everything's copied over ---
    echo "Dropping old columns...\n";
    $drops = [];
    foreach (ANIMATION_FIELDS as $field) {
        $drops[] = "DROP COLUMN `{$field}_animation`";
        $drops[] = "DROP COLUMN `{$field}_images`";
        $drops[] = "DROP COLUMN `{$field}_sounds`";
        $drops[] = "DROP COLUMN `{$field}_times`";
    }
    foreach (['stand', 'move'] as $mode) {
        foreach (['up', 'down', 'left', 'right'] as $direction) {
            $drops[] = "DROP COLUMN `overworld_{$mode}_{$direction}`";
        }
    }
    $db->prepare("ALTER TABLE personalities " . implode(', ', $drops))->execute();

    // --- Step 4: tighten the animation-effect columns to NOT NULL now that every row has real data ---
    echo "Tightening column constraints...\n";
    $modifies = [];
    foreach (ANIMATION_FIELDS as $field) {
        $modifies[] = "MODIFY `$field` JSON NOT NULL default ('{\"animation\":\"static_individual_impact\",\"images\":[],\"sounds\":[],\"times\":[100,100]}')";
    }
    $db->prepare("ALTER TABLE personalities " . implode(', ', $modifies))->execute();
} else {
    echo "base_animation column already gone, skipping migration.\n";
}

// --- Step 5: regenerate the cache file in the new format ---

require_once INCLUDE_GAME . 'sprite_animation.php';
require_once INCLUDE_GAME . 'personality.php';
echo "Regenerating personalities cache file...\n";
PERSONALITY::write_cache_file($db);

echo "Done.\n";
