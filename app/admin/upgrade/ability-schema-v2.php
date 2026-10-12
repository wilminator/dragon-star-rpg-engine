<?php
/**
 * One-time migration: abilities table -> new ABILITY schema.
 *   - impact_images / impact_sounds / impact_times  ->  single impact_data JSON column
 *   - adds `state` column (defaults every existing row to ACTIVE)
 *   - regenerates the abilities cache file in the new format
 *
 * Safe to re-run: each step checks whether it's already been applied
 * and skips itself if so.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/ability-schema-v2.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'dse';
$pass = getenv('DB_PASSWORD') ?: 'change-me';
$dbname = getenv('DB_DATABASE') ?: 'dse';

$db = Database::mysql($host, $user, $pass, $dbname);

// Guards against null AND empty-string (explode(',', '') would otherwise
// produce [''] instead of [] -- the bug present in the original code).
function split_csv(?string $value): array {
    if ($value === null || $value === '') {
        return [];
    }
    return explode(',', $value);
}

function column_exists(Database $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name COLLATE utf8mb3_bin = ?"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int) $row['cnt']) > 0;
}

// --- Step 1: add the new columns, if not already present ---

if (!column_exists($db, 'abilities', 'impact_data')) {
    echo "Adding impact_data column...\n";
    $db->prepare("ALTER TABLE abilities ADD COLUMN impact_data JSON NULL")->execute();
} else {
    echo "impact_data column already exists, skipping.\n";
}

if (!column_exists($db, 'abilities', 'state')) {
    echo "Adding state column (defaulting existing rows to ACTIVE)...\n";
    $db->prepare("ALTER TABLE abilities ADD COLUMN state TINYINT UNSIGNED NOT NULL DEFAULT 0")->execute();
} else {
    echo "state column already exists, skipping.\n";
}

if (!column_exists($db, 'abilities', 'mp_used')) {
    echo "Adding mp_used column (defaulting existing rows to 0)...\n";
    $db->prepare("ALTER TABLE abilities RENAME COLUMN MP_used TO mp_used")->execute();
} else {
    echo "mp_used column already exists, skipping.\n";
}

// --- Step 2: convert each row's old impact_* columns into impact_data JSON ---

if (column_exists($db, 'abilities', 'impact_images')) {
    echo "Converting impact_images/impact_sounds/impact_times to impact_data...\n";

    $select = $db->prepare(
        "SELECT abilityid, impact_images, impact_sounds, impact_times FROM abilities"
    );
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $update = $db->prepare("UPDATE abilities SET impact_data = ? WHERE abilityid = ?");

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $impact_data = json_encode([
                'images' => split_csv($row['impact_images']),
                'sounds' => split_csv($row['impact_sounds']),
                'times'  => split_csv($row['impact_times']),
            ]);
            $update->bind_param("si", $impact_data, $row['abilityid']);
            $update->execute();
        }
        $db->commit();
        echo "Converted " . count($rows) . " row(s).\n";
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Conversion failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    // --- Step 3: drop the old columns now that everything's copied over ---
    echo "Dropping old impact_images/impact_sounds/impact_times columns...\n";
    $db->prepare(
        "ALTER TABLE abilities
            DROP COLUMN impact_images,
            DROP COLUMN impact_sounds,
            DROP COLUMN impact_times"
    )->execute();
} else {
    echo "impact_images column already gone, skipping conversion.\n";
}

// --- Step 4: regenerate the cache file in the new format ---

require_once INCLUDE_GAME . 'ability.php';
echo "Regenerating abilities cache file...\n";
ABILITY::write_cache_file($db);

echo "Done.\n";
