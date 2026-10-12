<?php
/**
 * First migration on characters: collapses the original four-table schema
 * (characters + character_abilities + character_items + character_equip)
 * into a single, renamed heroes table with JSON columns:
 *   - bHP..bFocus (10 flat columns)   -> base (JSON, full stat names)
 *   - cHP..cFocus (10 flat columns)   -> current (JSON, full stat names)
 *   - character_abilities (characterid, abilityid rows)
 *                                     -> abilities (JSON array of ability IDs)
 *   - character_items (characterid, slot, itemid, qty rows)
 *                                     -> items (JSON, slot-keyed {item, qty})
 *   - character_equip (characterid, slot varchar, item rows)
 *                                     -> equipment (JSON, slot-NAME-keyed --
 *                                        e.g. {"lhand": 3, "rhand": null} --
 *                                        NOT a positional list like
 *                                        MONSTER's equipment column)
 *   - (new) teamid                   -> populated from team_characters
 *                                        (confirmed one-to-one: a character
 *                                        belongs to at most one team)
 *
 * Stat columns are read by their real name (bAccuracy -> "Accuracy", etc.),
 * not by position. This matters here in a way it didn't for MONSTER: the
 * OLD RUNTIME LOADING CODE (character_store.php's get_character()) built a
 * plain positional array in raw column order and zipped it against
 * $GLOBALS['character_stats']' canonical order via create_array() -- but
 * those two orders don't match (raw: HP,MP,Accuracy,Strength,Dodge,Block,
 * Speed,...; canonical: HP,MP,Speed,Accuracy,Strength,Dodge,Block,...), so
 * every existing character's Speed/Accuracy/Strength/Dodge/Block have been
 * silently shifted by one position this whole time. This migration reads
 * each column by its real, correct name, fixing that bug going forward --
 * a deliberate choice (Option B), not an oversight. Every existing
 * character's effective stats will shift at the moment this runs.
 *
 * No Effect enum validation step here, no state column at all -- HERO uses
 * hard deletes (HardDeleteTrait), not the retire-via-state pattern every
 * other entity uses. No cache file regeneration either -- HERO deliberately
 * doesn't implement FileCacheInterface, since hero data mutates constantly
 * during normal play, unlike admin-authored reference data.
 *
 * Safe to re-run: if the heroes table already exists, the whole thing is
 * skipped immediately. Column-existence checks make every other step
 * idempotent too. The three child tables are dropped with
 * DROP TABLE IF EXISTS, which is naturally idempotent on its own.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/hero-schema-v1.php
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

function table_exists(Database $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int) $row['cnt']) > 0;
}

// --- Step 0: if heroes already exists, the rename already happened -- done ---

if (table_exists($db, 'heroes')) {
    echo "heroes table already exists, migration already complete. Skipping.\n";
    exit(0);
}

// Maps the old flat column name to the new JSON key, by real name -- not
// position. See the file-level comment above for why this matters here.
const BASE_COLUMN_TO_KEY = [
    'bHP' => 'HP', 'bMP' => 'MP', 'bAccuracy' => 'Accuracy', 'bStrength' => 'Strength',
    'bDodge' => 'Dodge', 'bBlock' => 'Block', 'bSpeed' => 'Speed', 'bPower' => 'Power',
    'bResistance' => 'Resistance', 'bFocus' => 'Focus',
];
const CURRENT_COLUMN_TO_KEY = [
    'cHP' => 'HP', 'cMP' => 'MP', 'cAccuracy' => 'Accuracy', 'cStrength' => 'Strength',
    'cDodge' => 'Dodge', 'cBlock' => 'Block', 'cSpeed' => 'Speed', 'cPower' => 'Power',
    'cResistance' => 'Resistance', 'cFocus' => 'Focus',
];

// --- Step 1: add all new columns, if not already present ---

$new_columns = [
    'base' => "JSON NULL",
    'current' => "JSON NULL",
    'abilities' => "JSON NULL",
    'items' => "JSON NULL",
    'equipment' => "JSON NULL",
    'teamid' => "INT(11) UNSIGNED NOT NULL DEFAULT '0'",
];
foreach ($new_columns as $col => $def) {
    if (!column_exists($db, 'characters', $col)) {
        echo "Adding $col column...\n";
        $db->prepare("ALTER TABLE characters ADD COLUMN `$col` $def")->execute();
    } else {
        echo "$col column already exists, skipping.\n";
    }
}

// --- Step 2: migrate data, per row, pulling from the three child tables
//             plus team_characters for teamid ---

if (column_exists($db, 'characters', 'bHP')) {
    echo "Migrating character data to the new schema...\n";

    $select = $db->prepare("SELECT * FROM characters");
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $ability_select = $db->prepare("SELECT abilityid FROM character_abilities WHERE characterid = ?");
    $item_select = $db->prepare("SELECT slot, itemid, qty FROM character_items WHERE characterid = ?");
    $equip_select = $db->prepare("SELECT slot, item FROM character_equip WHERE characterid = ?");
    $team_select = $db->prepare("SELECT teamid FROM team_characters WHERE characterid = ?");
    $update = $db->prepare(
        "UPDATE characters SET base = ?, current = ?, abilities = ?, items = ?, equipment = ?, teamid = ? WHERE characterid = ?"
    );

    $orphaned = [];

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $characterid = $row['characterid'];

            // base / current
            $base = [];
            foreach (BASE_COLUMN_TO_KEY as $col => $key)
                $base[$key] = (int) $row[$col];
            $base_json = json_encode($base);

            $current = [];
            foreach (CURRENT_COLUMN_TO_KEY as $col => $key)
                $current[$key] = (int) $row[$col];
            $current_json = json_encode($current);

            // abilities
            $ability_select->bind_param("i", $characterid);
            $ability_select->execute();
            $abilities = array_map(
                fn($r) => (int) $r['abilityid'],
                $ability_select->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $abilities_json = json_encode($abilities);

            // items -- slot-keyed, matching character_items' own shape and
            // CHARACTER::$inventory's existing {item, qty} convention
            $item_select->bind_param("i", $characterid);
            $item_select->execute();
            $items = [];
            foreach ($item_select->get_result()->fetch_all(MYSQLI_ASSOC) as $item_row) {
                $items[(int) $item_row['slot']] = [
                    'item' => (int) $item_row['itemid'],
                    'qty' => (int) $item_row['qty'],
                ];
            }
            $items_json = json_encode($items);

            // equipment -- slot-NAME-keyed (e.g. "lhand" => 3), matching
            // character_equip's own shape and CHARACTER::$equipment's
            // existing convention. Not a positional list like MONSTER's.
            $equip_select->bind_param("i", $characterid);
            $equip_select->execute();
            $equipment = [];
            foreach ($equip_select->get_result()->fetch_all(MYSQLI_ASSOC) as $equip_row) {
                $equipment[$equip_row['slot']] = (int) $equip_row['item'];
            }
            $equipment_json = json_encode($equipment);

            // teamid -- confirmed one-to-one; a missing row means an
            // orphaned character, logged below rather than failing outright
            $team_select->bind_param("i", $characterid);
            $team_select->execute();
            $team_row = $team_select->get_result()->fetch_assoc();
            if ($team_row === null) {
                $teamid = 0;
                $orphaned[] = $characterid;
            } else {
                $teamid = (int) $team_row['teamid'];
            }

            $update->bind_param(
                "sssssii",
                $base_json, $current_json, $abilities_json, $items_json, $equipment_json, $teamid, $characterid
            );
            $update->execute();
        }
        $db->commit();
        echo "Migrated " . count($rows) . " row(s).\n";
        if (count($orphaned) > 0) {
            echo "WARNING: " . count($orphaned) . " character(s) had no team_characters row and were defaulted to teamid=0: "
                . implode(', ', $orphaned) . "\n";
        }
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Migration failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    // --- Step 3: drop the old flat stat columns and the three child tables ---
    echo "Dropping old stat columns...\n";
    $old_columns = array_merge(array_keys(BASE_COLUMN_TO_KEY), array_keys(CURRENT_COLUMN_TO_KEY));
    $drops = array_map(fn($col) => "DROP COLUMN `$col`", $old_columns);
    $db->prepare("ALTER TABLE characters " . implode(', ', $drops))->execute();

    echo "Dropping character_abilities, character_items, character_equip tables...\n";
    $db->prepare("DROP TABLE IF EXISTS character_abilities")->execute();
    $db->prepare("DROP TABLE IF EXISTS character_items")->execute();
    $db->prepare("DROP TABLE IF EXISTS character_equip")->execute();

    // --- Step 4: tighten the new columns to NOT NULL now that every row has real data ---
    echo "Tightening column constraints...\n";
    $db->prepare(
        "ALTER TABLE characters
            MODIFY base JSON NOT NULL,
            MODIFY current JSON NOT NULL,
            MODIFY abilities JSON NOT NULL default ('[]'),
            MODIFY items JSON NOT NULL default ('{}'),
            MODIFY equipment JSON NOT NULL default ('{}')"
    )->execute();

    // --- Step 5: rename the table itself, last, once everything else is settled ---
    echo "Renaming characters table to heroes...\n";
    $db->prepare("RENAME TABLE characters TO heroes")->execute();
} else {
    echo "bHP column already gone, skipping migration.\n";
}

echo "Done.\n";
