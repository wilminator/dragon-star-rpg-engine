<?php
/**
 * Fourth migration on abilities: effect/targets/base/added/attribute
 * (five separate scalar columns) -> single `effect_spec` JSON column
 * matching the new EffectSpec shape ({effect, targets, base, added, attribute}).
 *
 * Validates each row's `effect` value against the Effect enum before
 * writing -- a value outside Effect's known range would otherwise sit
 * silently in the new JSON column until something calls Effect::from()
 * on it later and fatals. Caught here instead, at migration time, where
 * it's actually actionable.
 *
 * Safe to re-run: each step checks whether it's already been applied
 * and skips itself if so.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/ability-schema-v4.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'effect.php';

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

// --- Step 1: add the new column, if not already present ---

if (!column_exists($db, 'abilities', 'effect_spec')) {
    echo "Adding effect_spec column...\n";
    $db->prepare("ALTER TABLE abilities ADD COLUMN effect_spec JSON NULL")->execute();
} else {
    echo "effect_spec column already exists, skipping.\n";
}

// --- Step 2: merge effect/targets/base/added/attribute into effect_spec, per row ---

if (column_exists($db, 'abilities', 'effect')) {
    echo "Merging effect/targets/base/added/attribute into effect_spec...\n";

    $select = $db->prepare(
        "SELECT abilityid, name, effect, targets, base, added, attribute FROM abilities"
    );
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $update = $db->prepare("UPDATE abilities SET effect_spec = ? WHERE abilityid = ?");

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $effect_value = (int) $row['effect'];
            if (Effect::tryFrom($effect_value) === null) {
                throw new Exception(
                    "Ability #{$row['abilityid']} ({$row['name']}) has effect=$effect_value, "
                    . "which isn't a valid Effect case. Fix this row's data before re-running."
                );
            }

            $effect_spec_json = json_encode([
                'effect' => $effect_value,
                'targets' => (int) $row['targets'],
                'base' => (int) $row['base'],
                'added' => (int) $row['added'],
                'attribute' => (int) $row['attribute'],
            ]);

            $update->bind_param("si", $effect_spec_json, $row['abilityid']);
            $update->execute();
        }
        $db->commit();
        echo "Merged " . count($rows) . " row(s).\n";
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Merge failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    // --- Step 3: drop the old columns now that everything's copied over ---
    echo "Dropping old effect/targets/base/added/attribute columns...\n";
    $db->prepare(
        "ALTER TABLE abilities
            DROP COLUMN effect,
            DROP COLUMN targets,
            DROP COLUMN base,
            DROP COLUMN added,
            DROP COLUMN attribute"
    )->execute();
} else {
    echo "effect column already gone, skipping merge.\n";
}

// --- Step 4: regenerate the cache file in the new format ---

require_once INCLUDE_GAME . 'ability.php';
echo "Regenerating abilities cache file...\n";
ABILITY::write_cache_file($db);

echo "Done.\n";