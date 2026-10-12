<?php
require_once INCLUDE_GAME . 'user_account.php';
require_once INCLUDE_GAME . 'rate_limit.php';
require_once INCLUDE_GAME . 'security_key.php';

// Two-factor sign-in. An account's second factors are an authenticator
// app (Google Authenticator, Authy, 1Password, ...: time-based one-time
// codes, RFC 6238 -- six digits, a new one every 30 seconds, SHA-1, the
// settings every app supports) and/or any number of security keys or
// passkeys (FIDO2 / WebAuthn -- see security_key.php). Either works
// wherever a second factor is asked for.
//
// With any second factor, an account also has ten one-time recovery
// codes for when the phone and keys are lost; they work wherever a code
// is asked for.
//
// Adding or removing a factor, or making new recovery codes, only
// happens once confirmed from a link emailed to the account (see
// TwoFactorChange) -- so someone who has only got into a logged-in
// session can't quietly add their own factor and use it to take over.

class Totp {
    const DIGITS = 6;
    const PERIOD = 30;
    // Codes from one step either side still count, for clock drift.
    const DRIFT_STEPS = 1;
    const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generate_secret(): string {
        return self::base32_encode(random_bytes(20));
    }

    public static function step(?int $now = null): int {
        return intdiv($now ?? time(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32_decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // The time step $code belongs to, if it's valid around $now.
    public static function matching_step(string $secret, string $code, ?int $now = null): ?int {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $current = self::step($now);
        for ($step = $current - self::DRIFT_STEPS; $step <= $current + self::DRIFT_STEPS; $step++) {
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    // What the authenticator app scans.
    public static function uri(string $secret, string $account_name, string $issuer = 'DragonStar'): string {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account_name) . '?' . http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32_encode(string $bytes): string {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32_decode(string $text): string {
        $text = strtoupper(preg_replace('/[\s=]+/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Not a base32 secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}

// Ten one-time codes per account, stored only as hashes. A row of the
// recovery_codes table.
class RecoveryCode {
    private static bool $table_verified = false;
    const COUNT = 10;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS recovery_codes",
            <<<QUERY
CREATE TABLE IF NOT EXISTS recovery_codes (
  userid int NOT NULL,
  code_hash varchar(64) NOT NULL,
  PRIMARY KEY (userid, code_hash)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Replaces the account's codes with a fresh set, returned for the
    // player to write down (they can't be shown again).
    public static function generate(Database $db, int $userid): array {
        static::delete_all($db, $userid);
        $codes = [];
        $stmt = $db->prepare($db->insert_query('recovery_codes', ['userid', 'code_hash']));
        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = strtolower(Totp::base32_encode(random_bytes(5)));
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $stmt->execute([$userid, self::hash($code)]);
            $codes[] = $code;
        }
        return $codes;
    }

    // Uses a code up; false if it isn't one of the account's.
    public static function use(Database $db, int $userid, string $code): bool {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM recovery_codes WHERE userid = ? AND code_hash = ?");
        $stmt->execute([$userid, self::hash($code)]);
        return $stmt->rowCount() === 1;
    }

    public static function remaining(Database $db, int $userid): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT COUNT(*) AS n FROM recovery_codes WHERE userid = ?");
        $stmt->execute([$userid]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['n'];
    }

    public static function delete_all(Database $db, int $userid): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM recovery_codes WHERE userid = ?");
        $stmt->execute([$userid]);
    }

    private static function hash(string $code): string {
        return hash('sha256', strtolower(preg_replace('/[^a-z2-7]/i', '', $code)));
    }
}

class TwoFactor {
    // Codes or keys an account may try per window, right or wrong, before
    // it has to wait -- guessing six digits needs a great many tries.
    const ATTEMPTS = 10;
    const ATTEMPT_WINDOW = 900;

    // Whether the account has any second factor: an authenticator app,
    // or at least one confirmed security key.
    public static function is_enabled(Database $db, UserAccount $account): bool {
        return self::has_app($account) || SecurityKey::load_for($db, $account->userid) !== [];
    }

    public static function has_app(UserAccount $account): bool {
        return $account->totp_secret !== null;
    }

    public static function has_keys(Database $db, UserAccount $account): bool {
        return SecurityKey::load_for($db, $account->userid) !== [];
    }

    // Checks a code from the app, or a recovery code (used up). A code
    // from the app works once: a code at or before the last accepted
    // time step is refused, so an overheard code can't be replayed.
    // Returns 'ok', 'wrong', or 'too_many' (over the attempt limit).
    public static function check(Database $db, UserAccount $account, string $code, ?int $now = null): string {
        if (!RateLimit::hit($db, "2fa:{$account->userid}", self::ATTEMPTS, self::ATTEMPT_WINDOW, $now)) {
            return 'too_many';
        }
        if (self::has_app($account) && $account->totp_readable) {
            $step = Totp::matching_step($account->totp_secret, $code, $now);
            if ($step !== null && $step > (int) $account->totp_last_step) {
                $account->totp_last_step = $step;
                $account->save($db);
                return 'ok';
            }
            if ($step !== null) {
                return 'wrong';
            }
        }
        if (preg_match('/^[a-z2-7]{4}-?[a-z2-7]{4}$/i', trim($code)) && RecoveryCode::use($db, $account->userid, trim($code))) {
            return 'ok';
        }
        return 'wrong';
    }

    // --- one form, either factor ---

    // Starts a security-key check for $purpose (a name for the form
    // asking, like 'login'), keeping its challenge in the session. The
    // WebAuthn options for the page, or null if the account has no keys.
    public static function key_request(Database $db, UserAccount $account, string $purpose): ?array {
        $keys = SecurityKey::load_for($db, $account->userid);
        if ($keys === []) {
            return null;
        }
        $request = WebAuthnService::for_site()->assertion_options($keys);
        self::remember_challenge($purpose, $request['challenge']);
        return $request['options'];
    }

    // Challenges are kept per purpose, a few at a time: the same page
    // loaded again (another tab, a reload, a stray request) starts a new
    // one, and the answer to an earlier one should still count.
    const CHALLENGES_KEPT = 5;
    const CHALLENGE_SECONDS = 600;

    public static function remember_challenge(string $purpose, string $challenge): void {
        $kept = array_filter($_SESSION['webauthn'][$purpose] ?? [], fn($c) => is_array($c) && $c['at'] + self::CHALLENGE_SECONDS >= time());
        $kept[] = ['challenge' => base64_encode($challenge), 'at' => time()];
        $_SESSION['webauthn'][$purpose] = array_slice(array_values($kept), -self::CHALLENGES_KEPT);
    }

    // Takes back the outstanding challenge $answered, if it is one
    // (each works once). The challenge, or '' if not.
    public static function take_challenge(string $purpose, string $answered): string {
        foreach ($_SESSION['webauthn'][$purpose] ?? [] as $index => $kept) {
            if (!is_array($kept) || $kept['at'] + self::CHALLENGE_SECONDS < time()) {
                continue;
            }
            $challenge = base64_decode($kept['challenge']);
            if ($answered !== '' && hash_equals($challenge, $answered)) {
                unset($_SESSION['webauthn'][$purpose][$index]);
                return $challenge;
            }
        }
        return '';
    }

    // Checks whichever factor a form sent: a code (field 'code', from the
    // app or a recovery code) or a security key's answer to the
    // key_request() for the same $purpose. 'ok', 'wrong' or 'too_many'.
    public static function verify(Database $db, UserAccount $account, array $post, string $purpose): string {
        $code = trim((string) ($post['code'] ?? ''));
        if ($code !== '') {
            return self::check($db, $account, $code);
        }
        if (!WebAuthnService::has_assertion($post)) {
            return 'wrong';
        }
        if (!RateLimit::hit($db, "2fa:{$account->userid}", self::ATTEMPTS, self::ATTEMPT_WINDOW)) {
            return 'too_many';
        }
        $challenge = self::take_challenge($purpose, WebAuthnService::answered_challenge($post));
        if ($challenge === '') {
            return 'wrong';
        }
        $key = WebAuthnService::for_site()->verify_assertion($db, SecurityKey::load_for($db, $account->userid), $post, $challenge);
        return $key === null ? 'wrong' : 'ok';
    }

    public static function problem(string $result): ?string {
        return match ($result) {
            'ok' => null,
            'too_many' => 'Too many tries. Please wait a few minutes and try again.',
            default => "That didn't work. Try the newest code from your app, a recovery code, or your security key.",
        };
    }

    // Everything two-factor, gone -- for an admin helping a player who
    // lost their phone, keys and recovery codes.
    public static function remove_everything(Database $db, UserAccount $account): void {
        $account->totp_secret = null;
        $account->totp_last_step = null;
        $account->save($db);
        SecurityKey::delete_all_for($db, $account->userid);
        RecoveryCode::delete_all($db, $account->userid);
    }
}

// The authenticator setup QR code, as an image address for <img src>.
function totp_qr_image(string $otpauth_uri): string {
    $options = new \chillerlan\QRCode\QROptions([
        'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
        'outputBase64' => true,
        'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M,
    ]);
    return (new \chillerlan\QRCode\QRCode($options))->render($otpauth_uri);
}
