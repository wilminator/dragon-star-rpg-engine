<?php
/**
 * Groundwork for live spectating:
 *
 *   1. fight_semaphore.spectatable -- whether non-admins may watch a
 *      fight while it's live. Stored on the semaphore rather than the
 *      fight, because the semaphore is purged once the fight resolves:
 *      the permission is meant to be transient. Existing rows default
 *      to 0 (not spectatable).
 *
 *   2. The SPECTATE_FIGHTS permission, granted to every existing admin.
 *      New admins get it from USER_STORE::create_user(). Admins are
 *      recognized here as accounts holding RESET_DB, which
 *      create_user() grants only to admins.
 *
 * Safe to re-run: the column addition is guarded, and the grant uses
 * REPLACE on the (userid, permission_name) pair.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v10.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';

$db = Database::mysql(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_DATABASE'));

function column_exists(Database $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
}

// --- 1. fight_semaphore.spectatable ---
if (column_exists($db, 'fight_semaphore', 'spectatable')) {
    echo "fight_semaphore.spectatable already exists, skipping add.\n";
} else {
    echo "Adding fight_semaphore.spectatable...\n";
    $db->prepare("ALTER TABLE fight_semaphore ADD COLUMN spectatable int NOT NULL DEFAULT 0")->execute();
}

// --- 2. SPECTATE_FIGHTS for existing admins ---
$stmt = $db->prepare(
    "REPLACE INTO permissions (userid, permission_name)
     SELECT userid, 'SPECTATE_FIGHTS' FROM permissions WHERE permission_name = 'RESET_DB'"
);
$stmt->execute();
$count = $db->prepare("SELECT COUNT(*) AS cnt FROM permissions WHERE permission_name = 'SPECTATE_FIGHTS'");
$count->execute();
echo "SPECTATE_FIGHTS is now held by " . (int) $count->fetch(PDO::FETCH_ASSOC)['cnt'] . " account(s).\n";

echo "Done.\n";
