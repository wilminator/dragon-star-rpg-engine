<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'rate_limit.php';
require_once INCLUDE_GAME . 'app_setting.php';

/**
 * The site's log: a row of the game_log table per entry, reviewed on
 * admin/logs.php.
 *
 * Every entry has a category, and each category is its own log: it can
 * be switched off, and has its own retention (see purge()). The
 * categories:
 *
 *   server_error  PHP errors, warnings and uncaught exceptions, and
 *                 log_error() calls
 *   security      refused or suspicious requests (log_error() messages
 *                 starting "HACK")
 *   browser       problems players' browsers report (report_anomalies)
 *   client_error  data or logic problems the client caught
 *   debug         tracing, in channels (DEBUG_CHANNELS), each switched on
 *                 separately and off by default -- see log_debug()
 *
 * Within a category, entries have a level (error, warning, notice,
 * debug) and a kind (what sort of problem, for grouping and filtering).
 *
 * Everything is scrubbed before it's stored (see LogScrubber):
 * passwords, codes, tokens, security-key answers, encrypted values and
 * the like never reach the table.
 *
 * If the database can't be written -- it's down, or the error is the
 * database itself -- the entry goes to the error log file instead, so
 * nothing is lost and logging can't loop on itself.
 */
final class GameLog {
    private static bool $table_verified = false;

    const CATEGORIES = [
        'server_error' => 'Server errors and warnings',
        'security' => 'Security: refused or suspicious requests',
        'browser' => "Problems reported by players' browsers",
        'client_error' => 'Problems the game client caught',
        'debug' => 'Debug tracing',
    ];
    // The debug log's channels: each records only while switched on
    // (set_debug_enabled()). An entry's kind is its channel.
    const DEBUG_CHANNELS = [
        'rpc' => 'Requests and responses',
        'fight' => 'Fight rounds',
        'ai' => 'AI decisions',
        'xp' => 'Experience sharing',
        'monsters' => 'Monster party generation',
        'map' => 'Map data',
    ];
    // Off unless an admin switches them on.
    const OFF_BY_DEFAULT = ['debug'];
    const LEVELS = ['error', 'warning', 'notice', 'debug'];

    const MAX_MESSAGE = 1000;
    const MAX_DETAILS = 65536;
    // More than this from one request goes to the file instead.
    const MAX_PER_REQUEST = 100;
    const FALLBACK_FILE = 'errorlog.txt';

    const RETENTION_CHECK_SECONDS = 60;
    const RETENTION_CHECKED = 'log_retention_checked_at';

    private static bool $writing = false;
    // Debug channels' switches, read once per request: [channel => bool].
    private static array $debug_switches = [];
    private static int $written = 0;
    // For tests, and for pages that know their database already.
    private static ?Database $db = null;
    private static string $fallback_file = self::FALLBACK_FILE;

    public static function use_database(?Database $db): void {
        self::$db = $db;
        self::$debug_switches = [];
    }

    // Starts the per-request count again -- for long-running scripts
    // that handle many requests, and tests.
    // Returns the file used until now, to put back afterward.
    public static function use_fallback_file(?string $path): string {
        $previous = self::$fallback_file;
        self::$fallback_file = $path ?? self::FALLBACK_FILE;
        return $previous;
    }

    public static function new_request(): void {
        self::$written = 0;
        self::$writing = false;
        self::$debug_switches = [];
    }

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $id_column = $db->auto_increment_primary_key('id');
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS game_log",
            <<<QUERY
CREATE TABLE IF NOT EXISTS game_log (
  $id_column,
  created_at int NOT NULL,
  category varchar(32) NOT NULL,
  level varchar(16) NOT NULL,
  kind varchar(64) NOT NULL,
  message varchar(1000) NOT NULL,
  userid int NULL,
  fightid int NULL,
  ip varchar(45) NULL,
  request varchar(255) NULL,
  location varchar(255) NULL,
  details text NULL,
  resolved_at int NULL,
  resolved_by int NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // --- writing ---

    // Records one entry. $context, all optional: 'userid', 'fightid',
    // 'ip', 'request', 'location' -- the request's own are filled in
    // where not given. Returns false when it went to the file instead
    // (or was dropped: its category is switched off).
    public static function write(string $category, string $level, string $kind, string $message, $details = null,
                                 array $context = [], ?Database $db = null): bool {
        if (!array_key_exists($category, self::CATEGORIES)) {
            $category = 'server_error';
        }
        $level = in_array($level, self::LEVELS, true) ? $level : 'error';
        $kind = self::clean_kind($kind);
        $message = LogScrubber::text($message);
        $details = $details === null ? null : LogScrubber::value($details);

        if (self::$writing || self::$written >= self::MAX_PER_REQUEST) {
            self::to_file($category, $level, $kind, $message, $details);
            return false;
        }
        self::$writing = true;
        try {
            $db ??= self::$db ?? get_database();
            if ($category === 'debug' ? !self::debug_enabled($kind, $db) : !self::enabled($db, $category)) {
                return false;
            }
            self::ensure_table_exists($db);
            $json = null;
            if ($details !== null) {
                $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '"(unencodable)"';
                if (strlen($json) > self::MAX_DETAILS) {
                    $json = json_encode(['truncated' => true, 'bytes' => strlen($json), 'start' => mb_strcut($json, 0, self::MAX_DETAILS - 200)]);
                }
            }
            $context += self::request_context();
            $stmt = $db->prepare($db->insert_query('game_log', ['created_at', 'category', 'level', 'kind', 'message',
                'userid', 'fightid', 'ip', 'request', 'location', 'details', 'resolved_at', 'resolved_by']));
            $stmt->execute([time(), $category, $level, $kind, mb_strcut($message, 0, self::MAX_MESSAGE),
                $context['userid'] ?? null, $context['fightid'] ?? null,
                isset($context['ip']) ? substr((string) $context['ip'], 0, 45) : null,
                isset($context['request']) ? mb_strcut(LogScrubber::text((string) $context['request']), 0, 255) : null,
                isset($context['location']) ? mb_strcut((string) $context['location'], 0, 255) : null,
                $json, null, null]);
            self::$written++;
            self::maybe_purge($db);
            return true;
        } catch (\Throwable $e) {
            self::to_file('server_error', 'error', 'log_write_failed', 'Logging to the database failed: ' . $e->getMessage(), null);
            self::to_file($category, $level, $kind, $message, $details);
            return false;
        } finally {
            self::$writing = false;
        }
    }

    // What the running request can say about itself.
    private static function request_context(): array {
        $userid = (int) ($GLOBALS['userid'] ?? ($_SESSION['userid'] ?? 0));
        $request = $_SERVER['SCRIPT_NAME'] ?? (PHP_SAPI === 'cli' ? 'cli' : null);
        if (isset($GLOBALS['current_rpc_method'])) {
            $request .= ' ' . $GLOBALS['current_rpc_method'];
        }
        return [
            'userid' => $userid > 0 ? $userid : null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'request' => $request,
        ];
    }

    private static function to_file(string $category, string $level, string $kind, string $message, $details): void {
        $line = date('Y-m-d H:i:s') . " [$category/$level/$kind] $message"
            . ($details === null ? '' : ' ' . json_encode($details, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) . "\n";
        @file_put_contents(self::$fallback_file, $line, FILE_APPEND | LOCK_EX);
    }

    // Lowercase letters, digits and underscores, at most 64; else 'unknown'.
    public static function clean_kind($kind): string {
        $kind = is_string($kind) ? strtolower(trim($kind)) : '';
        return preg_match('/^[a-z0-9_]{1,64}$/', $kind) ? $kind : 'unknown';
    }

    // --- per-category settings ---

    private static function setting(string $category, string $name): string {
        return "log.$category.$name";
    }

    public static function enabled(Database $db, string $category): bool {
        $value = AppSetting::get($db, self::setting($category, 'enabled'));
        return $value === null ? !in_array($category, self::OFF_BY_DEFAULT, true) : $value === '1';
    }

    public static function set_enabled(Database $db, string $category, bool $enabled): void {
        AppSetting::set($db, self::setting($category, 'enabled'), $enabled ? '1' : '0');
    }

    // Whether a debug channel is recording. Cheap to ask often: read once
    // per request. False for an unknown channel, or when there's no
    // database to ask.
    public static function debug_enabled(string $channel, ?Database $db = null): bool {
        if (!array_key_exists($channel, self::DEBUG_CHANNELS)) {
            return false;
        }
        if (!array_key_exists($channel, self::$debug_switches)) {
            try {
                $db ??= self::$db ?? get_database();
                self::$debug_switches[$channel] = AppSetting::get($db, "log.debug.$channel.enabled") === '1';
            } catch (\Throwable $e) {
                self::$debug_switches[$channel] = false;
            }
        }
        return self::$debug_switches[$channel];
    }

    public static function set_debug_enabled(Database $db, string $channel, bool $enabled): void {
        if (array_key_exists($channel, self::DEBUG_CHANNELS)) {
            AppSetting::set($db, "log.debug.$channel.enabled", $enabled ? '1' : '0');
            self::$debug_switches = [];
        }
    }

    // ['days' => ?int, 'rows' => ?int, 'cull_percent' => int]; null = no limit.
    public static function retention(Database $db, string $category): array {
        $number = fn(string $name) => ($v = AppSetting::get($db, self::setting($category, $name))) === null || $v === '' ? null : (int) $v;
        return ['days' => $number('days'), 'rows' => $number('rows'), 'cull_percent' => $number('cull') ?? 0];
    }

    // The reasons nothing was saved; [] when saved. Days and rows are
    // whole numbers of at least 1, or null for no limit; the cull is 0
    // to 100 (percent).
    public static function set_retention(Database $db, string $category, ?int $days, ?int $rows, int $cull_percent): array {
        $problems = [];
        if (!array_key_exists($category, self::CATEGORIES)) {
            $problems[] = "There's no log called $category.";
        }
        if ($days !== null && $days < 1) {
            $problems[] = 'Keep entries for at least 1 day, or leave it blank for no age limit.';
        }
        if ($rows !== null && $rows < 1) {
            $problems[] = 'Keep at least 1 entry, or leave it blank for no row limit.';
        }
        if ($cull_percent < 0 || $cull_percent > 100) {
            $problems[] = 'The cull is a percentage from 0 to 100.';
        }
        if ($problems !== []) {
            return $problems;
        }
        foreach (['days' => $days, 'rows' => $rows] as $name => $value) {
            if ($value === null) {
                AppSetting::delete($db, self::setting($category, $name));
            } else {
                AppSetting::set($db, self::setting($category, $name), (string) $value);
            }
        }
        AppSetting::set($db, self::setting($category, 'cull'), (string) $cull_percent);
        return [];
    }

    // --- retention ---

    // Purges one category's entries that are due: older than its days,
    // and/or beyond its row limit, oldest first. When anything is due, a
    // further cull percentage of the row limit (or, with no row limit,
    // of the whole log) goes too, so purges come in batches; at 0% just
    // what's due goes, at least one entry. Returns how many went.
    public static function purge(Database $db, string $category, ?int $now = null): int {
        self::ensure_table_exists($db);
        $now ??= time();
        $settings = self::retention($db, $category);
        if ($settings['days'] === null && $settings['rows'] === null) {
            return 0;
        }
        $count = (int) self::scalar($db, "SELECT COUNT(*) AS n FROM game_log WHERE category = ?", [$category]);
        $due = 0;
        if ($settings['rows'] !== null && $count > $settings['rows']) {
            $due = $count - $settings['rows'];
        }
        if ($settings['days'] !== null) {
            $due = max($due, (int) self::scalar($db, "SELECT COUNT(*) AS n FROM game_log WHERE category = ? AND created_at < ?",
                [$category, $now - $settings['days'] * 86400]));
        }
        if ($due === 0) {
            return 0;
        }
        $cull = intdiv(($settings['rows'] ?? $count) * $settings['cull_percent'], 100);
        $remove = min($count, max(1, $due + $cull));

        // The newest entry to go; everything in the category up to it goes.
        $stmt = $db->prepare("SELECT id FROM game_log WHERE category = ? ORDER BY id LIMIT 1 OFFSET " . ($remove - 1));
        $stmt->execute([$category]);
        $cutoff = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cutoff === false) {
            return 0;
        }
        $stmt = $db->prepare("DELETE FROM game_log WHERE category = ? AND id <= ?");
        $stmt->execute([$category, (int) $cutoff['id']]);
        return $stmt->rowCount();
    }

    // Every category's purge, at most once per RETENTION_CHECK_SECONDS --
    // run as entries are written. [category => removed].
    public static function maybe_purge(Database $db, ?int $now = null): array {
        $now ??= time();
        $last = (int) (AppSetting::get($db, self::RETENTION_CHECKED) ?? 0);
        if ($last + self::RETENTION_CHECK_SECONDS > $now) {
            return [];
        }
        AppSetting::set($db, self::RETENTION_CHECKED, (string) $now);
        return self::purge_all($db, $now);
    }

    public static function purge_all(Database $db, ?int $now = null): array {
        $removed = [];
        foreach (array_keys(self::CATEGORIES) as $category) {
            $removed[$category] = self::purge($db, $category, $now);
        }
        return $removed;
    }

    // --- reviewing (admin/logs.php) ---

    // Filters, every key optional:
    //   'category', 'level', 'kind' => string, 'userid', 'fightid' => int,
    //   'from' / 'to' => int (Unix times, inclusive),
    //   'status' => 'open' | 'resolved' (default: both),
    //   'text' => string (in the message or details), 'limit' => int (200).
    // Newest first, as arrays (see from_row()).
    public static function search(Database $db, array $filters = []): array {
        self::ensure_table_exists($db);
        $conditions = [];
        $params = [];
        foreach (['category', 'level', 'kind'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $conditions[] = "$column = ?";
                $params[] = (string) $filters[$column];
            }
        }
        foreach (['userid', 'fightid'] as $column) {
            if (isset($filters[$column])) {
                $conditions[] = "$column = ?";
                $params[] = (int) $filters[$column];
            }
        }
        if (isset($filters['from'])) {
            $conditions[] = 'created_at >= ?';
            $params[] = (int) $filters['from'];
        }
        if (isset($filters['to'])) {
            $conditions[] = 'created_at <= ?';
            $params[] = (int) $filters['to'];
        }
        if (($filters['status'] ?? '') === 'open') {
            $conditions[] = 'resolved_at IS NULL';
        } elseif (($filters['status'] ?? '') === 'resolved') {
            $conditions[] = 'resolved_at IS NOT NULL';
        }
        if (trim((string) ($filters['text'] ?? '')) !== '') {
            $conditions[] = '(LOWER(message) LIKE ? OR LOWER(details) LIKE ?)';
            $text = '%' . strtolower(trim((string) $filters['text'])) . '%';
            array_push($params, $text, $text);
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $limit = max(1, (int) ($filters['limit'] ?? 200));
        $stmt = $db->prepare("SELECT * FROM game_log $where ORDER BY id DESC LIMIT $limit");
        $stmt->execute($params);
        return array_map(fn($row) => self::from_row($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Open entries: [category => [kind => count]], most first within each.
    public static function open_counts(Database $db): array {
        self::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT category, kind, COUNT(*) AS n FROM game_log WHERE resolved_at IS NULL
            GROUP BY category, kind ORDER BY category, COUNT(*) DESC, kind");
        $stmt->execute();
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['category']][$row['kind']] = (int) $row['n'];
        }
        return $counts;
    }

    // Kinds seen, for the filter list.
    public static function kinds(Database $db, string $category = ''): array {
        self::ensure_table_exists($db);
        $stmt = $category === '' ? $db->prepare("SELECT DISTINCT kind FROM game_log ORDER BY kind")
            : $db->prepare("SELECT DISTINCT kind FROM game_log WHERE category = ? ORDER BY kind");
        $stmt->execute($category === '' ? [] : [$category]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'kind');
    }

    public static function count(Database $db, string $category): int {
        self::ensure_table_exists($db);
        return (int) self::scalar($db, "SELECT COUNT(*) AS n FROM game_log WHERE category = ?", [$category]);
    }

    // Marks entries dealt with (or open again). Returns how many changed.
    public static function set_resolved(Database $db, array $ids, bool $resolved, int $admin_userid): int {
        self::ensure_table_exists($db);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $resolved
            ? $db->prepare("UPDATE game_log SET resolved_at = ?, resolved_by = ? WHERE resolved_at IS NULL AND id IN ($placeholders)")
            : $db->prepare("UPDATE game_log SET resolved_at = NULL, resolved_by = NULL WHERE resolved_at IS NOT NULL AND id IN ($placeholders)");
        $stmt->execute($resolved ? [time(), $admin_userid, ...$ids] : $ids);
        return $stmt->rowCount();
    }

    private static function scalar(Database $db, string $query, array $params) {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)['n'];
    }

    private static function from_row(array $row): array {
        $int = fn($v) => $v === null ? null : (int) $v;
        return [
            'id' => (int) $row['id'],
            'created_at' => (int) $row['created_at'],
            'category' => $row['category'],
            'level' => $row['level'],
            'kind' => $row['kind'],
            'message' => $row['message'],
            'userid' => $int($row['userid']),
            'fightid' => $int($row['fightid']),
            'ip' => $row['ip'],
            'request' => $row['request'],
            'location' => $row['location'],
            'details' => $row['details'] === null ? null : json_decode($row['details'], true),
            'resolved_at' => $int($row['resolved_at']),
            'resolved_by' => $int($row['resolved_by']),
        ];
    }
}

/**
 * Takes secrets out of anything about to be logged. Two passes:
 *
 *   - values under a telling key -- password, secret, token, code, otp,
 *     csrf, signature, cookie, session, authorization, anything
 *     webauthn, and the like -- are replaced whole, at any depth;
 *   - in text, the same names in query strings, form bodies and JSON
 *     ("password=...", "\"code\":\"...\""), Bearer credentials, password
 *     hashes, encrypted values (enc1:...) and one-time link tokens (64
 *     hex characters) are replaced.
 *
 * Deliberately broad: a scrubbed value that turns out to have been
 * harmless costs a little detail; a missed secret can't be taken back.
 */
final class LogScrubber {
    const MASK = '[scrubbed]';
    const SECRET_KEY = '/pass(word|wd|phrase)?|secret|token|csrf|otp|totp|\bcode\b|^code$|recovery|signature|attestation|authenticator|client_?data|webauthn|cookie|session|authori[sz]ation|bearer|api_?key|private|credential/i';
    const TEXT_PATTERNS = [
        // name=value in query strings and form bodies
        '/\b((?:[a-z_]*?)(?:pass(?:word|wd)?|secret|token|csrf|otp|totp|code|recovery|signature|attestation|client_?data|cookie|session|credential)[a-z_]*)=([^&\s"\']+)/i' => '$1=' . self::MASK,
        // "name": "value" in JSON
        '/("(?:[a-z_]*?)(?:pass(?:word|wd)?|secret|token|csrf|otp|totp|code|recovery|signature|attestation|client_?data|cookie|session|credential)[a-z_]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i' => '$1"' . self::MASK . '"',
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer ' . self::MASK,
        '/\$(?:argon2id?|argon2i|2[aby])\$[^\s"\']+/' => self::MASK,
        '/\benc1:[0-9a-f]{16}:[A-Za-z0-9+\/=]+/' => self::MASK,
        '/\b[0-9a-f]{64}\b/i' => self::MASK,
    ];

    public static function value($value, int $depth = 0) {
        if ($depth > 32) {
            return self::MASK;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[$key] = is_string($key) && preg_match(self::SECRET_KEY, $key) ? self::MASK : self::value($item, $depth + 1);
            }
            return $clean;
        }
        return is_string($value) ? self::text($value) : $value;
    }

    public static function text(string $text): string {
        return (string) preg_replace(array_keys(self::TEXT_PATTERNS), array_values(self::TEXT_PATTERNS), $text);
    }
}
