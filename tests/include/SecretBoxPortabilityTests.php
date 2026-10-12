<?php

/**
 * Covers encrypting stored secrets (SecretBox), the two key slots
 * (EncryptionKeys), and moving everything onto a new key at the first
 * login attempt after it appears, under a lock (KeyRotation). Also the
 * login attempt limits and Argon2id password hashing.
 */
trait SecretBoxPortabilityTests {

    private string $key_dir;

    private function key_slots(?string $slot1 = null, ?string $slot2 = null): void {
        $this->reset_tables(UserAccount::class, PermissionGrant::class, AppSetting::class, AccountToken::class,
            RecoveryCode::class, RateLimit::class, SecurityKey::class);
        $this->key_dir = sys_get_temp_dir() . '/dragonstar-keys-' . bin2hex(random_bytes(6));
        mkdir($this->key_dir, 0700);
        $this->put_key('1', $slot1);
        $this->put_key('2', $slot2);
    }

    // $key: raw 32 bytes, or null for an empty slot.
    private function put_key(string $slot, ?string $key, int $mtime = 0): void {
        $path = "{$this->key_dir}/dragonstar_key_$slot";
        file_put_contents($path, $key === null ? '' : base64_encode($key));
        if ($mtime > 0) {
            touch($path, $mtime);
        }
        EncryptionKeys::reload($this->key_dir);
    }

    protected function tearDown(): void {
        EncryptionKeys::reload(null);
        parent::tearDown();
    }

    private function fp(string $key): string {
        return EncryptionKeys::fingerprint($key);
    }

    private function stored_secret(int $userid): ?string {
        $stmt = $this->db->prepare("SELECT totp_secret FROM users WHERE userid = ?");
        $stmt->execute([$userid]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['totp_secret'];
    }

    private function account_with_secret(string $name, string $secret): UserAccount {
        $account = UserAccount::create($this->db, $name, 'correct horse', strtolower($name) . '@example.com', verified: true);
        $account->totp_secret = $secret;
        $account->save($this->db);
        return $account;
    }

    // --- encrypting ---

    public function test_a_value_round_trips_and_is_not_readable_as_stored() {
        $key = random_bytes(32);
        $this->key_slots($key);

        $stored = SecretBox::encrypt($this->db, 'JBSWY3DPEHPK3PXP', 'totp:7');

        $this->assertStringStartsWith('enc1:' . $this->fp($key) . ':', $stored);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $stored);
        $this->assertSame('JBSWY3DPEHPK3PXP', SecretBox::decrypt($stored, 'totp:7'));
        $this->assertNotSame($stored, SecretBox::encrypt($this->db, 'JBSWY3DPEHPK3PXP', 'totp:7'), 'a fresh nonce each time');
    }

    public function test_a_value_only_decrypts_for_what_it_belongs_to() {
        $this->key_slots(random_bytes(32));
        $stored = SecretBox::encrypt($this->db, 'secret', 'totp:7');

        $this->expectException(SecretBoxException::class);
        SecretBox::decrypt($stored, 'totp:8');
    }

    public function test_an_altered_value_is_refused() {
        $this->key_slots(random_bytes(32));
        $stored = SecretBox::encrypt($this->db, 'secret', 'totp:7');
        $raw = base64_decode(explode(':', $stored, 3)[2]);
        $raw[30] = chr(ord($raw[30]) ^ 1);

        $this->expectException(SecretBoxException::class);
        SecretBox::decrypt(implode(':', array_slice(explode(':', $stored, 3), 0, 2)) . ':' . base64_encode($raw), 'totp:7');
    }

    public function test_values_from_before_encryption_read_as_they_are() {
        $this->key_slots(random_bytes(32));
        $this->assertSame('JBSWY3DPEHPK3PXP', SecretBox::decrypt('JBSWY3DPEHPK3PXP', 'totp:7'));
    }

    public function test_without_any_key_nothing_can_be_encrypted() {
        $this->key_slots();
        $this->expectException(SecretBoxException::class);
        SecretBox::encrypt($this->db, 'secret', 'totp:7');
    }

    public function test_a_malformed_key_file_is_reported() {
        $this->key_slots();
        file_put_contents("{$this->key_dir}/dragonstar_key_2", 'not a key');
        $this->expectException(SecretBoxException::class);
        EncryptionKeys::reload($this->key_dir);
    }

    // --- stored secrets ---

    public function test_an_authenticator_secret_is_stored_encrypted_and_loaded_decrypted() {
        $this->key_slots(random_bytes(32));
        $account = $this->account_with_secret('Alice', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('enc1:', $this->stored_secret($account->userid));
        $loaded = UserAccount::load($this->db, $account->userid);
        $this->assertSame('JBSWY3DPEHPK3PXP', $loaded->totp_secret);
        $this->assertTrue($loaded->totp_readable);
    }

    public function test_a_secret_whose_key_is_gone_stays_as_it_was_and_its_codes_fail() {
        $old = random_bytes(32);
        $this->key_slots($old);
        $account = $this->account_with_secret('Alice', 'JBSWY3DPEHPK3PXP');
        $stored = $this->stored_secret($account->userid);
        $this->put_key('1', random_bytes(32));

        $loaded = UserAccount::load($this->db, $account->userid);

        $this->assertFalse($loaded->totp_readable);
        $this->assertSame('wrong', TwoFactor::check($this->db, $loaded, Totp::code('JBSWY3DPEHPK3PXP', Totp::step())));
        $loaded->last_login_at = 123;
        $loaded->save($this->db);
        $this->assertSame($stored, $this->stored_secret($account->userid), 'saved back untouched');
    }

    public function test_the_mail_refresh_token_is_stored_encrypted() {
        $this->key_slots(random_bytes(32));
        $mailbox = new OAuthMailbox($this->db, 'gmail', OAuthProvider::for('gmail'), 'game@example.com', 'id', 'secret', '',
            fn() => ['access_token' => 'at', 'refresh_token' => 'the-refresh-token']);

        $mailbox->finish_connect('code', 'https://game.example.com/admin/mail.php');

        $this->assertStringStartsWith('enc1:', AppSetting::get($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING));
        $this->assertSame('the-refresh-token', $mailbox->refresh_token());
    }

    // --- changing keys ---

    public function test_the_first_key_is_taken_up_by_the_first_login_and_old_plaintext_is_encrypted() {
        $key = random_bytes(32);
        $this->key_slots($key);
        $account = UserAccount::create($this->db, 'Old', 'correct horse', 'old@example.com', verified: true);
        $this->db->prepare("UPDATE users SET totp_secret = ? WHERE userid = ?")->execute(['JBSWY3DPEHPK3PXP', $account->userid]);

        $this->assertTrue(KeyRotation::needed($this->db));
        $this->assertSame('done', KeyRotation::run_if_needed($this->db));

        $this->assertSame($this->fp($key), KeyRotation::current_key($this->db));
        $this->assertStringStartsWith('enc1:' . $this->fp($key), $this->stored_secret($account->userid));
        $this->assertSame('JBSWY3DPEHPK3PXP', UserAccount::load($this->db, $account->userid)->totp_secret);
        $this->assertSame('none', KeyRotation::run_if_needed($this->db));
    }

    public function test_a_new_key_moves_everything_and_retires_the_old_one() {
        $a = random_bytes(32);
        $b = random_bytes(32);
        $this->key_slots($a);
        KeyRotation::run_if_needed($this->db);
        $alice = $this->account_with_secret('Alice', 'AAAAAAAAAAAAAAAA');
        $bob = $this->account_with_secret('Bob', 'BBBBBBBBBBBBBBBB');
        AppSetting::set($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING,
            SecretBox::encrypt($this->db, 'refresh', 'setting:' . OAuthMailbox::REFRESH_TOKEN_SETTING));

        $this->put_key('2', $b);
        $this->assertSame($this->fp($b), KeyRotation::new_key($this->db));
        $this->assertSame('done', KeyRotation::run_if_needed($this->db));

        foreach ([$alice, $bob] as $account) {
            $this->assertStringStartsWith('enc1:' . $this->fp($b), $this->stored_secret($account->userid));
        }
        $this->assertSame('AAAAAAAAAAAAAAAA', UserAccount::load($this->db, $alice->userid)->totp_secret);
        $this->assertStringStartsWith('enc1:' . $this->fp($b), AppSetting::get($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING));
        $this->assertSame($this->fp($b), KeyRotation::current_key($this->db));
        $this->assertSame([$this->fp($a)], KeyRotation::retired_keys($this->db));
        $this->assertFalse(KeyRotation::needed($this->db), 'the retired key left in slot 1 is not new');

        // Next change: slot 1 (retired) gets a fresh key.
        $c = random_bytes(32);
        $this->put_key('1', $c);
        KeyRotation::run_if_needed($this->db);
        $this->assertSame($this->fp($c), KeyRotation::current_key($this->db));
        $this->assertSame('BBBBBBBBBBBBBBBB', UserAccount::load($this->db, $bob->userid)->totp_secret);
    }

    public function test_writes_before_the_change_runs_already_use_the_new_key() {
        $a = random_bytes(32);
        $b = random_bytes(32);
        $this->key_slots($a);
        KeyRotation::run_if_needed($this->db);
        $this->put_key('2', $b);

        $account = $this->account_with_secret('Alice', 'AAAAAAAAAAAAAAAA');

        $this->assertStringStartsWith('enc1:' . $this->fp($b), $this->stored_secret($account->userid));
    }

    public function test_a_pending_authenticator_setup_is_encrypted_and_moved_too() {
        $a = random_bytes(32);
        $b = random_bytes(32);
        $this->key_slots($a);
        KeyRotation::run_if_needed($this->db);
        putenv('APP_URL=https://game.example.com');
        $account = UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);
        $outbox = new MemoryTransport();
        $changes = new TwoFactorChange($this->db, new Mailer($outbox, 'noreply@example.com'));
        $secret = Totp::generate_secret();
        $this->assertNull($changes->request_add_app($account, $secret, Totp::code($secret, Totp::step())));

        $stmt = $this->db->prepare("SELECT data FROM account_tokens WHERE purpose = ?");
        $stmt->execute([TwoFactorChange::ADD_APP]);
        $this->assertStringStartsWith('enc1:' . $this->fp($a), $stmt->fetch(PDO::FETCH_ASSOC)['data']);

        $this->put_key('2', $b);
        KeyRotation::run_if_needed($this->db);
        $stmt->execute([TwoFactorChange::ADD_APP]);
        $this->assertStringStartsWith('enc1:' . $this->fp($b), $stmt->fetch(PDO::FETCH_ASSOC)['data']);

        preg_match('#token=([0-9a-f]{64})#', end($outbox->sent)->text, $match);
        $changes->confirm($match[1]);
        $this->assertSame($secret, UserAccount::load($this->db, $account->userid)->totp_secret);
    }

    public function test_with_both_slots_filled_on_a_new_site_the_newer_key_is_used() {
        $older = random_bytes(32);
        $newer = random_bytes(32);
        $this->key_slots();
        $this->put_key('1', $newer, time());
        $this->put_key('2', $older, time() - 3600);

        KeyRotation::run_if_needed($this->db);

        $this->assertSame($this->fp($newer), KeyRotation::current_key($this->db));
        $this->assertFalse(KeyRotation::needed($this->db));
    }

    // --- the lock ---

    public function test_while_someone_holds_the_lock_nobody_else_changes_keys_and_logins_wait() {
        $this->key_slots(random_bytes(32));
        $this->db->prepare("INSERT INTO app_settings (name, value) VALUES (?, ?)")->execute([KeyRotation::LOCK, time() . ':other']);

        $this->assertSame('busy', KeyRotation::run_if_needed($this->db));
        $this->assertNull(KeyRotation::current_key($this->db));
        $this->assertTrue(KeyRotation::locked($this->db));
    }

    public function test_a_stale_lock_is_taken_over() {
        $this->key_slots(random_bytes(32));
        $this->db->prepare("INSERT INTO app_settings (name, value) VALUES (?, ?)")
            ->execute([KeyRotation::LOCK, (time() - KeyRotation::STALE_LOCK_SECONDS - 5) . ':dead']);

        $this->assertSame('done', KeyRotation::run_if_needed($this->db));
        $this->assertNotNull(KeyRotation::current_key($this->db));
        $this->assertNull(AppSetting::get($this->db, KeyRotation::LOCK), 'released when done');
    }

    public function test_a_finished_change_leaves_no_lock_and_nothing_waiting() {
        $this->key_slots(random_bytes(32));
        KeyRotation::run_if_needed($this->db);
        $this->assertFalse(KeyRotation::locked($this->db));
        $this->assertSame('none', KeyRotation::run_if_needed($this->db));
    }

    // --- passwords and login attempts ---

    public function test_passwords_are_hashed_with_argon2id_and_older_hashes_are_upgraded() {
        $this->key_slots(random_bytes(32));
        $account = UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);
        $this->assertStringStartsWith('$argon2id$', $account->password_hash);

        $account->password_hash = password_hash('correct horse', PASSWORD_BCRYPT);
        $this->assertTrue($account->password_matches('correct horse'));
        $this->assertStringStartsWith('$argon2id$', $account->password_hash);
    }

    public function test_login_attempts_are_limited_per_account_and_per_address() {
        $this->key_slots(random_bytes(32));
        for ($i = 0; $i < LOGIN_ATTEMPTS_PER_ACCOUNT; $i++) {
            $this->assertTrue(login_attempt_allowed($this->db, 'Alice', "203.0.113.$i"));
        }
        $this->assertFalse(login_attempt_allowed($this->db, 'ALICE', '198.51.100.1'), 'any case, any address');
        $this->assertTrue(login_attempt_allowed($this->db, 'Bob', '198.51.100.1'));

        RateLimit::clear($this->db, login_account_bucket('alice'));
        $this->assertTrue(login_attempt_allowed($this->db, 'Alice', '198.51.100.2'), 'a correct password starts the count over');

        for ($i = 0; $i < LOGIN_ATTEMPTS_PER_IP; $i++) {
            login_attempt_allowed($this->db, "player$i", '192.0.2.9');
        }
        $this->assertFalse(login_attempt_allowed($this->db, 'someone', '192.0.2.9'));
    }
}
