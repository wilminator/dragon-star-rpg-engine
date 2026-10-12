<?php

/**
 * A software security key for tests: registers ('none' attestation) and
 * signs WebAuthn challenges with its own P-256 key, producing exactly
 * the fields assets/webauthn.js would post (base64url).
 */
class SoftAuthenticator {
    public string $credential_id;
    private \OpenSSLAsymmetricKey $key;
    public int $counter = 0;

    public function __construct(private string $rp_id = 'game.example.com', private string $origin = 'https://game.example.com') {
        $this->credential_id = random_bytes(16);
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    // The form fields answering WebAuthn creation options.
    public function register(array $options): array {
        $client_data = $this->client_data('webauthn.create', $options['publicKey']['challenge']);
        $details = openssl_pkey_get_details($this->key)['ec'];
        // OpenSSL drops leading zero bytes; COSE wants the full 32 (without
        // this, about 1 key in 128 failed to register).
        $x = str_pad($details['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['y'], 32, "\0", STR_PAD_LEFT);
        $cose = self::cbor_map([1 => 2, 3 => -7, -1 => 1, -2 => ['bytes' => $x], -3 => ['bytes' => $y]]);
        $auth_data = hash('sha256', $this->rp_id, true) . chr(0x41) . pack('N', $this->counter)
            . str_repeat("\0", 16) . pack('n', strlen($this->credential_id)) . $this->credential_id . $cose;
        $attestation = self::cbor_map(['fmt' => 'none', 'attStmt' => [], 'authData' => ['bytes' => $auth_data]]);
        return [
            'webauthn_client_data' => self::b64url($client_data),
            'webauthn_attestation' => self::b64url($attestation),
        ];
    }

    // The form fields answering WebAuthn request options.
    public function sign(array $options, ?string $challenge_override = null): array {
        $this->counter++;
        $client_data = $this->client_data('webauthn.get', $challenge_override ?? $options['publicKey']['challenge']);
        $auth_data = hash('sha256', $this->rp_id, true) . chr(0x01) . pack('N', $this->counter);
        openssl_sign($auth_data . hash('sha256', $client_data, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
        return [
            'webauthn_id' => self::b64url($this->credential_id),
            'webauthn_client_data' => self::b64url($client_data),
            'webauthn_authenticator_data' => self::b64url($auth_data),
            'webauthn_signature' => self::b64url($signature),
        ];
    }

    private function client_data(string $type, string $challenge_b64url): string {
        return json_encode(['type' => $type, 'challenge' => $challenge_b64url, 'origin' => $this->origin, 'crossOrigin' => false]);
    }

    public static function b64url(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    // --- just enough CBOR ---

    private static function head(int $major, int $value): string {
        if ($value < 24) return chr(($major << 5) | $value);
        if ($value < 256) return chr(($major << 5) | 24) . chr($value);
        if ($value < 65536) return chr(($major << 5) | 25) . pack('n', $value);
        return chr(($major << 5) | 26) . pack('N', $value);
    }

    private static function cbor($value): string {
        if (is_int($value)) {
            return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
        }
        if (is_string($value)) {
            return self::head(3, strlen($value)) . $value;
        }
        if (is_array($value) && array_key_exists('bytes', $value) && count($value) === 1) {
            return self::head(2, strlen($value['bytes'])) . $value['bytes'];
        }
        return self::cbor_map($value);
    }

    private static function cbor_map(array $map): string {
        $out = self::head(5, count($map));
        foreach ($map as $key => $value) {
            $out .= self::cbor($key) . self::cbor($value);
        }
        return $out;
    }
}
