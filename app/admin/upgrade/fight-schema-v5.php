<?php
/**
 * Re-aligns fight_saves to FightSave's new shape:
 *
 *   - Adds fight_saves.power_ratio (nullable float) -- new.
 *   - Changes the primary key from (fightid, playerid) to
 *     (fightid, playerid, type). A single fight can now carry more
 *     than one tag for the same player (e.g. both 'user' and
 *     'underdog' at once, for a system-generated fight tagged
 *     playerid 0), which the original, narrower key couldn't
 *     represent at all -- a second type for the same (fightid,
 *     playerid) would have silently overwritten the first row instead
 *     of coexisting with it.
 *
 * fight_saves has never actually been written to by any code path --
 * confirmed directly earlier this session (the only references
 * anywhere in the app were FIGHT_STORE's own DDL and its LOCK TABLES
 * statement, never a real INSERT/SELECT/UPDATE) -- so this drops and
 * recreates the table outright rather than attempting an in-place
 * ALTER, with a safety check that refuses to proceed if it finds any
 * row already there (a sign something unexpected has written to it
 * since that was last confirmed).
 *
 * Safe to re-run: a no-op if the table already matches the new shape.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v5.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
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
    && column_exists($db, 'fight_saves', 'power_ratio')
    && primary_key_columns($db, 'fight_saves') === ['fightid', 'playerid', 'type'];

if ($already_aligned) {
    echo "fight_saves already matches the new shape, skipping.\n";
} else {
    if ($exists) {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM fight_saves");
        $stmt->execute();
        $row_count = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        if ($row_count > 0) {
            echo "REFUSING to proceed: fight_saves has $row_count row(s), but this migration assumes it's empty (nothing has ever written to it before). Resolve this manually before re-running.\n";
            exit(1);
        }
        echo "Dropping the old fight_saves (confirmed empty)...\n";
        $db->prepare("DROP TABLE fight_saves")->execute();
    }
    echo "Creating fight_saves with the new shape...\n";
    FightSave::ensure_table_exists($db);
}

echo "Done.\n";
