<?php
/**
 * Re-keys fight_saves from playerid to teamid:
 *
 *   - Renames the teamid column (was playerid).
 *   - Primary key becomes (fightid, teamid, type) (was
 *     (fightid, playerid, type), itself only just introduced by
 *     fight-schema-v5.php).
 *
 * A player can own more than one team, and keying by playerid meant
 * all of a player's teams shared one 25-fight rolling history and one
 * 10-fight retain cap -- a team with a lot of recent activity could
 * crowd another of the same player's teams out of its own history.
 * Keying by teamid instead gives each team the independent treatment
 * it should get. See FightSave's own docblock for the full reasoning.
 *
 * fight_saves has never actually held real data -- confirmed directly
 * (nothing has ever read or written it outside this session's own
 * work building FightSave), so this drops and recreates the table
 * outright rather than attempting an in-place rename, with the same
 * safety check fight-schema-v5.php used: refuses to proceed if it
 * finds any row already there.
 *
 * Handles whichever state the live table is actually in: not created
 * yet, still in fight-schema-v5.php's shape (playerid-keyed), or
 * already this migration's own teamid-keyed shape (a no-op then).
 *
 * Safe to re-run.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v6.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight_semaphore.php';
require_once INCLUDE_GAME . 'fight_save.php';

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');

$db = Database::mysql($host, $user, $pass, $dbname);

function table_exists(Database $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
}

function column_exists(Database $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
}

function primary_key_columns(Database $db, string $table): array {
    $stmt = $db->prepare(
        "SELECT column_name AS col FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY'
         ORDER BY ordinal_position"
    );
    $stmt->execute([$table]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'col');
}

$exists = table_exists($db, 'fight_saves');
$already_aligned = $exists
    && column_exists($db, 'fight_saves', 'teamid')
    && !column_exists($db, 'fight_saves', 'playerid')
    && primary_key_columns($db, 'fight_saves') === ['fightid', 'teamid', 'type'];

if ($already_aligned) {
    echo "fight_saves already keyed by teamid, skipping.\n";
} else {
    if ($exists) {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM fight_saves");
        $stmt->execute();
        $row_count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        if ($row_count > 0) {
            echo "REFUSING to proceed: fight_saves has $row_count row(s), but this migration assumes it's empty (nothing has ever written real data to it). Resolve this manually before re-running.\n";
            exit(1);
        }
        echo "Dropping the old fight_saves (confirmed empty)...\n";
        $db->prepare("DROP TABLE fight_saves")->execute();
    }
    echo "Creating fight_saves keyed by teamid...\n";
    FightSave::ensure_table_exists($db);
}

echo "Done.\n";
