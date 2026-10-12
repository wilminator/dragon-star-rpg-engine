<?php
/**
 * fight_players.teamid -- signed. The live table was created with
 * `teamid int unsigned`, which cannot hold CHARACTER::MONSTER_TEAMID
 * (-1), the seat a player controlling a monster party is given. Under
 * MySQL strict mode, saving that seat fails outright.
 *
 * Safe to re-run: only alters the column when it is still unsigned.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v12.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';

$db = Database::mysql(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_DATABASE'));

// Aliased: MySQL 8 names information_schema's columns in capitals
// (COLUMN_TYPE), whatever case the query asks for.
$stmt = $db->prepare(
    "SELECT column_type AS col_type FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'fight_players' AND column_name = 'teamid'"
);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    echo "No fight_players.teamid column yet: the game will create the table signed. Nothing to do.\n";
} elseif (!str_contains(strtolower((string) $row['col_type']), 'unsigned')) {
    echo "fight_players.teamid is already signed ({$row['col_type']}), skipping.\n";
} else {
    echo "Making fight_players.teamid signed (was {$row['col_type']})...\n";
    $db->prepare("ALTER TABLE fight_players MODIFY teamid int NOT NULL")->execute();
}

echo "Done.\n";
