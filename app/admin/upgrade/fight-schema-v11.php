<?php
/**
 * fight_players.played_sequence -- the last round each player's client
 * reported as fully played back. Once every player with a living
 * character has acknowledged a round, the round's deadline drops from
 * the playback fallback to just the time needed to enter commands (see
 * FightCommunicator::acknowledge_playback()). A refreshed client that
 * already acknowledged the current round starts at command entry
 * instead of replaying it. Existing rows default to -1 (nothing
 * acknowledged yet).
 *
 * Safe to re-run: the column addition is guarded.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v11.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';

$db = Database::mysql(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_DATABASE'));

$stmt = $db->prepare(
    "SELECT COUNT(*) AS cnt FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'fight_players' AND column_name = 'played_sequence'"
);
$stmt->execute();
if (((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0) {
    echo "fight_players.played_sequence already exists, skipping add.\n";
} else {
    echo "Adding fight_players.played_sequence...\n";
    $db->prepare("ALTER TABLE fight_players ADD COLUMN played_sequence int NOT NULL DEFAULT -1")->execute();
}

echo "Done.\n";
