<?php
/**
 * Accounts move onto the portable Database layer (UserAccount,
 * PermissionGrant), replacing USER_STORE. For the existing MySQL users
 * table:
 *
 *   - adds email (unique), email_verified_at and last_login_at, and
 *     totp_secret / totp_last_step for two-factor sign-in;
 *   - turns created_at from a MySQL timestamp into a Unix time (int),
 *     the convention every other table uses;
 *   - counts every existing account as email-confirmed, so they can
 *     keep logging in (only public sign-ups need to confirm);
 *   - gives MANAGE_USERS (the new right to manage accounts) to every
 *     account holding RESET_DB, so the current admins can reach the
 *     new account pages.
 *
 * Safe to re-run: each step checks first.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/accounts-schema-v1.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';

$db = Database::mysql(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_DATABASE'));

function column_type(Database $db, string $table, string $column): ?string {
    $stmt = $db->prepare(
        "SELECT data_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : strtolower($row['data_type'] ?? $row['DATA_TYPE']);
}

if (column_type($db, 'users', 'userid') === null) {
    echo "No users table yet: the login page will create it in the new shape. Nothing to do.\n";
    exit;
}

if (column_type($db, 'users', 'created_at') === 'timestamp') {
    echo "Converting users.created_at to a Unix time...\n";
    $db->prepare("ALTER TABLE users ADD COLUMN created_at_unix int NOT NULL DEFAULT 0")->execute();
    $db->prepare("UPDATE users SET created_at_unix = UNIX_TIMESTAMP(created_at)")->execute();
    $db->prepare("ALTER TABLE users DROP COLUMN created_at")->execute();
    $db->prepare("ALTER TABLE users RENAME COLUMN created_at_unix TO created_at")->execute();
} else {
    echo "users.created_at is already a Unix time, skipping.\n";
}

if (column_type($db, 'users', 'email') === null) {
    echo "Adding users.email...\n";
    $db->prepare("ALTER TABLE users ADD COLUMN email varchar(255) NULL")->execute();
    $db->prepare("ALTER TABLE users ADD UNIQUE KEY email (email)")->execute();
} else {
    echo "users.email already exists, skipping.\n";
}

if (column_type($db, 'users', 'email_verified_at') === null) {
    echo "Adding users.email_verified_at; existing accounts count as confirmed...\n";
    $db->prepare("ALTER TABLE users ADD COLUMN email_verified_at int NULL")->execute();
    $db->prepare("UPDATE users SET email_verified_at = IF(created_at > 0, created_at, UNIX_TIMESTAMP())")->execute();
} else {
    echo "users.email_verified_at already exists, skipping.\n";
}

if (column_type($db, 'users', 'last_login_at') === null) {
    echo "Adding users.last_login_at...\n";
    $db->prepare("ALTER TABLE users ADD COLUMN last_login_at int NULL")->execute();
} else {
    echo "users.last_login_at already exists, skipping.\n";
}

foreach (['totp_secret' => 'varchar(64) NULL', 'totp_last_step' => 'int NULL'] as $column => $type) {
    if (column_type($db, 'users', $column) === null) {
        echo "Adding users.$column (two-factor sign-in)...\n";
        $db->prepare("ALTER TABLE users ADD COLUMN $column $type")->execute();
    } else {
        echo "users.$column already exists, skipping.\n";
    }
}

// totp_secret holds an encrypted value now (see include/secret_box.php),
// longer than the 64 characters first given to it.
$stmt = $db->prepare("SELECT character_maximum_length AS n FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_secret'");
$stmt->execute();
$length = $stmt->fetch(PDO::FETCH_ASSOC);
if ($length !== false && (int) ($length['n'] ?? $length['N']) < 255) {
    echo "Widening users.totp_secret for encrypted values...\n";
    $db->prepare("ALTER TABLE users MODIFY totp_secret varchar(255) NULL")->execute();
}

// account_tokens is new in this release; if an earlier copy of it was
// created before tokens could carry data, add the column.
if (column_type($db, 'account_tokens', 'token_hash') !== null && column_type($db, 'account_tokens', 'data') === null) {
    echo "Adding account_tokens.data...\n";
    $db->prepare("ALTER TABLE account_tokens ADD COLUMN data varchar(255) NULL")->execute();
}

echo "Giving MANAGE_USERS to every RESET_DB holder...\n";
$db->prepare(
    "INSERT IGNORE INTO permissions (userid, permission_name)
     SELECT userid, 'MANAGE_USERS' FROM permissions WHERE permission_name = 'RESET_DB'"
)->execute();

echo "Giving REVIEW_LOGS (the logs page) to every RESET_DB or REVIEW_ANOMALIES holder...\n";
$db->prepare(
    "INSERT IGNORE INTO permissions (userid, permission_name)
     SELECT userid, 'REVIEW_LOGS' FROM permissions WHERE permission_name IN ('RESET_DB', 'REVIEW_ANOMALIES')"
)->execute();
$db->prepare("DELETE FROM permissions WHERE permission_name = 'REVIEW_ANOMALIES'")->execute();

// Problem reports stored by the short-lived anomalies table move into
// the site log, with their retention settings.
$stmt = $db->prepare("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'anomalies'");
$stmt->execute();
if ((int) $stmt->fetch(PDO::FETCH_ASSOC)['n'] > 0) {
    echo "Moving problem reports from anomalies into game_log...\n";
    require_once INCLUDE_GAME . 'game_log.php';
    GameLog::ensure_table_exists($db);
    $db->prepare(
        "INSERT INTO game_log (created_at, category, level, kind, message, userid, fightid, ip, request, location, details, resolved_at, resolved_by)
         SELECT created_at, IF(source = 'client_error', 'client_error', 'browser'), IF(source = 'client_error', 'error', 'warning'),
                kind, LEFT(COALESCE(summary, ''), 1000),
                userid, fightid, ip, NULL, NULL, details, resolved_at, resolved_by
         FROM anomalies ORDER BY id"
    )->execute();
    foreach (['days', 'rows', 'cull'] as $name) {
        $old = $name === 'cull' ? 'anomaly_retention_cull_percent' : "anomaly_retention_$name";
        foreach (['browser', 'client_error'] as $category) {
            $db->prepare("INSERT IGNORE INTO app_settings (name, value) SELECT ?, value FROM app_settings WHERE name = ?")
                ->execute(["log.$category.$name", $old]);
        }
        $db->prepare("DELETE FROM app_settings WHERE name = ?")->execute([$old]);
    }
    $db->prepare("DROP TABLE anomalies")->execute();
}

echo "Done.\n";
