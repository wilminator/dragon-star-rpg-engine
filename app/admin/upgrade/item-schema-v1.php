<?php
/**
 * First migration on items: collapses the original flat-column schema into
 * the new JSON-based ITEM shape:
 *   - effect, use_targets, base, added, attribute       -> use_effect_spec (EffectSpec JSON)
 *   - inc_HP..inc_FOC (10 columns)                       -> stat_increase (JSON, full stat names)
 *   - percinc_HP..percinc_FOC (10 columns)                -> stat_percent_increase (JSON, full stat names)
 *   - targets                                             -> attack_targets (rename only)
 *   - equip_type (comma-separated varchar)                -> equip_type (JSON array, empty string -> NULL)
 *   - one_use (enum('true','false'))                      -> one_use (tinyint(1))
 *   - fight_impact_animation/images/sounds/times          -> fight_animation_effect (AnimationEffect JSON)
 *   - use_impact_animation/images/sounds/times            -> use_animation_effect (AnimationEffect JSON)
 *   - (new) state                                         -> defaults to ObjectState::ACTIVE for existing rows
 *
 * Validates each row's `effect` value against the Effect enum before writing --
 * a value outside Effect's known range would otherwise sit silently in the new
 * JSON column until something calls Effect::from() on it later and fatals.
 *
 * Safe to re-run: each step checks whether it's already been applied and
 * skips itself if so.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/item-schema-v1.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'effect.php';
require_once INCLUDE_GAME . 'effect_spec.php';
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

// Same stat order verified against $GLOBALS['character_stats'] and the
// original get_item()'s array-building order when ITEM's design was worked out.
const STAT_COLUMN_TO_KEY = [
    'HP' => 'HP', 'MP' => 'MP', 'SPD' => 'Speed', 'ACC' => 'Accuracy',
    'STR' => 'Strength', 'DOD' => 'Dodge', 'BLO' => 'Block',
    'POW' => 'Power', 'RES' => 'Resistance', 'FOC' => 'Focus',
];

// --- Step 1: add all new columns, if not already present ---

$new_columns = [
    'use_effect_spec' => "JSON NULL",
    'stat_increase' => "JSON NULL",
    'stat_percent_increase' => "JSON NULL",
    'attack_targets' => "TINYINT(4) NULL",
    'fight_animation_effect' => "JSON NULL",
    'use_animation_effect' => "JSON NULL",
    'state' => "TINYINT(4) UNSIGNED NOT NULL DEFAULT '0'",
    // one_use and equip_type already exist with the WRONG type (enum, varchar) --
    // can't MODIFY those in place (enum-to-int conversion uses enum position,
    // not the boolean value we want), so migrate via temp columns instead.
    'one_use_new' => "TINYINT(1) NULL",
    'equip_type_new' => "JSON NULL",
];
foreach ($new_columns as $col => $def) {
    if (!column_exists($db, 'items', $col)) {
        echo "Adding $col column...\n";
        $db->prepare("ALTER TABLE items ADD COLUMN `$col` $def")->execute();
    } else {
        echo "$col column already exists, skipping.\n";
    }
}

// --- Step 2: migrate data, per row ---

if (column_exists($db, 'items', 'effect')) {
    echo "Migrating item data to the new schema...\n";

    $select = $db->prepare("SELECT * FROM items");
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $update = $db->prepare(
        "UPDATE items SET
            use_effect_spec = ?, stat_increase = ?, stat_percent_increase = ?,
            attack_targets = ?, equip_type_new = ?, one_use_new = ?,
            fight_animation_effect = ?, use_animation_effect = ?
         WHERE itemid = ?"
    );

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $effect_value = (int) $row['effect'];
            if (Effect::tryFrom($effect_value) === null) {
                throw new Exception(
                    "Item #{$row['itemid']} ({$row['name']}) has effect=$effect_value, "
                    . "which isn't a valid Effect case. Fix this row's data before re-running."
                );
            }

            // use_effect_spec
            $use_effect_spec = new EffectSpec(
                effect: Effect::from($effect_value),
                targets: (int) $row['use_targets'],
                base: (int) $row['base'],
                added: (int) $row['added'],
                attribute: (int) $row['attribute'],
            );
            $use_effect_spec_json = json_encode($use_effect_spec);

            // stat_increase / stat_percent_increase
            $stat_increase = [];
            $stat_percent_increase = [];
            foreach (STAT_COLUMN_TO_KEY as $col_suffix => $stat_name) {
                $stat_increase[$stat_name] = (int) $row["inc_$col_suffix"];
                $stat_percent_increase[$stat_name] = (int) $row["percinc_$col_suffix"];
            }
            $stat_increase_json = json_encode($stat_increase);
            $stat_percent_increase_json = json_encode($stat_percent_increase);

            // attack_targets (straight rename)
            $attack_targets = (int) $row['targets'];

            // equip_type: '' -> NULL, else comma-split -> JSON array
            $equip_type_json = $row['equip_type'] === ''
                ? null
                : json_encode(explode(',', $row['equip_type']));

            // one_use: enum('true','false') -> tinyint(1)
            $one_use = $row['one_use'] === 'true' ? 1 : 0;

            // fight_animation_effect / use_animation_effect
            $fight_effect = new AnimationEffect(
                animation: $row['fight_impact_animation'] ?? 'static_individual_impact',
                images: is_null($row['fight_impact_images']) || $row['fight_impact_images'] === '' ? [] : explode(',', $row['fight_impact_images']),
                sounds: is_null($row['fight_impact_sounds']) || $row['fight_impact_sounds'] === '' ? [] : explode(',', $row['fight_impact_sounds']),
                times: is_null($row['fight_impact_times']) || $row['fight_impact_times'] === '' ? [100, 100] : explode(',', $row['fight_impact_times']),
            );
            $use_effect = new AnimationEffect(
                animation: $row['use_impact_animation'] ?? 'static_individual_impact',
                images: is_null($row['use_impact_images']) || $row['use_impact_images'] === '' ? [] : explode(',', $row['use_impact_images']),
                sounds: is_null($row['use_impact_sounds']) || $row['use_impact_sounds'] === '' ? [] : explode(',', $row['use_impact_sounds']),
                times: is_null($row['use_impact_times']) || $row['use_impact_times'] === '' ? [100, 100] : explode(',', $row['use_impact_times']),
            );
            $fight_animation_effect_json = json_encode($fight_effect);
            $use_animation_effect_json = json_encode($use_effect);

            $update->bind_param(
                "sssisissi",
                $use_effect_spec_json,
                $stat_increase_json,
                $stat_percent_increase_json,
                $attack_targets,
                $equip_type_json,
                $one_use,
                $fight_animation_effect_json,
                $use_animation_effect_json,
                $row['itemid']
            );
            $update->execute();
        }
        $db->commit();
        echo "Migrated " . count($rows) . " row(s).\n";
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Migration failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    // --- Step 3: drop the old columns now that everything's copied over,
    //     then rename the temp one_use_new/equip_type_new into place ---
    echo "Dropping old columns...\n";
    $db->prepare(
        "ALTER TABLE items
            DROP COLUMN effect, DROP COLUMN use_targets, DROP COLUMN base,
            DROP COLUMN added, DROP COLUMN attribute, DROP COLUMN targets,
            DROP COLUMN inc_HP, DROP COLUMN inc_MP, DROP COLUMN inc_ACC, DROP COLUMN inc_STR,
            DROP COLUMN inc_DOD, DROP COLUMN inc_BLO, DROP COLUMN inc_SPD, DROP COLUMN inc_POW,
            DROP COLUMN inc_RES, DROP COLUMN inc_FOC,
            DROP COLUMN percinc_HP, DROP COLUMN percinc_MP, DROP COLUMN percinc_ACC, DROP COLUMN percinc_STR,
            DROP COLUMN percinc_DOD, DROP COLUMN percinc_BLO, DROP COLUMN percinc_SPD, DROP COLUMN percinc_POW,
            DROP COLUMN percinc_RES, DROP COLUMN percinc_FOC,
            DROP COLUMN fight_impact_animation, DROP COLUMN fight_impact_images,
            DROP COLUMN fight_impact_sounds, DROP COLUMN fight_impact_times,
            DROP COLUMN use_impact_animation, DROP COLUMN use_impact_images,
            DROP COLUMN use_impact_sounds, DROP COLUMN use_impact_times,
            DROP COLUMN one_use, DROP COLUMN equip_type"
    )->execute();

    echo "Renaming temp columns into place...\n";
    $db->prepare(
        "ALTER TABLE items
            CHANGE one_use_new one_use TINYINT(1) NULL,
            CHANGE equip_type_new equip_type JSON NULL"
    )->execute();

    // --- Step 4: make the JSON columns NOT NULL now that every row has real data ---
    echo "Tightening column constraints...\n";
    $db->prepare(
        "ALTER TABLE items
            MODIFY use_effect_spec JSON NOT NULL default ('{\"effect\":0,\"targets\":0,\"base\":0,\"added\":0,\"attribute\":0}'),
            MODIFY stat_increase JSON NOT NULL default ('{\"HP\":0,\"MP\":0,\"Speed\":0,\"Accuracy\":0,\"Strength\":0,\"Dodge\":0,\"Block\":0,\"Power\":0,\"Resistance\":0,\"Focus\":0}'),
            MODIFY stat_percent_increase JSON NOT NULL default ('{\"HP\":0,\"MP\":0,\"Speed\":0,\"Accuracy\":0,\"Strength\":0,\"Dodge\":0,\"Block\":0,\"Power\":0,\"Resistance\":0,\"Focus\":0}'),
            MODIFY attack_targets TINYINT(4) NOT NULL default '0',
            MODIFY fight_animation_effect JSON NOT NULL default ('{\"animation\":\"static_individual_impact\",\"images\":[],\"sounds\":[],\"times\":[100,100]}'),
            MODIFY use_animation_effect JSON NOT NULL default ('{\"animation\":\"static_individual_impact\",\"images\":[],\"sounds\":[],\"times\":[100,100]}'),
            MODIFY one_use TINYINT(1) NOT NULL default '1'"
    )->execute();
    // equip_type stays nullable with no default -- NULL is itself the meaningful
    // "not equippable" value, not a placeholder waiting to be tightened.
} else {
    echo "effect column already gone, skipping migration.\n";
}

// --- Step 5: regenerate the cache file in the new format ---

require_once INCLUDE_GAME . 'item.php';
echo "Regenerating items cache file...\n";
ITEM::write_cache_file($db);

echo "Done.\n";
