<?php
/**
 * One-time migration: jobs table -> new JOB schema.
 *   - HP/MP/accuracy/strength/dodge/block/speed/power/resistance/focus
 *     -> single `upgrade` JSON column (capitalized keys, matching JOB::translate_and_build())
 *   - job_abilities table -> single `abilities` JSON column (list of {ability,level}
 *     pairs, ordered by level -- matches the old get_job() query's own ordering)
 *   - adds `state` column (defaults every existing row to ACTIVE)
 *   - regenerates the jobs cache file in the new format
 *
 * Safe to re-run: each step checks whether it's already been applied
 * and skips itself if so.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/job-schema-v2.php
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

// Old column name -> new JSON key. Capitalization matters: JOB expects
// keys like "Accuracy", but the old column is lowercase `accuracy`.
const STAT_COLUMN_MAP = [
    'HP' => 'HP',
    'MP' => 'MP',
    'accuracy'   => 'Accuracy',
    'strength'   => 'Strength',
    'dodge'      => 'Dodge',
    'block'      => 'Block',
    'speed'      => 'Speed',
    'power'      => 'Power',
    'resistance' => 'Resistance',
    'focus'      => 'Focus',
];

// --- Step 1: add the new columns, if not already present ---

if (!column_exists($db, 'jobs', 'upgrade')) {
    echo "Adding upgrade column...\n";
    $db->prepare("ALTER TABLE jobs ADD COLUMN upgrade JSON NULL")->execute();
} else {
    echo "upgrade column already exists, skipping.\n";
}

if (!column_exists($db, 'jobs', 'abilities')) {
    echo "Adding abilities column...\n";
    $db->prepare("ALTER TABLE jobs ADD COLUMN abilities JSON NULL")->execute();
} else {
    echo "abilities column already exists, skipping.\n";
}

if (!column_exists($db, 'jobs', 'state')) {
    echo "Adding state column (defaulting existing rows to ACTIVE)...\n";
    $db->prepare("ALTER TABLE jobs ADD COLUMN state TINYINT UNSIGNED NOT NULL DEFAULT 0")->execute();
} else {
    echo "state column already exists, skipping.\n";
}

// --- Step 2: convert each row's old stat columns + job_abilities rows ---

if (column_exists($db, 'jobs', 'HP')) {
    echo "Converting stat columns to upgrade, and job_abilities to abilities...\n";

    $select = $db->prepare(
        "SELECT jobid, HP, MP, accuracy, strength, dodge, block, speed, power, resistance, focus
         FROM jobs"
    );
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    $select_abilities = null;
    if (table_exists($db, 'job_abilities')) {
        $select_abilities = $db->prepare(
            "SELECT abilityid AS ability, level FROM job_abilities
             WHERE jobid = ? ORDER BY level"
        );
    }

    $update = $db->prepare("UPDATE jobs SET upgrade = ?, abilities = ? WHERE jobid = ?");

    $db->begin_transaction();
    try {
        foreach ($rows as $row) {
            $upgrade = [];
            foreach (STAT_COLUMN_MAP as $old_column => $new_key) {
                $upgrade[$new_key] = (float) $row[$old_column];
            }

            $abilities = [];
            if ($select_abilities !== null) {
                $select_abilities->bind_param("i", $row['jobid']);
                $select_abilities->execute();
                $abilities = $select_abilities->get_result()->fetch_all(MYSQLI_ASSOC);
                // fetch_all gives string keys per row already (ability, level);
                // cast level to int since it comes back as a string from mysqli.
                foreach ($abilities as &$pair) {
                    $pair['ability'] = (int) $pair['ability'];
                    $pair['level'] = (int) $pair['level'];
                }
                unset($pair);
            }

            $upgrade_json = json_encode($upgrade);
            $abilities_json = json_encode($abilities);
            $update->bind_param("ssi", $upgrade_json, $abilities_json, $row['jobid']);
            $update->execute();
        }
        $db->commit();
        echo "Converted " . count($rows) . " row(s).\n";
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Conversion failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    // --- Step 3: drop the old columns/table now that everything's copied over ---
    echo "Dropping old stat columns...\n";
    $drop_columns = implode(', ', array_map(fn($c) => "DROP COLUMN $c", array_keys(STAT_COLUMN_MAP)));
    $db->prepare("ALTER TABLE jobs $drop_columns")->execute();

    if (table_exists($db, 'job_abilities')) {
        echo "Dropping job_abilities table...\n";
        $db->prepare("DROP TABLE job_abilities")->execute();
    }
} else {
    echo "HP column already gone, skipping conversion.\n";
}

// --- Step 4: regenerate the cache file in the new format ---

require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'character.php';
require_once INCLUDE_GAME . 'job.php';
echo "Regenerating jobs cache file...\n";
JOB::write_cache_file($db);

echo "Done.\n";