<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'site.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

/**
 * A security key or passkey (FIDO2 / WebAuthn) registered to an account
 * as a second sign-in factor: a row of the security_keys table. An
 * account may have several, alongside an authenticator app.
 *
 * A newly registered key is inactive until the account's owner confirms
 * it from the link emailed to them (see TwoFactorChange).
 *
 * credential_id is the authenticator's id for the key, base64url
 * encoded; public_key is its public key (PEM); sign_count is the
 * authenticator's signature counter, which should only ever go up --
 * one going backwards suggests a cloned key.
 */
class SecurityKey {
    private static bool $table_verified = false;

    public ?int $keyid = null;
    public int $userid;
    public string $credential_id;
    public string $public_key;
    public int $sign_count = 0;
    public string $name;
    public int $created_at;
    public ?int $last_used_at = null;
    public bool $active = false;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $id_column = $db->auto_increment_primary_key('keyid');
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS security_keys",
            <<<QUERY
CREATE TABLE IF NOT EXISTS security_keys (
  $id_column,
  userid int NOT NULL,
  credential_id varchar(255) NOT NULL UNIQUE,
  public_key text NOT NULL,
  sign_count int NOT NULL DEFAULT 0,
  name varchar(64) NOT NULL,
  created_at int NOT NULL,
  last_used_at int NULL,
  active int NOT NULL DEFAULT 0
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Stores a newly registered key, inactive.
    public static function add_pending(Database $db, int $userid, string $credential_id_binary, string $public_key, int $sign_count, string $name): SecurityKey {
        static::ensure_table_exists($db);
        $key = new SecurityKey();
        $key->userid = $userid;
        $key->credential_id = self::base64url($credential_id_binary);
        $key->public_key = $public_key;
        $key->sign_count = $sign_count;
        $key->name = mb_substr(trim($name) === '' ? 'Security key' : trim($name), 0, 64);
        $key->created_at = time();
        $stmt = $db->prepare($db->insert_query('security_keys',
            ['userid', 'credential_id', 'public_key', 'sign_count', 'name', 'created_at', 'last_used_at', 'active']));
        $stmt->execute([$key->userid, $key->credential_id, $key->public_key, $key->sign_count, $key->name, $key->created_at, null, 0]);
        $key->keyid = $db->mysql_do_insert_id();
        return $key;
    }

    public static function load(Database $db, int $keyid): ?SecurityKey {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM security_keys WHERE keyid = ?");
        $stmt->execute([$keyid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::from_row($row);
    }

    // The account's keys, oldest first; active ones only unless asked.
    public static function load_for(Database $db, int $userid, bool $include_pending = false): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM security_keys WHERE userid = ?" . ($include_pending ? '' : ' AND active <> 0') . " ORDER BY keyid");
        $stmt->execute([$userid]);
        return array_map(fn($row) => static::from_row($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function activate(Database $db): void {
        $this->active = true;
        $db->prepare("UPDATE security_keys SET active = 1 WHERE keyid = ?")->execute([$this->keyid]);
    }

    public function record_use(Database $db, int $sign_count): void {
        $this->sign_count = $sign_count;
        $this->last_used_at = time();
        $db->prepare("UPDATE security_keys SET sign_count = ?, last_used_at = ? WHERE keyid = ?")
            ->execute([$this->sign_count, $this->last_used_at, $this->keyid]);
    }

    public function delete(Database $db): void {
        $db->prepare("DELETE FROM security_keys WHERE keyid = ?")->execute([$this->keyid]);
    }

    public static function delete_all_for(Database $db, int $userid): void {
        static::ensure_table_exists($db);
        $db->prepare("DELETE FROM security_keys WHERE userid = ?")->execute([$userid]);
    }

    // Registrations never confirmed from their email, older than a day.
    public static function purge_stale_pending(Database $db): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM security_keys WHERE active = 0 AND created_at < ?");
        $stmt->execute([time() - 86400]);
        return $stmt->rowCount();
    }

    public function credential_id_binary(): string {
        return self::base64url_decode($this->credential_id);
    }

    public static function base64url(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64url_decode(string $text): string {
        return (string) base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4));
    }

    private static function from_row(array $row): SecurityKey {
        $key = new SecurityKey();
        $key->keyid = (int) $row['keyid'];
        $key->userid = (int) $row['userid'];
        $key->credential_id = $row['credential_id'];
        $key->public_key = $row['public_key'];
        $key->sign_count = (int) $row['sign_count'];
        $key->name = $row['name'];
        $key->created_at = (int) $row['created_at'];
        $key->last_used_at = $row['last_used_at'] === null ? null : (int) $row['last_used_at'];
        $key->active = (int) $row['active'] !== 0;
        return $key;
    }
}

/**
 * The two WebAuthn exchanges with the browser, on lbuchs/webauthn:
 *
 *   registering a key:  registration_options() -> the browser's
 *     navigator.credentials.create() -> finish_registration()
 *   using a key:        assertion_options() -> navigator.credentials.get()
 *     -> verify_assertion()
 *
 * Options go to the page as JSON with binary values base64url-encoded
 * (assets/webauthn.js decodes them); the browser's answer comes back as
 * form fields, also base64url. The challenge each exchange starts with
 * is kept by the caller (in the session) and handed back to finish it.
 *
 * The relying party is the site's host name, from APP_URL: a key only
 * works on the site it was registered with. Browsers allow WebAuthn only
 * over HTTPS, or on localhost.
 */
class WebAuthnService {
    const TIMEOUT_SECONDS = 120;

    private WebAuthn $webauthn;

    public function __construct(public readonly string $rp_id, string $rp_name = 'DragonStar') {
        $this->webauthn = new WebAuthn($rp_name, $rp_id, null, true);
    }

    public static function for_site(): WebAuthnService {
        $host = parse_url(app_url(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('APP_URL has no host name for security keys to use.');
        }
        return new WebAuthnService($host);
    }

    // ['options' => JSON-ready array, 'challenge' => binary].
    public function registration_options(int $userid, string $username, array $existing_keys): array {
        $args = $this->webauthn->getCreateArgs(
            pack('J', $userid), $username, $username, self::TIMEOUT_SECONDS,
            false, 'discouraged', null,
            array_map(fn(SecurityKey $key) => $key->credential_id_binary(), $existing_keys),
        );
        return ['options' => json_decode(json_encode($args), true), 'challenge' => $this->webauthn->getChallenge()->getBinaryString()];
    }

    // Checks the browser's answer to registration_options(). Returns
    // ['credential_id' => binary, 'public_key' => PEM, 'sign_count' => int];
    // throws RuntimeException with a readable reason.
    public function finish_registration(array $post, string $challenge): array {
        try {
            $data = $this->webauthn->processCreate(
                self::field($post, 'webauthn_client_data'), self::field($post, 'webauthn_attestation'),
                $challenge, false, true, false,
            );
        } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
            throw new RuntimeException("The security key couldn't be registered: " . $e->getMessage(), 0, $e);
        }
        return [
            'credential_id' => $data->credentialId,
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) $data->signatureCounter,
        ];
    }

    // Asks for any of $keys. ['options' => JSON-ready array, 'challenge' => binary].
    public function assertion_options(array $keys): array {
        $args = $this->webauthn->getGetArgs(
            array_map(fn(SecurityKey $key) => $key->credential_id_binary(), $keys),
            self::TIMEOUT_SECONDS, true, true, true, true, true, 'discouraged',
        );
        return ['options' => json_decode(json_encode($args), true), 'challenge' => $this->webauthn->getChallenge()->getBinaryString()];
    }

    // The key among $keys that signed the browser's answer, or null.
    // Records its use.
    public function verify_assertion(Database $db, array $keys, array $post, string $challenge): ?SecurityKey {
        $credential_id = (string) ($post['webauthn_id'] ?? '');
        foreach ($keys as $key) {
            if (!hash_equals($key->credential_id, $credential_id)) {
                continue;
            }
            try {
                $this->webauthn->processGet(
                    self::field($post, 'webauthn_client_data'), self::field($post, 'webauthn_authenticator_data'),
                    self::field($post, 'webauthn_signature'), $key->public_key, $challenge, $key->sign_count, false,
                );
            } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
                return null;
            }
            $key->record_use($db, (int) ($this->webauthn->getSignatureCounter() ?? $key->sign_count));
            return $key;
        }
        return null;
    }

    // The challenge the browser's answer says it signed (base64url
    // decoded), for finding which outstanding challenge it answers.
    public static function answered_challenge(array $post): string {
        $client_data = json_decode(SecurityKey::base64url_decode((string) ($post['webauthn_client_data'] ?? '')), true);
        return is_array($client_data) && is_string($client_data['challenge'] ?? null)
            ? SecurityKey::base64url_decode($client_data['challenge']) : '';
    }

    public static function has_assertion(array $post): bool {
        return trim((string) ($post['webauthn_id'] ?? '')) !== '';
    }

    private static function field(array $post, string $name): string {
        return SecurityKey::base64url_decode((string) ($post[$name] ?? ''));
    }
}
