<?php

require_once __DIR__ . '/../SoftAuthenticator.php';

/**
 * Covers two-factor sign-in: TOTP codes (RFC 6238's own test vectors),
 * security keys (WebAuthn, with a software authenticator), recovery
 * codes, and every change being confirmed from an emailed link
 * (TwoFactorChange). Links are built from APP_URL; mail goes to a
 * MemoryTransport.
 */
trait TwoFactorPortabilityTests {

    // RFC 6238's SHA-1 test key, "12345678901234567890", in base32.
    const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private MemoryTransport $mailbox;
    private ?string $previous_app_url = null;

    private function changes(): TwoFactorChange {
        $this->reset_tables(UserAccount::class, PermissionGrant::class, RecoveryCode::class, RateLimit::class,
            AccountToken::class, SecurityKey::class, AppSetting::class);
        $this->previous_app_url = getenv('APP_URL') === false ? null : getenv('APP_URL');
        putenv('APP_URL=https://game.example.com');
        $_SESSION = [];
        $this->mailbox = new MemoryTransport();
        return new TwoFactorChange($this->db, new Mailer($this->mailbox, 'noreply@example.com'));
    }

    protected function tearDown(): void {
        putenv($this->previous_app_url === null ? 'APP_URL' : 'APP_URL=' . $this->previous_app_url);
        parent::tearDown();
    }

    private function alice(): UserAccount {
        return UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);
    }

    private function emailed_link_token(): string {
        $mail = end($this->mailbox->sent);
        $this->assertNotFalse($mail, 'a confirmation email was sent');
        $this->assertSame('alice@example.com', $mail->to);
        $this->assertTrue((bool) preg_match('#https://game\.example\.com/two_factor_change\.php\?token=([0-9a-f]{64})#', $mail->text, $match));
        return $match[1];
    }

    private function reload(UserAccount $account): UserAccount {
        return UserAccount::load($this->db, $account->userid);
    }

    // Adds and confirms an authenticator app showing RFC_SECRET's codes.
    private function with_app(TwoFactorChange $changes, UserAccount $account): ?array {
        $this->assertNull($changes->request_add_app($account, self::RFC_SECRET, Totp::code(self::RFC_SECRET, Totp::step())));
        return $changes->confirm($this->emailed_link_token())['recovery_codes'];
    }

    // Registers and confirms a software security key.
    private function with_key(TwoFactorChange $changes, UserAccount $account, string $name = 'YubiKey'): SoftAuthenticator {
        $authenticator = new SoftAuthenticator();
        $request = WebAuthnService::for_site()->registration_options($account->userid, $account->username, []);
        $this->assertNull($changes->request_add_key($account, $authenticator->register($request['options']), $request['challenge'], $name));
        $changes->confirm($this->emailed_link_token());
        return $authenticator;
    }

    private function key_answer(UserAccount $account, SoftAuthenticator $authenticator, string $purpose): array {
        return $authenticator->sign(TwoFactor::key_request($this->db, $account, $purpose));
    }

    // --- TOTP ---

    public function test_codes_match_the_rfc_6238_test_vectors() {
        // The RFC's 8-digit values, last six digits.
        $this->assertSame('287082', Totp::code(self::RFC_SECRET, Totp::step(59)));
        $this->assertSame('081804', Totp::code(self::RFC_SECRET, Totp::step(1111111109)));
        $this->assertSame('050471', Totp::code(self::RFC_SECRET, Totp::step(1111111111)));
        $this->assertSame('005924', Totp::code(self::RFC_SECRET, Totp::step(1234567890)));
    }

    public function test_base32_round_trips() {
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32_decode(Totp::base32_encode($bytes)));
        $this->assertSame('12345678901234567890', Totp::base32_decode(self::RFC_SECRET));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::generate_secret());
    }

    public function test_a_code_from_one_step_either_side_still_matches() {
        $now = 1_000_000_000;
        $step = Totp::step($now);
        foreach ([-1, 0, 1] as $offset) {
            $this->assertSame($step + $offset, Totp::matching_step(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $offset), $now));
        }
        $this->assertNull(Totp::matching_step(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 2), $now));
        $this->assertNull(Totp::matching_step(self::RFC_SECRET, '12345', $now));
    }

    public function test_the_address_the_app_scans() {
        $this->assertSame('otpauth://totp/DragonStar:Alice?secret=' . self::RFC_SECRET . '&issuer=DragonStar&algorithm=SHA1&digits=6&period=30',
            Totp::uri(self::RFC_SECRET, 'Alice'));
    }

    // --- adding an authenticator app: tested, then confirmed by email ---

    public function test_adding_an_app_needs_its_code_first() {
        $changes = $this->changes();
        $account = $this->alice();

        $this->assertNotNull($changes->request_add_app($account, self::RFC_SECRET, '000000'));
        $this->assertSame([], $this->mailbox->sent);
    }

    public function test_an_app_is_only_added_once_the_emailed_link_is_confirmed() {
        $changes = $this->changes();
        $account = $this->alice();

        $this->assertNull($changes->request_add_app($account, self::RFC_SECRET, Totp::code(self::RFC_SECRET, Totp::step())));
        $token = $this->emailed_link_token();
        $this->assertFalse(TwoFactor::is_enabled($this->db, $this->reload($account)), 'not until the link is followed');
        $this->assertSame(['add an authenticator app for sign-in codes'], $changes->pending($account));
        $this->assertSame(['username' => 'Alice', 'change' => 'add an authenticator app for sign-in codes'], $changes->describe_link($token));

        $result = $changes->confirm($token);

        $this->assertTrue(TwoFactor::has_app($this->reload($account)));
        $this->assertCount(RecoveryCode::COUNT, $result['recovery_codes'], 'the first factor comes with recovery codes');
        $this->assertNull($changes->confirm($token), 'a link works once');
        $this->assertSame([], $changes->pending($account));
    }

    public function test_the_code_used_to_test_the_app_is_spent() {
        $changes = $this->changes();
        $account = $this->alice();
        $code = Totp::code(self::RFC_SECRET, Totp::step());
        $changes->request_add_app($account, self::RFC_SECRET, $code);
        $changes->confirm($this->emailed_link_token());

        $this->assertSame('wrong', TwoFactor::check($this->db, $this->reload($account), $code));
    }

    public function test_cancelling_from_the_link_changes_nothing() {
        $changes = $this->changes();
        $account = $this->alice();
        $changes->request_add_app($account, self::RFC_SECRET, Totp::code(self::RFC_SECRET, Totp::step()));
        $token = $this->emailed_link_token();

        $this->assertTrue($changes->cancel($token));

        $this->assertNull($changes->confirm($token));
        $this->assertFalse(TwoFactor::is_enabled($this->db, $this->reload($account)));
    }

    public function test_an_account_without_email_cannot_change_two_factor() {
        $changes = $this->changes();
        $account = UserAccount::create($this->db, 'Old', 'correct horse', null, verified: true);

        $this->assertNotNull($changes->request_add_app($account, self::RFC_SECRET, Totp::code(self::RFC_SECRET, Totp::step())));
        $this->assertSame([], $this->mailbox->sent);
    }

    public function test_nothing_is_left_waiting_when_the_email_cannot_be_sent() {
        $this->changes();
        $account = $this->alice();
        $failing = new class implements MailTransport {
            public function send(OutgoingMail $mail, string $from, string $from_name): void {
                throw new MailException('server down');
            }
        };
        $changes = new TwoFactorChange($this->db, new Mailer($failing, 'noreply@example.com'));

        $this->assertNotNull($changes->request_add_app($account, self::RFC_SECRET, Totp::code(self::RFC_SECRET, Totp::step())));
        $this->assertSame([], $changes->pending($account));
    }

    // --- security keys ---

    public function test_a_security_key_is_only_usable_once_the_emailed_link_is_confirmed() {
        $changes = $this->changes();
        $account = $this->alice();
        $authenticator = new SoftAuthenticator();
        $request = WebAuthnService::for_site()->registration_options($account->userid, $account->username, []);

        $this->assertNull($changes->request_add_key($account, $authenticator->register($request['options']), $request['challenge'], 'YubiKey'));
        $this->assertSame([], SecurityKey::load_for($this->db, $account->userid), 'pending');
        $this->assertFalse(TwoFactor::is_enabled($this->db, $account));
        $this->assertSame(['add the security key "YubiKey"'], $changes->pending($account));

        $result = $changes->confirm($this->emailed_link_token());

        $this->assertCount(1, SecurityKey::load_for($this->db, $account->userid));
        $this->assertCount(RecoveryCode::COUNT, $result['recovery_codes']);
        $this->assertSame('ok', TwoFactor::verify($this->db, $account, $this->key_answer($account, $authenticator, 'login'), 'login'));
    }

    public function test_a_registration_for_another_challenge_is_refused() {
        $changes = $this->changes();
        $account = $this->alice();
        $authenticator = new SoftAuthenticator();
        $request = WebAuthnService::for_site()->registration_options($account->userid, $account->username, []);

        $this->assertNotNull($changes->request_add_key($account, $authenticator->register($request['options']), random_bytes(32), 'YubiKey'));
        $this->assertSame([], $this->mailbox->sent);
    }

    public function test_a_key_answer_works_once_and_only_for_its_own_challenge() {
        $changes = $this->changes();
        $account = $this->alice();
        $authenticator = $this->with_key($changes, $account);

        $answer = $this->key_answer($account, $authenticator, 'login');
        $this->assertSame('ok', TwoFactor::verify($this->db, $account, $answer, 'login'));
        $this->assertSame('wrong', TwoFactor::verify($this->db, $account, $answer, 'login'), 'the challenge is used up');

        $options = TwoFactor::key_request($this->db, $account, 'login');
        $wrong = $authenticator->sign($options, SoftAuthenticator::b64url(random_bytes(32)));
        $this->assertSame('wrong', TwoFactor::verify($this->db, $account, $wrong, 'login'));

        $this->assertSame('wrong', TwoFactor::verify($this->db, $account, (new SoftAuthenticator())->sign(TwoFactor::key_request($this->db, $account, 'login')), 'login'),
            "someone else's key");
    }

    public function test_an_answer_to_an_earlier_page_load_still_works() {
        // The same page loaded twice (another tab, a reload) starts two
        // challenges; answering the first must still count.
        $changes = $this->changes();
        $account = $this->alice();
        $authenticator = $this->with_key($changes, $account);

        $first = TwoFactor::key_request($this->db, $account, 'login');
        TwoFactor::key_request($this->db, $account, 'login');

        $this->assertSame('ok', TwoFactor::verify($this->db, $account, $authenticator->sign($first), 'login'));
    }

    public function test_using_a_key_records_its_counter() {
        $changes = $this->changes();
        $account = $this->alice();
        $authenticator = $this->with_key($changes, $account);

        TwoFactor::verify($this->db, $account, $this->key_answer($account, $authenticator, 'login'), 'login');

        $key = SecurityKey::load_for($this->db, $account->userid)[0];
        $this->assertSame($authenticator->counter, $key->sign_count);
        $this->assertNotNull($key->last_used_at);
    }

    public function test_an_app_and_keys_together_with_one_set_of_recovery_codes() {
        $changes = $this->changes();
        $account = $this->alice();
        $codes = $this->with_app($changes, $account);
        $this->with_key($changes, $account, 'First');
        $second = $this->with_key($changes, $account, 'Second');

        $account = $this->reload($account);
        $this->assertCount(2, SecurityKey::load_for($this->db, $account->userid));
        $this->assertSame('ok', TwoFactor::verify($this->db, $account, $this->key_answer($account, $second, 'login'), 'login'));
        $this->assertSame('ok', TwoFactor::check($this->db, $account, Totp::code(self::RFC_SECRET, Totp::step() + 1)));
        $this->assertSame('ok', TwoFactor::check($this->db, $account, $codes[0]), 'the first codes still work');
    }

    // --- removing, and new recovery codes ---

    public function test_removing_a_key_waits_for_the_link() {
        $changes = $this->changes();
        $account = $this->alice();
        $this->with_app($changes, $account);
        $this->with_key($changes, $account);
        $key = SecurityKey::load_for($this->db, $account->userid)[0];

        $this->assertNull($changes->request_remove_key($account, $key->keyid));
        $this->assertCount(1, SecurityKey::load_for($this->db, $account->userid));
        $changes->confirm($this->emailed_link_token());

        $this->assertSame([], SecurityKey::load_for($this->db, $account->userid));
        $this->assertGreaterThan(0, RecoveryCode::remaining($this->db, $account->userid), 'the app is still on');
    }

    public function test_removing_the_last_factor_turns_it_all_off() {
        $changes = $this->changes();
        $account = $this->alice();
        $this->with_app($changes, $this->reload($account));

        $this->assertNull($changes->request_remove_app($this->reload($account)));
        $this->assertTrue(TwoFactor::has_app($this->reload($account)), 'not until the link is followed');
        $changes->confirm($this->emailed_link_token());

        $this->assertFalse(TwoFactor::is_enabled($this->db, $this->reload($account)));
        $this->assertSame(0, RecoveryCode::remaining($this->db, $account->userid));
    }

    public function test_only_the_accounts_own_keys_can_be_removed() {
        $changes = $this->changes();
        $account = $this->alice();
        $bob = UserAccount::create($this->db, 'Bob', 'correct horse', 'bob@example.com', verified: true);
        $authenticator = new SoftAuthenticator();
        $request = WebAuthnService::for_site()->registration_options($bob->userid, 'Bob', []);
        $registered = WebAuthnService::for_site()->finish_registration($authenticator->register($request['options']), $request['challenge']);
        $bobs = SecurityKey::add_pending($this->db, $bob->userid, $registered['credential_id'], $registered['public_key'], 0, 'Bob key');
        $bobs->activate($this->db);

        $this->assertNotNull($changes->request_remove_key($account, $bobs->keyid));
    }

    public function test_new_recovery_codes_replace_the_old_ones_once_confirmed() {
        $changes = $this->changes();
        $account = $this->alice();
        $old = $this->with_app($changes, $account);
        $account = $this->reload($account);

        $this->assertNull($changes->request_new_recovery_codes($account));
        $this->assertSame('ok', TwoFactor::check($this->db, $account, $old[0]), 'still the old ones until confirmed');
        $new = $changes->confirm($this->emailed_link_token())['recovery_codes'];

        $this->assertCount(RecoveryCode::COUNT, $new);
        $this->assertSame('wrong', TwoFactor::check($this->db, $account, $old[1]));
        $this->assertSame('ok', TwoFactor::check($this->db, $account, $new[0]));
    }

    public function test_a_newer_request_replaces_an_older_one() {
        $changes = $this->changes();
        $account = $this->alice();
        $authenticator = new SoftAuthenticator();
        $request = WebAuthnService::for_site()->registration_options($account->userid, $account->username, []);
        $this->assertNull($changes->request_add_key($account, $authenticator->register($request['options']), $request['challenge'], 'First try'));
        $first = $this->emailed_link_token();
        $request = WebAuthnService::for_site()->registration_options($account->userid, $account->username, []);
        $this->assertNull($changes->request_add_key($account, (new SoftAuthenticator())->register($request['options']), $request['challenge'], 'Second try'));

        $this->assertNull($changes->confirm($first));
        $this->assertSame(['add the security key "Second try"'], $changes->pending($account));
        $this->assertCount(1, SecurityKey::load_for($this->db, $account->userid, include_pending: true), 'the first pending key is gone');
    }

    // --- codes ---

    public function test_a_code_works_once() {
        $changes = $this->changes();
        $account = $this->alice();
        $this->with_app($changes, $account);
        $account = $this->reload($account);
        $code = Totp::code(self::RFC_SECRET, Totp::step() + 1);

        $this->assertSame('ok', TwoFactor::verify($this->db, $account, ['code' => $code], 'login'));
        $this->assertSame('wrong', TwoFactor::verify($this->db, $account, ['code' => $code], 'login'));
    }

    public function test_recovery_codes_work_once_each_in_any_case() {
        $changes = $this->changes();
        $account = $this->alice();
        $codes = $this->with_key($changes, $account) ? RecoveryCode::generate($this->db, $account->userid) : [];

        $this->assertSame('ok', TwoFactor::check($this->db, $account, strtoupper($codes[0])));
        $this->assertSame('wrong', TwoFactor::check($this->db, $account, $codes[0]));
        $this->assertSame('ok', TwoFactor::check($this->db, $account, str_replace('-', '', $codes[1])));
    }

    public function test_too_many_tries_are_refused_even_when_right() {
        $changes = $this->changes();
        $account = $this->alice();
        $this->with_app($changes, $account);
        $account = $this->reload($account);
        for ($i = 0; $i < TwoFactor::ATTEMPTS; $i++) {
            TwoFactor::check($this->db, $account, '000000');
        }
        $this->assertSame('too_many', TwoFactor::check($this->db, $account, Totp::code(self::RFC_SECRET, Totp::step() + 1)));
    }

    // --- admins ---

    public function test_an_admin_can_remove_every_factor_for_a_player() {
        $changes = $this->changes();
        $account = $this->alice();
        $this->with_app($changes, $account);
        $this->with_key($changes, $account);
        $account = $this->reload($account);
        $boss = AccountAdmin::create($this->db, 'Boss', 'correct horse', null, ['MANAGE_USERS']);

        $view = AccountAdmin::view($this->db, $account);
        $this->assertTrue($view['two_factor']);
        $this->assertSame(1, $view['security_keys']);
        $this->assertSame([], AccountAdmin::update($this->db, $boss->userid, $account, ['two_factor_off' => true]));

        $this->assertFalse(TwoFactor::is_enabled($this->db, $this->reload($account)));
        $this->assertSame([], SecurityKey::load_for($this->db, $account->userid));
        $this->assertSame(0, RecoveryCode::remaining($this->db, $account->userid));
    }
}
