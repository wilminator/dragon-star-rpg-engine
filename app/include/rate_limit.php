<?php
require_once INCLUDE_GAME . 'database.php';

// Counts attempts per "bucket" (say, 'signup-ip:203.0.113.9') in fixed
// time windows, to slow down anyone hammering the public account pages.
// A row of the rate_limits table.
class RateLimit {
    private static bool $table_verified = false;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS rate_limits",
            <<<QUERY
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket varchar(191) NOT NULL PRIMARY KEY,
  window_start int NOT NULL,
  hits int NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Records one attempt and says whether it's within $max per
    // $window_seconds. Attempts over the limit still count, so hammering
    // doesn't wear the limit down. $now is for tests.
    public static function hit(Database $db, string $bucket, int $max, int $window_seconds, ?int $now = null): bool {
        static::ensure_table_exists($db);
        $now ??= time();
        $bucket = substr($bucket, 0, 191);
        $stmt = $db->prepare("SELECT window_start, hits FROM rate_limits WHERE bucket = ?");
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['window_start'] + $window_seconds <= $now) {
            $window_start = $now;
            $hits = 1;
        } else {
            $window_start = (int) $row['window_start'];
            $hits = (int) $row['hits'] + 1;
        }
        $stmt = $db->prepare($db->upsert_query('rate_limits', ['bucket', 'window_start', 'hits'], 'bucket'));
        $stmt->execute([$bucket, $window_start, $hits]);
        return $hits <= $max;
    }

    // Forgets a bucket's count (say, an account's failed logins once it
    // logs in).
    public static function clear(Database $db, string $bucket): void {
        static::ensure_table_exists($db);
        $db->prepare("DELETE FROM rate_limits WHERE bucket = ?")->execute([substr($bucket, 0, 191)]);
    }

    public static function purge_older_than(Database $db, int $seconds): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM rate_limits WHERE window_start < ?");
        $stmt->execute([time() - $seconds]);
        return $stmt->rowCount();
    }
}
