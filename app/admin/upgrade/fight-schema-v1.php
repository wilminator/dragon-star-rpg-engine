<?php
/**
 * First migration on the fights table: renames fightid -> id (matching
 * every other DbStoreTrait entity's convention) and replaces the
 * three-value active enum('yes','no','processing') with an integer
 * state column, backed by the new FightStatus enum:
 *   - 'yes'        -> FightStatus::WaitingForInput (0)
 *   - 'processing' -> FightStatus::Processing (1)
 *   - 'no'         -> FightStatus::Resolved (2)
 *
 * Purely structural. fight_data is left exactly as-is -- still PHP
 * serialize() format, not JSON -- and sequence/timeout are untouched;
 * timeout in particular isn't modeled on FIGHT at all yet, it just
 * carries forward unused by the new class for now. fight_players,
 * fight_actions, and fight_saves are not touched by this migration.
 *
 * Safe to re-run: if the `id` column already exists, the whole thing
 * is skipped immediately.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v1.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight.php';

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
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ((int) $row['cnt']) > 0;
}

// --- Step 0: if `id` already exists, this migration already ran -- done ---

if (column_exists($db, 'fights', 'id')) {
    echo "fights.id already exists, migration already complete. Skipping.\n";
    exit(0);
}

// --- Step 1: rename fightid -> id ---

echo "Renaming fightid -> id...\n";
$db->prepare("ALTER TABLE fights CHANGE fightid id INT(11) UNSIGNED NOT NULL")->execute();

// --- Step 2: add state, populate it from the old active enum, drop active ---

echo "Adding state column...\n";
$db->prepare("ALTER TABLE fights ADD COLUMN state INT NOT NULL DEFAULT " . FightStatus::WaitingForInput->value)->execute();

echo "Populating state from active...\n";
$db->prepare(
    "UPDATE fights SET state = CASE active
        WHEN 'yes' THEN " . FightStatus::WaitingForInput->value . "
        WHEN 'processing' THEN " . FightStatus::Processing->value . "
        WHEN 'no' THEN " . FightStatus::Resolved->value . "
     END"
)->execute();

echo "Dropping active column...\n";
$db->prepare("ALTER TABLE fights DROP COLUMN active")->execute();

echo "Done.\n";
