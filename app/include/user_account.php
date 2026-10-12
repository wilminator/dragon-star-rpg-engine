<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'app_setting.php';
require_once INCLUDE_GAME . 'account_token.php';
require_once INCLUDE_GAME . 'rate_limit.php';
require_once INCLUDE_GAME . 'secret_box.php';
require_once INCLUDE_GAME . 'anomaly.php';

/**
 * One player account: a row of the users table, on the portable
 * Database layer (replacing the mysqli-based USER_STORE).
 *
 * userid is also the playerid everywhere else in the game (teams,
 * fight_players, ...).
 *
 * Usernames are unique regardless of case, and matched regardless of
 * case at login. Emails are stored lowercased and are unique; an
 * account may have none (accounts made before sign-up existed).
 *
 * email_verified_at: when the account's email was confirmed. An
 * account can only log in once it's set -- accounts an admin creates,
 * and accounts that existed before sign-up, count as verified (see
 * admin/upgrade/accounts-schema-v1.php).
 *
 * totp_secret / totp_last_step: two-factor sign-in (see TwoFactor);
 * null when it's off.
 *
 * Times are Unix timestamps, the same convention as fights.created_at.
 */
class UserAccount {
    private static bool $table_verified = false;

    const USERNAME_PATTERN = '/^[A-Za-z0-9_-]{3,20}$/';
    const MIN_PASSWORD_LENGTH = 8;

    // Argon2id: memory-hard, so guessing on GPUs is expensive, and no
    // 72-byte limit on passwords (bcrypt's). PHP's own default is used
    // only where PHP was built without it. Older hashes are upgraded at
    // the next login (see password_matches()).
    public static function password_algorithm(): string|int|null {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public ?int $userid = null;
    public string $username;
    public ?string $email = null;
    public string $password_hash;
    public bool $on_hold = false;
    public int $created_at;
    public ?int $email_verified_at = null;
    public ?int $last_login_at = null;
    // The authenticator-app secret, decrypted. Stored encrypted (see
    // SecretBox). If it can't be decrypted -- its key has gone from the
    // key slots -- $totp_readable is false, $totp_secret holds the stored
    // form untouched, and the app's codes simply don't work.
    public ?string $totp_secret = null;
    public bool $totp_readable = true;
    public ?int $totp_last_step = null;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $id_column = $db->auto_increment_primary_key('userid');
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS users",
            <<<QUERY
CREATE TABLE IF NOT EXISTS users (
  $id_column,
  username varchar(64) NOT NULL UNIQUE,
  email varchar(255) NULL UNIQUE,
  password_hash varchar(255) NOT NULL,
  on_hold int NOT NULL DEFAULT 0,
  created_at int NOT NULL DEFAULT 0,
  email_verified_at int NULL,
  last_login_at int NULL,
  totp_secret varchar(255) NULL,
  totp_last_step int NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // --- validation (null when fine, otherwise the reason) ---

    public static function username_problem(string $username): ?string {
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return 'Usernames are 3 to 20 letters, digits, underscores or hyphens.';
        }
        return null;
    }

    public static function password_problem(string $password): ?string {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Passwords need at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        return null;
    }

    public static function email_problem(string $email): ?string {
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return "That doesn't look like an email address.";
        }
        return null;
    }

    public static function normalize_email(?string $email): ?string {
        $email = $email === null ? '' : strtolower(trim($email));
        return $email === '' ? null : $email;
    }

    // --- creating and loading ---

    // Throws InvalidArgumentException with a player-readable message when
    // the username, email or password isn't acceptable or is taken.
    // $verified: whether the email counts as confirmed already (true for
    // admin-created accounts; public sign-ups confirm by email).
    public static function create(Database $db, string $username, string $password, ?string $email = null, bool $verified = false): UserAccount {
        static::ensure_table_exists($db);
        $username = trim($username);
        $email = static::normalize_email($email);
        foreach ([static::username_problem($username), static::password_problem($password),
                  $email === null ? null : static::email_problem($email)] as $problem) {
            if ($problem !== null) {
                throw new InvalidArgumentException($problem);
            }
        }
        if (static::load_by_username($db, $username) !== null) {
            throw new InvalidArgumentException('That username is taken.');
        }
        if ($email !== null && static::load_by_email($db, $email) !== null) {
            throw new InvalidArgumentException('That email address already has an account.');
        }

        $account = new UserAccount();
        $account->username = $username;
        $account->email = $email;
        $account->password_hash = password_hash($password, static::password_algorithm());
        $account->created_at = time();
        $account->email_verified_at = $verified ? $account->created_at : null;

        $stmt = $db->prepare($db->insert_query('users',
            ['username', 'email', 'password_hash', 'on_hold', 'created_at', 'email_verified_at', 'last_login_at']));
        $stmt->execute([$account->username, $account->email, $account->password_hash, 0,
            $account->created_at, $account->email_verified_at, null]);
        $account->userid = $db->mysql_do_insert_id();
        return $account;
    }

    public static function load(Database $db, int $userid): ?UserAccount {
        return static::load_where($db, 'userid = ?', [$userid]);
    }

    public static function load_by_username(Database $db, string $username): ?UserAccount {
        return static::load_where($db, 'LOWER(username) = ?', [strtolower(trim($username))]);
    }

    public static function load_by_email(Database $db, string $email): ?UserAccount {
        $email = static::normalize_email($email);
        return $email === null ? null : static::load_where($db, 'email = ?', [$email]);
    }

    private static function load_where(Database $db, string $condition, array $params): ?UserAccount {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM users WHERE $condition");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::from_row($row);
    }

    private static function from_row(array $row): UserAccount {
        $account = new UserAccount();
        $account->userid = (int) $row['userid'];
        $account->username = $row['username'];
        $account->email = $row['email'];
        $account->password_hash = $row['password_hash'];
        $account->on_hold = (int) $row['on_hold'] !== 0;
        $account->created_at = (int) $row['created_at'];
        $account->email_verified_at = $row['email_verified_at'] === null ? null : (int) $row['email_verified_at'];
        $account->last_login_at = $row['last_login_at'] === null ? null : (int) $row['last_login_at'];
        if ($row['totp_secret'] !== null) {
            try {
                $account->totp_secret = SecretBox::decrypt($row['totp_secret'], 'totp:' . $account->userid);
            } catch (SecretBoxException $e) {
                log_error("Account {$account->userid}'s authenticator secret can't be read: " . $e->getMessage());
                $account->totp_secret = $row['totp_secret'];
                $account->totp_readable = false;
            }
        }
        $account->totp_last_step = $row['totp_last_step'] === null ? null : (int) $row['totp_last_step'];
        return $account;
    }

    // Admin search: part of a username or email (any case), or an exact
    // userid when $text is all digits. Filters, all optional:
    //   'on_hold' => bool, 'unverified' => bool,
    //   'permission' => string (only holders of it), 'limit' => int.
    // Ordered by username.
    public static function search(Database $db, string $text = '', array $filters = []): array {
        static::ensure_table_exists($db);
        PermissionGrant::ensure_table_exists($db);
        $conditions = [];
        $params = [];
        $text = trim($text);
        if ($text !== '') {
            if (ctype_digit($text)) {
                $conditions[] = 'u.userid = ?';
                $params[] = (int) $text;
            } else {
                $conditions[] = '(LOWER(u.username) LIKE ? OR u.email LIKE ?)';
                $params[] = '%' . strtolower($text) . '%';
                $params[] = '%' . strtolower($text) . '%';
            }
        }
        if (!empty($filters['on_hold'])) {
            $conditions[] = 'u.on_hold <> 0';
        }
        if (!empty($filters['unverified'])) {
            $conditions[] = 'u.email_verified_at IS NULL';
        }
        if (!empty($filters['permission'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM permissions p WHERE p.userid = u.userid AND p.permission_name = ?)';
            $params[] = (string) $filters['permission'];
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $limit = max(1, (int) ($filters['limit'] ?? 200));
        $stmt = $db->prepare("SELECT u.* FROM users u $where ORDER BY LOWER(u.username) LIMIT $limit");
        $stmt->execute($params);
        return array_map(fn($row) => static::from_row($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function count_all(Database $db): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT COUNT(*) AS n FROM users");
        $stmt->execute();
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['n'];
    }

    // --- changing ---

    // Writes every field back. Throws InvalidArgumentException, changing
    // nothing, if the username or email is invalid or belongs to another
    // account.
    public function save(Database $db): void {
        static::ensure_table_exists($db);
        $this->username = trim($this->username);
        $this->email = static::normalize_email($this->email);
        $problem = static::username_problem($this->username)
            ?? ($this->email === null ? null : static::email_problem($this->email));
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
        $same_name = static::load_by_username($db, $this->username);
        if ($same_name !== null && $same_name->userid !== $this->userid) {
            throw new InvalidArgumentException('That username is taken.');
        }
        if ($this->email !== null) {
            $same_email = static::load_by_email($db, $this->email);
            if ($same_email !== null && $same_email->userid !== $this->userid) {
                throw new InvalidArgumentException('That email address already has an account.');
            }
        }
        $stored_secret = $this->totp_secret === null || !$this->totp_readable ? $this->totp_secret
            : SecretBox::encrypt($db, $this->totp_secret, 'totp:' . $this->userid);
        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, on_hold = ?,
            email_verified_at = ?, last_login_at = ?, totp_secret = ?, totp_last_step = ? WHERE userid = ?");
        $stmt->execute([$this->username, $this->email, $this->password_hash, $this->on_hold ? 1 : 0,
            $this->email_verified_at, $this->last_login_at, $stored_secret, $this->totp_last_step, $this->userid]);
    }

    // Throws InvalidArgumentException if the password is too weak.
    // Doesn't save.
    public function set_password(string $password): void {
        $problem = static::password_problem($password);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
        $this->password_hash = password_hash($password, static::password_algorithm());
    }

    // Checks a password, upgrading the stored hash if PHP's default
    // algorithm has moved on since it was made (the caller saves).
    public function password_matches(string $password): bool {
        if (!password_verify($password, $this->password_hash)) {
            return false;
        }
        if (password_needs_rehash($this->password_hash, static::password_algorithm())) {
            $this->password_hash = password_hash($password, static::password_algorithm());
        }
        return true;
    }

    public function is_verified(): bool {
        return $this->email_verified_at !== null;
    }

}

/**
 * The rights an account holds: rows of the permissions table
 * (userid, permission_name).
 */
class PermissionGrant {
    private static bool $table_verified = false;

    // Every right, with what it lets an account do (shown on the admin
    // account page).
    const ALL = [
        'MANAGE_USERS' => 'Search accounts, edit them, and assign rights',
        'SPECTATE_FIGHTS' => 'Watch any fight; search, replay and retain fights',
        'REVIEW_LOGS' => 'Review the site logs (errors, security, players\' browsers) and set their retention',
        'PLAY_AGAINST' => 'Play the monster side of a fight',
        'EDIT_ABILITY' => 'Edit abilities',
        'EDIT_ITEM' => 'Edit items',
        'EDIT_JOB' => 'Edit jobs',
        'EDIT_MONSTER' => 'Edit monsters',
        'EDIT_PERSONALITY' => 'Edit personalities',
        'RESET_DB' => 'Reset, import and export the database; regenerate characters',
    ];

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS permissions",
            <<<QUERY
CREATE TABLE IF NOT EXISTS permissions (
  userid int NOT NULL,
  permission_name varchar(64) NOT NULL,
  PRIMARY KEY (userid, permission_name)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public static function has(Database $db, int $userid, string $permission): bool {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT 1 FROM permissions WHERE userid = ? AND permission_name = ?");
        $stmt->execute([$userid, $permission]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    // Sorted by name.
    public static function list_for(Database $db, int $userid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT permission_name FROM permissions WHERE userid = ? ORDER BY permission_name");
        $stmt->execute([$userid]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'permission_name');
    }

    public static function holders(Database $db, string $permission): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT userid FROM permissions WHERE permission_name = ? ORDER BY userid");
        $stmt->execute([$permission]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'userid'));
    }

    public static function grant(Database $db, int $userid, string $permission): void {
        static::ensure_table_exists($db);
        if (!array_key_exists($permission, self::ALL)) {
            throw new InvalidArgumentException("Unknown right $permission.");
        }
        $stmt = $db->prepare($db->upsert_query('permissions', ['userid', 'permission_name'], ['userid', 'permission_name']));
        $stmt->execute([$userid, $permission]);
    }

    public static function revoke(Database $db, int $userid, string $permission): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM permissions WHERE userid = ? AND permission_name = ?");
        $stmt->execute([$userid, $permission]);
    }
}

// Two-factor sign-in builds on accounts; loaded with them so every
// account table is known to ensure_all_databases_exist().
require_once INCLUDE_GAME . 'two_factor.php';
