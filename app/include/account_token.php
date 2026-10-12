<?php
require_once INCLUDE_GAME . 'database.php';

// One-time links sent by email -- confirming an address (VERIFY),
// resetting a password (RESET), approving an email change from the old
// address (EMAIL_CHANGE) and confirming the new one (EMAIL_NEW) -- plus
// the short-lived token a correct two-factor code hands to the
// password-reset page. A row of the account_tokens table. data carries
// what the token is about, where that's needed (the new email address).
//
// Only a SHA-256 hash of each token is stored, so a leaked database
// doesn't hand out working links. A new token replaces any earlier one
// for the same account and purpose, and a token works once.
class AccountToken {
    private static bool $table_verified = false;

    const VERIFY = 'verify';
    const RESET = 'reset';
    const EMAIL_CHANGE = 'email_change';
    const EMAIL_NEW = 'email_new';

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS account_tokens",
            <<<QUERY
CREATE TABLE IF NOT EXISTS account_tokens (
  token_hash varchar(64) NOT NULL PRIMARY KEY,
  userid int NOT NULL,
  purpose varchar(16) NOT NULL,
  expires_at int NOT NULL,
  data varchar(255) NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Returns the token to put in the link.
    public static function issue(Database $db, int $userid, string $purpose, int $lifetime_seconds, ?string $data = null): string {
        static::ensure_table_exists($db);
        static::revoke_all($db, $userid, $purpose);
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare($db->insert_query('account_tokens', ['token_hash', 'userid', 'purpose', 'expires_at', 'data']));
        $stmt->execute([hash('sha256', $token), $userid, $purpose, time() + $lifetime_seconds, $data]);
        return $token;
    }

    // The account a still-valid token belongs to, without using it up.
    public static function peek(Database $db, string $token, string $purpose): ?int {
        return static::peek_with_data($db, $token, $purpose)['userid'] ?? null;
    }

    // ['userid' => int, 'data' => ?string] for a still-valid token.
    public static function peek_with_data(Database $db, string $token, string $purpose): ?array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT userid, expires_at, data FROM account_tokens WHERE token_hash = ? AND purpose = ?");
        $stmt->execute([hash('sha256', $token), $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row === false || (int) $row['expires_at'] < time()) ? null : ['userid' => (int) $row['userid'], 'data' => $row['data']];
    }

    // Uses the token up: the account it belongs to, or null if it's
    // unknown, spent or expired.
    public static function consume(Database $db, string $token, string $purpose): ?int {
        return static::consume_with_data($db, $token, $purpose)['userid'] ?? null;
    }

    // As consume(), with the token's data: ['userid' => int, 'data' => ?string].
    public static function consume_with_data(Database $db, string $token, string $purpose): ?array {
        $found = static::peek_with_data($db, $token, $purpose);
        $stmt = $db->prepare("DELETE FROM account_tokens WHERE token_hash = ? AND purpose = ?");
        $stmt->execute([hash('sha256', $token), $purpose]);
        return $stmt->rowCount() === 1 ? $found : null;
    }

    // ['userid' => int, 'purpose' => string, 'data' => ?string] for a
    // still-valid token of any purpose.
    public static function find(Database $db, string $token): ?array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT userid, purpose, expires_at, data FROM account_tokens WHERE token_hash = ?");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row === false || (int) $row['expires_at'] < time()) ? null
            : ['userid' => (int) $row['userid'], 'purpose' => $row['purpose'], 'data' => $row['data']];
    }

    // Every still-valid token an account has for these purposes:
    // [['purpose' => string, 'data' => ?string, 'expires_at' => int], ...].
    public static function outstanding(Database $db, int $userid, array $purposes): array {
        static::ensure_table_exists($db);
        if ($purposes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($purposes), '?'));
        $stmt = $db->prepare("SELECT purpose, data, expires_at FROM account_tokens
            WHERE userid = ? AND purpose IN ($placeholders) AND expires_at >= ? ORDER BY expires_at");
        $stmt->execute([$userid, ...$purposes, time()]);
        return array_map(fn($row) => ['purpose' => $row['purpose'], 'data' => $row['data'], 'expires_at' => (int) $row['expires_at']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function revoke_all(Database $db, int $userid, string $purpose): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM account_tokens WHERE userid = ? AND purpose = ?");
        $stmt->execute([$userid, $purpose]);
    }

    public static function purge_expired(Database $db): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM account_tokens WHERE expires_at < ?");
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }
}
