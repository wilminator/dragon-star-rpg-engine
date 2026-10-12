<?php
/**
 * Adds fights.created_at: a plain Unix timestamp (int), set once at
 * FIGHT construction and never touched again -- matching the
 * convention FightSemaphore's own timeout/lock_expires_at already
 * use, not a SQL datetime/timestamp column.
 *
 * Needed directly: nothing anywhere recorded when a fight was
 * created, which the admin fight-browser's own timestamp filter
 * needs (see admin/fight_playback.php).
 *
 * Existing fights (created before this column existed) get 0, not a
 * guess -- there's no way to recover their real creation time, and 0
 * is an honest "unknown" rather than a fabricated date.
 *
 * Safe to re-run.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v8.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');

$db = Database::mysql($host, $user, $pass, $dbname);

$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'fights' AND column_name = 'created_at'"
);
$stmt->execute();
$already_exists = ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;

if ($already_exists) {
    echo "fights.created_at already exists, skipping.\n";
} else {
    echo "Adding fights.created_at...\n";
    $db->prepare("ALTER TABLE fights ADD COLUMN created_at int NOT NULL DEFAULT 0")->execute();
}

echo "Done.\n";
