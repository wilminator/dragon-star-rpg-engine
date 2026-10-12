<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'app_setting.php';

/**
 * Encrypting the secrets the site has to be able to read back -- the
 * authenticator-app secrets, and the mail OAuth refresh token -- so a
 * copy of the database alone (a backup, an export, a leak) doesn't
 * give them away.
 *
 * The keys are never in the database. There are two key slots, files
 * holding a base64 32-byte key each, normally Docker secrets mounted at
 * /run/secrets/dragonstar_key_1 and /run/secrets/dragonstar_key_2 (the
 * folder can be changed with ENCRYPTION_KEY_DIR). An empty or missing
 * file is an empty slot. Each key is known by its fingerprint.
 *
 * Changing keys: put a new key in the slot not holding the current one
 * (utils/new_encryption_key.sh). The next login attempt re-encrypts
 * everything with it, holding logins back while it does (see
 * KeyRotation); after that the old key is retired and its slot can be
 * reused for the next change. Both keys must stay in place until the
 * re-encryption has run.
 *
 * Stored form: "enc1:<key fingerprint>:<base64(nonce . ciphertext)>",
 * XChaCha20-Poly1305 (libsodium). Each value is bound to what it
 * belongs to (the "context", like "totp:12"): moved onto another
 * account, it no longer decrypts. Values without the prefix are
 * plaintext from before encryption, and are read as they are until
 * re-encrypted.
 */
class SecretBoxException extends RuntimeException {}

final class EncryptionKeys {
    const SLOTS = ['1', '2'];
    const FILE_PREFIX = 'dragonstar_key_';

    private static ?EncryptionKeys $site = null;

    // fingerprint => ['slot' => string, 'key' => binary, 'mtime' => int]
    private array $keys = [];

    public function __construct(public readonly string $directory) {
        foreach (self::SLOTS as $slot) {
            $path = $this->path($slot);
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $text = trim((string) file_get_contents($path));
            if ($text === '') {
                continue;
            }
            $key = base64_decode($text, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new SecretBoxException("The encryption key in $path isn't a base64 32-byte key.");
            }
            $this->keys[self::fingerprint($key)] = ['slot' => $slot, 'key' => $key, 'mtime' => (int) filemtime($path)];
        }
    }

    public static function site(): EncryptionKeys {
        return self::$site ??= new EncryptionKeys(getenv('ENCRYPTION_KEY_DIR') ?: '/run/secrets');
    }

    // Tests, and after key files change within one process.
    public static function reload(?string $directory = null): void {
        self::$site = $directory === null ? null : new EncryptionKeys($directory);
    }

    public function path(string $slot): string {
        return rtrim($this->directory, '/') . '/' . self::FILE_PREFIX . $slot;
    }

    public static function fingerprint(string $key): string {
        return substr(hash('sha256', "dragonstar key fingerprint\0" . $key), 0, 16);
    }

    // fingerprint => ['slot' => string, 'mtime' => int]
    public function available(): array {
        return array_map(fn($k) => ['slot' => $k['slot'], 'mtime' => $k['mtime']], $this->keys);
    }

    public function key(string $fingerprint): ?string {
        return $this->keys[$fingerprint]['key'] ?? null;
    }
}

final class SecretBox {
    const PREFIX = 'enc1:';

    // Encrypts with the key new data should use (see KeyRotation::
    // encryption_key()).
    public static function encrypt(Database $db, string $plaintext, string $context): string {
        $fingerprint = KeyRotation::encryption_key($db);
        if ($fingerprint === null) {
            throw new SecretBoxException('No encryption key is available (see ENCRYPTION_KEY_DIR / the dragonstar_key_* secrets).');
        }
        return self::encrypt_with($fingerprint, $plaintext, $context);
    }

    public static function encrypt_with(string $fingerprint, string $plaintext, string $context): string {
        $key = EncryptionKeys::site()->key($fingerprint);
        if ($key === null) {
            throw new SecretBoxException("Encryption key $fingerprint isn't in either key slot.");
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $key);
        return self::PREFIX . $fingerprint . ':' . base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(string $stored, string $context): string {
        if (!self::is_encrypted($stored)) {
            return $stored; // from before encryption
        }
        [, $fingerprint, $payload] = explode(':', $stored, 3) + [2 => ''];
        $key = EncryptionKeys::site()->key($fingerprint);
        if ($key === null) {
            throw new SecretBoxException("Can't decrypt: key $fingerprint isn't in either key slot.");
        }
        $raw = base64_decode($payload, true);
        $nonce_length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plaintext = $raw === false || strlen($raw) <= $nonce_length ? false
            : sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonce_length), $context, substr($raw, 0, $nonce_length), $key);
        if ($plaintext === false) {
            throw new SecretBoxException('Encrypted value was altered, or belongs to something else.');
        }
        return $plaintext;
    }

    public static function is_encrypted(string $stored): bool {
        return str_starts_with($stored, self::PREFIX);
    }

    public static function key_of(string $stored): ?string {
        return self::is_encrypted($stored) ? explode(':', $stored, 3)[1] : null;
    }
}

/**
 * Moving everything encrypted onto a new key. Nothing runs on a
 * schedule: the first login attempt after a new key appears does it
 * (login.php, via run_if_needed()), and logins wait until it's done.
 *
 * State, in app_settings: which key everything is encrypted with
 * (encryption_current_key), keys already moved away from
 * (encryption_retired_keys), and the lock while moving
 * (encryption_rotation_lock).
 *
 * A key in a slot that is neither current nor retired is new. On a site
 * that has never encrypted anything, whichever key there is (the newer
 * file, if both slots are filled) becomes the first.
 */
final class KeyRotation {
    const CURRENT = 'encryption_current_key';
    const RETIRED = 'encryption_retired_keys';
    const LOCK = 'encryption_rotation_lock';
    // A lock this old is from a run that died; the next attempt takes over.
    const STALE_LOCK_SECONDS = 600;

    // Everything encrypted in table columns: the table, its key column,
    // the encrypted column, which rows (SQL), and each value's context
    // from its row.
    public static function encrypted_columns(): array {
        return [
            ['table' => 'users', 'key' => 'userid', 'column' => 'totp_secret', 'where' => 'totp_secret IS NOT NULL',
             'context' => fn(array $row) => 'totp:' . $row['userid']],
            // An authenticator app waiting for its emailed confirmation
            // (see TwoFactorChange).
            ['table' => 'account_tokens', 'key' => 'token_hash', 'column' => 'data', 'where' => "purpose = '2fa_add_app' AND data IS NOT NULL",
             'context' => fn(array $row) => 'totp-pending:' . $row['userid'], 'extra' => ', userid'],
        ];
    }
    // Encrypted app_settings values (context "setting:<name>").
    const ENCRYPTED_SETTINGS = ['mail_oauth_refresh_token'];

    public static function current_key(Database $db): ?string {
        return AppSetting::get($db, self::CURRENT);
    }

    public static function retired_keys(Database $db): array {
        $text = AppSetting::get($db, self::RETIRED) ?? '';
        return $text === '' ? [] : explode(',', $text);
    }

    // A key in the slots that everything should move to, or null.
    public static function new_key(Database $db): ?string {
        $known = array_filter([self::current_key($db), ...self::retired_keys($db)]);
        $fresh = array_diff_key(EncryptionKeys::site()->available(), array_flip($known));
        if ($fresh === []) {
            return null;
        }
        uasort($fresh, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return array_key_first($fresh);
    }

    // What new data is encrypted with: the new key once there is one
    // (so nothing written during a change is left on the old key),
    // otherwise the current key.
    public static function encryption_key(Database $db): ?string {
        return self::new_key($db) ?? self::current_key($db);
    }

    public static function needed(Database $db): bool {
        return self::new_key($db) !== null;
    }

    public static function locked(Database $db, ?int $now = null): bool {
        $lock = AppSetting::get($db, self::LOCK);
        return $lock !== null && (int) explode(':', $lock)[0] + self::STALE_LOCK_SECONDS > ($now ?? time());
    }

    // Runs the change if a new key has appeared and nobody else is
    // running it. 'none' (nothing to do), 'done', or 'busy' (another
    // request holds the lock: logins should wait).
    public static function run_if_needed(Database $db): string {
        if (!self::needed($db)) {
            return self::locked($db) ? 'busy' : 'none';
        }
        $lock = self::acquire_lock($db);
        if ($lock === null) {
            return 'busy';
        }
        try {
            // Someone may have finished it between the check and the lock.
            if (self::needed($db)) {
                self::rotate($db);
            }
        } finally {
            self::release_lock($db, $lock);
        }
        return 'done';
    }

    // Re-encrypts everything with the new key, then records it as
    // current and retires the rest. Returns how many values moved.
    public static function rotate(Database $db): int {
        $target = self::new_key($db);
        if ($target === null) {
            return 0;
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $moved = 0;
        foreach (self::encrypted_columns() as $spec) {
            ['table' => $table, 'key' => $key, 'column' => $column, 'where' => $where, 'context' => $context] = $spec;
            $extra = $spec['extra'] ?? '';
            try {
                $stmt = $db->prepare("SELECT $key, $column$extra FROM $table WHERE $where");
                $stmt->execute();
            } catch (PDOException $e) {
                continue; // a table not made yet has nothing in it
            }
            $update = $db->prepare("UPDATE $table SET $column = ? WHERE $key = ?");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $moved += self::move_value($row[$column], $context($row), $target,
                    fn($value) => $update->execute([$value, $row[$key]]));
            }
        }
        foreach (self::ENCRYPTED_SETTINGS as $name) {
            $value = AppSetting::get($db, $name);
            if ($value !== null) {
                $moved += self::move_value($value, "setting:$name", $target, fn($v) => AppSetting::set($db, $name, $v));
            }
        }

        $retired = self::retired_keys($db);
        foreach ([self::current_key($db), ...array_keys(EncryptionKeys::site()->available())] as $old) {
            if ($old !== null && $old !== $target && !in_array($old, $retired, true)) {
                $retired[] = $old;
            }
        }
        AppSetting::set($db, self::RETIRED, implode(',', array_slice($retired, -20)));
        AppSetting::set($db, self::CURRENT, $target);
        return $moved;
    }

    private static function move_value(string $stored, string $context, string $target, callable $write): int {
        if (SecretBox::key_of($stored) === $target) {
            return 0;
        }
        try {
            $write(SecretBox::encrypt_with($target, SecretBox::decrypt($stored, $context), $context));
            return 1;
        } catch (SecretBoxException $e) {
            // Its key is gone: it can't be read now or later. Left as it
            // is (the account's app will simply not work) and reported.
            log_error("Encryption key change: couldn't move $context: " . $e->getMessage());
            return 0;
        }
    }

    // --- the lock: one row, inserted, so only one request can hold it ---

    private static function acquire_lock(Database $db): ?string {
        AppSetting::ensure_table_exists($db);
        $token = time() . ':' . bin2hex(random_bytes(8));
        try {
            $db->prepare("INSERT INTO app_settings (name, value) VALUES (?, ?)")->execute([self::LOCK, $token]);
            return $token;
        } catch (PDOException $e) {
            // Held -- unless it's stale, in which case take it over, but
            // only if nobody else took it over first.
            $held = AppSetting::get($db, self::LOCK);
            if ($held === null || self::locked($db)) {
                return null;
            }
            $stmt = $db->prepare("UPDATE app_settings SET value = ? WHERE name = ? AND value = ?");
            $stmt->execute([$token, self::LOCK, $held]);
            return $stmt->rowCount() === 1 ? $token : null;
        }
    }

    private static function release_lock(Database $db, string $token): void {
        $db->prepare("DELETE FROM app_settings WHERE name = ? AND value = ?")->execute([self::LOCK, $token]);
    }

    // For admin/encryption_keys.php.
    public static function describe(Database $db): array {
        $counts = [];
        foreach (self::encrypted_columns() as $spec) {
            try {
                $stmt = $db->prepare("SELECT {$spec['column']} AS v FROM {$spec['table']} WHERE {$spec['where']}");
                $stmt->execute();
            } catch (PDOException $e) {
                continue;
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[SecretBox::key_of($row['v']) ?? 'plaintext'] = ($counts[SecretBox::key_of($row['v']) ?? 'plaintext'] ?? 0) + 1;
            }
        }
        foreach (self::ENCRYPTED_SETTINGS as $name) {
            $value = AppSetting::get($db, $name);
            if ($value !== null) {
                $counts[SecretBox::key_of($value) ?? 'plaintext'] = ($counts[SecretBox::key_of($value) ?? 'plaintext'] ?? 0) + 1;
            }
        }
        return [
            'slots' => EncryptionKeys::site()->available(),
            'current' => self::current_key($db),
            'retired' => self::retired_keys($db),
            'new' => self::new_key($db),
            'locked' => self::locked($db),
            'values_by_key' => $counts,
        ];
    }
}
