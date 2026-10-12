<?php
/**
 * First migration on monsters: collapses the original four-table schema
 * (monsters + monster_abilities + monster_items + monster_equip) into a
 * single monsters table with JSON columns:
 *   - HP, MP, accuracy, strength, dodge, block, speed, power, resistance,
 *     focus (10 flat columns)         -> stats (JSON, full stat names)
 *   - monster_abilities (monsterid, abilityid rows)
 *                                      -> abilities (JSON array of ability IDs)
 *   - monster_items (monsterid, slot, itemid, qty rows)
 *                                      -> items (JSON, slot-keyed {item, qty})
 *   - monster_equip (monsterid, slot, side rows)
 *                                      -> equipment (JSON array of {slot, side})
 *   - (new) state                     -> defaults to ObjectState::ACTIVE
 *
 * No Effect enum validation step here -- MONSTER has no effect field at all,
 * same as the PERSONALITY migration.
 *
 * Safe to re-run: each step checks whether it's already been applied and
 * skips itself if so. The three child tables are dropped with
 * DROP TABLE IF EXISTS, which is naturally idempotent on its own.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/monster-schema-v1.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';

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

// Maps the old flat column name to the new JSON key -- these were already
// spelled out (if lowercase), so unlike ITEM's inc_ACC/inc_STR abbreviations,
// this is a straightforward capitalize-and-copy, not a position-based
// reconstruction against $character_stats' order.
const STAT_COLUMN_TO_KEY = [
    'HP' => 'HP', 'MP' => 'MP', 'speed' => 'Speed', 'accuracy' => 'Accuracy',
    'strength' => 'Strength', 'dodge' => 'Dodge', 'block' => 'Block',
    'power' => 'Power', 'resistance' => 'Resistance', 'focus' => 'Focus',
];

// --- Step 1: add all new columns, if not already present ---

$new_columns = [
    'stats' => "JSON NULL",
    'abilities' => "JSON NULL",
    'items' => "JSON NULL",
    'equipment' => "JSON NULL",
    'state' => "TINYINT(4) UNSIGNED NOT NULL DEFAULT '0'",
];
foreach ($new_columns as $col => $def) {
    if (!column_exists($db, 'monsters', $col)) {
        echo "Adding $col column...\n";
        $db->prepare("ALTER TABLE monsters ADD COLUMN `$col` $def")->execute();
    } else {
        echo "$col column already exists, skipping.\n";
    }
}

// --- Step 2: migrate data, per row, pulling from the three child tables ---

if (column_exists($db, 'monsters', 'HP')) {
    echo "Migrating monster data to the new schema...\n";

    $select = $db->prepare("SELECT * FROM monsters");
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $ability_select = $db->prepare("SELECT abilityid FROM monster_abilities WHERE monsterid = ?");
    $item_select = $db->prepare("SELECT slot, itemid, qty FROM monster_items WHERE monsterid = ?");
    $equip_select = $db->prepare("SELECT slot, side FROM monster_equip WHERE monsterid = ?");
    $update = $db->prepare(
        "UPDATE monsters SET stats = ?, abilities = ?, items = ?, equipment = ? WHERE monsterid = ?"
    );

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $monsterid = $row['monsterid'];

            // stats
            $stats = [];
            foreach (STAT_COLUMN_TO_KEY as $col => $key) {
                $stats[$key] = (int) $row[$col];
            }
            $stats_json = json_encode($stats);

            // abilities
            $ability_select->bind_param("i", $monsterid);
            $ability_select->execute();
            $abilities = array_map(
                fn($r) => (int) $r['abilityid'],
                $ability_select->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $abilities_json = json_encode($abilities);

            // items -- slot-keyed, matching MONSTER::make_monster()'s existing
            // ['item' => ..., 'qty' => ...] shape per slot
            $item_select->bind_param("i", $monsterid);
            $item_select->execute();
            $items = [];
            foreach ($item_select->get_result()->fetch_all(MYSQLI_ASSOC) as $item_row) {
                $items[(int) $item_row['slot']] = [
                    'item' => (int) $item_row['itemid'],
                    'qty' => (int) $item_row['qty'],
                ];
            }
            $items_json = json_encode($items);

            // equipment -- a plain positional list of {slot, side} pairs,
            // matching MONSTER::make_monster()'s existing consumption pattern
            $equip_select->bind_param("i", $monsterid);
            $equip_select->execute();
            $equipment = array_map(
                fn($r) => ['slot' => (int) $r['slot'], 'side' => (int) $r['side']],
                $equip_select->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $equipment_json = json_encode($equipment);

            $update->bind_param("ssssi", $stats_json, $abilities_json, $items_json, $equipment_json, $monsterid);
            $update->execute();
        }
        $db->commit();
        echo "Migrated " . count($rows) . " row(s).\n";
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Migration failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    // --- Step 3: drop the old flat stat columns and the three child tables ---
    echo "Dropping old stat columns...\n";
    $drops = array_map(fn($col) => "DROP COLUMN `$col`", array_keys(STAT_COLUMN_TO_KEY));
    $db->prepare("ALTER TABLE monsters " . implode(', ', $drops))->execute();

    echo "Dropping monster_abilities, monster_items, monster_equip tables...\n";
    $db->prepare("DROP TABLE IF EXISTS monster_abilities")->execute();
    $db->prepare("DROP TABLE IF EXISTS monster_items")->execute();
    $db->prepare("DROP TABLE IF EXISTS monster_equip")->execute();

    // --- Step 4: tighten the new columns to NOT NULL now that every row has real data ---
    echo "Tightening column constraints...\n";
    $db->prepare(
        "ALTER TABLE monsters
            MODIFY stats JSON NOT NULL default ('{\"HP\":0,\"MP\":0,\"Speed\":0,\"Accuracy\":0,\"Strength\":0,\"Dodge\":0,\"Block\":0,\"Power\":0,\"Resistance\":0,\"Focus\":0}'),
            MODIFY abilities JSON NOT NULL default ('[]'),
            MODIFY items JSON NOT NULL default ('[]'),
            MODIFY equipment JSON NOT NULL default ('[]')"
    )->execute();
} else {
    echo "HP column already gone, skipping migration.\n";
}

// --- Step 5: regenerate the cache file in the new format ---

require_once INCLUDE_GAME . 'monster.php';
echo "Regenerating monsters cache file...\n";
MONSTER::write_cache_file($db);

echo "Done.\n";