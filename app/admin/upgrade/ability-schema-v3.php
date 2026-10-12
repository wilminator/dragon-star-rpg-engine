<?php
/**
 * Second migration on abilities: impact_animation (varchar) + impact_data
 * (JSON: images/sounds/times) -> single `impact` JSON column matching the
 * new AnimationEffect shape ({animation, images, sounds, times}).
 *
 * Safe to re-run: each step checks whether it's already been applied
 * and skips itself if so.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/ability-schema-v3.php
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

// --- Step 1: add the new column, if not already present ---

if (!column_exists($db, 'abilities', 'impact')) {
    echo "Adding impact column...\n";
    $db->prepare("ALTER TABLE abilities ADD COLUMN impact JSON NULL")->execute();
} else {
    echo "impact column already exists, skipping.\n";
}

// --- Step 2: merge impact_animation + impact_data into impact, per row ---

if (column_exists($db, 'abilities', 'impact_animation')) {
    echo "Merging impact_animation + impact_data into impact...\n";

    $select = $db->prepare(
        "SELECT abilityid, impact_animation, impact_data FROM abilities"
    );
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $update = $db->prepare("UPDATE abilities SET impact = ? WHERE abilityid = ?");

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $old_data = is_null($row['impact_data'])
                ? []
                : json_decode($row['impact_data'], true);

            $effect = new AnimationEffect(
                animation: $row['impact_animation'] ?? 'static_individual_impact',
                images: $old_data['images'] ?? [],
                sounds: $old_data['sounds'] ?? [],
                times: $old_data['times'] ?? [100, 100],
            );

            $impact_json = json_encode($effect);
            $update->bind_param("si", $impact_json, $row['abilityid']);
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
    echo "Dropping old impact_animation/impact_data columns...\n";
    $db->prepare(
        "ALTER TABLE abilities
            DROP COLUMN impact_animation,
            DROP COLUMN impact_data"
    )->execute();
} else {
    echo "impact_animation column already gone, skipping merge.\n";
}

// --- Step 4: regenerate the cache file in the new format ---

require_once INCLUDE_GAME . 'ability.php';
echo "Regenerating abilities cache file...\n";
ABILITY::write_cache_file($db);

echo "Done.\n";
