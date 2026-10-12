<?php
require_once INCLUDE_GAME . 'database.php';

// Small site-wide values the site sets for itself at runtime (as opposed
// to configuration, which comes from environment variables): a row of
// the app_settings table, name => text.
//
// Holds the mail mailbox's OAuth refresh token (see OAuthMailbox), which
// is a secret: whoever can read this table can send mail as that mailbox.
class AppSetting {
    private static bool $table_verified = false;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS app_settings",
            <<<QUERY
CREATE TABLE IF NOT EXISTS app_settings (
  name varchar(64) NOT NULL PRIMARY KEY,
  value text NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public static function get(Database $db, string $name): ?string {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT value FROM app_settings WHERE name = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row['value'];
    }

    public static function set(Database $db, string $name, string $value): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare($db->upsert_query('app_settings', ['name', 'value'], 'name'));
        $stmt->execute([$name, $value]);
    }

    public static function delete(Database $db, string $name): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM app_settings WHERE name = ?");
        $stmt->execute([$name]);
    }
}
