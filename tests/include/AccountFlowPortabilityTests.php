<?php

require_once __DIR__ . '/../SoftAuthenticator.php';

/**
 * Covers public sign-up and email links: AccountFlow (sign up, confirm,
 * resend, forgot and reset password), AccountToken (one-time, hashed,
 * expiring links) and RateLimit. Mail goes to a MemoryTransport; links
 * are built from APP_URL.
 */
trait AccountFlowPortabilityTests {

    private MemoryTransport $outbox;
    private ?string $saved_app_url = null;

    private function flow(): AccountFlow {
        $this->reset_tables(UserAccount::class, PermissionGrant::class, AccountToken::class, RateLimit::class, RecoveryCode::class, SecurityKey::class, AppSetting::class);
        $_SESSION = [];
        $this->saved_app_url = getenv('APP_URL') === false ? null : getenv('APP_URL');
        putenv('APP_URL=https://game.example.com');
        $this->outbox = new MemoryTransport();
        return new AccountFlow($this->db, new Mailer($this->outbox, 'noreply@example.com'));
    }

    protected function tearDown(): void {
        putenv($this->saved_app_url === null ? 'APP_URL' : 'APP_URL=' . $this->saved_app_url);
        parent::tearDown();
    }

    // The token in the last email's link to $page.
    private function emailed_token(string $page): string {
        $mail = end($this->outbox->sent);
        $this->assertNotFalse($mail, 'an email was sent');
        $this->assertMatchesRegularExpression('#https://game\.example\.com/' . preg_quote($page) . '\?token=([0-9a-f]{64})#', $mail->text);
        preg_match('#\?token=([0-9a-f]{64})#', $mail->text, $match);
        return $match[1];
    }

    // --- signing up and confirming ---

    public function test_signing_up_makes_an_account_that_waits_for_its_email_to_be_confirmed() {
        $flow = $this->flow();

        $result = $flow->sign_up('Alice', 'Alice@Example.com', 'correct horse', '203.0.113.1');

        $this->assertNull($result['problem']);
        $this->assertFalse($result['mail_failed']);
        $this->assertFalse($result['account']->is_verified());
        $this->assertCount(1, $this->outbox->sent);
        $this->assertSame('alice@example.com', $this->outbox->sent[0]->to);
        $this->assertStringContainsString('Hello Alice', $this->outbox->sent[0]->text);
        $this->emailed_token('confirm_email.php');
    }

    public function test_following_the_link_confirms_the_account_once() {
        $flow = $this->flow();
        $account = $flow->sign_up('Alice', 'alice@example.com', 'correct horse', '203.0.113.1')['account'];
        $token = $this->emailed_token('confirm_email.php');

        $this->assertSame($account->userid, $flow->confirm_email($token)->userid);
        $this->assertTrue(UserAccount::load($this->db, $account->userid)->is_verified());
        $this->assertNull($flow->confirm_email($token), 'a link works once');
        $this->assertNull($flow->confirm_email('not-a-token'));
    }

    public function test_sign_up_needs_an_email_and_reports_other_problems() {
        $flow = $this->flow();
        $flow->sign_up('Alice', 'alice@example.com', 'correct horse', '203.0.113.1');

        $this->assertNotNull($flow->sign_up('Bob', '', 'correct horse', '203.0.113.1')['problem']);
        $this->assertSame('That username is taken.', $flow->sign_up('alice', 'other@example.com', 'correct horse', '203.0.113.1')['problem']);
        $this->assertSame('That email address already has an account.', $flow->sign_up('Carol', 'ALICE@example.com', 'correct horse', '203.0.113.1')['problem']);
        $this->assertNotNull($flow->sign_up('Dave', 'dave@example.com', 'short', '203.0.113.1')['problem']);
        $this->assertSame(1, UserAccount::count_all($this->db));
    }

    public function test_sign_ups_are_limited_per_address() {
        $flow = $this->flow();
        for ($i = 0; $i < AccountFlow::SIGNUPS_PER_IP; $i++) {
            $this->assertNull($flow->sign_up("Player$i", "p$i@example.com", 'correct horse', '203.0.113.1')['problem']);
        }

        $this->assertStringContainsString('Too many', $flow->sign_up('OneMore', 'more@example.com', 'correct horse', '203.0.113.1')['problem']);
        $this->assertNull($flow->sign_up('Elsewhere', 'else@example.com', 'correct horse', '198.51.100.7')['problem']);
    }

    public function test_the_account_exists_even_if_the_email_cannot_be_sent() {
        $this->reset_tables(UserAccount::class, PermissionGrant::class, AccountToken::class, RateLimit::class);
        putenv('APP_URL=https://game.example.com');
        $failing = new class implements MailTransport {
            public function send(OutgoingMail $mail, string $from, string $from_name): void {
                throw new MailException('server down');
            }
        };
        $flow = new AccountFlow($this->db, new Mailer($failing, 'noreply@example.com'));

        $result = $flow->sign_up('Alice', 'alice@example.com', 'correct horse', '203.0.113.1');

        $this->assertTrue($result['mail_failed']);
        $this->assertNotNull(UserAccount::load_by_username($this->db, 'Alice'));
    }

    public function test_resending_the_confirmation_replaces_the_old_link() {
        $flow = $this->flow();
        $flow->sign_up('Alice', 'alice@example.com', 'correct horse', '203.0.113.1');
        $old = $this->emailed_token('confirm_email.php');

        $this->assertTrue($flow->resend_confirmation('alice@example.com', '203.0.113.1'));
        $new = $this->emailed_token('confirm_email.php');

        $this->assertNotSame($old, $new);
        $this->assertNull($flow->confirm_email($old));
        $this->assertNotNull($flow->confirm_email($new));
    }

    public function test_resending_does_nothing_for_unknown_or_confirmed_addresses() {
        $flow = $this->flow();
        UserAccount::create($this->db, 'Done', 'correct horse', 'done@example.com', verified: true);

        $this->assertTrue($flow->resend_confirmation('nobody@example.com', '203.0.113.1'));
        $this->assertTrue($flow->resend_confirmation('done@example.com', '203.0.113.1'));
        $this->assertSame([], $this->outbox->sent);
    }

    // --- password reset ---

    public function test_resetting_a_forgotten_password() {
        $flow = $this->flow();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);

        $this->assertTrue($flow->request_password_reset('ALICE@example.com', '203.0.113.1'));
        $token = $this->emailed_token('reset_password.php');
        $this->assertSame($account->userid, $flow->reset_link_account($token)->userid);

        $this->assertNull($flow->reset_password($token, 'brand new horse'));
        $this->assertTrue(UserAccount::load($this->db, $account->userid)->password_matches('brand new horse'));
        $this->assertNotNull($flow->reset_password($token, 'another horse'), 'a link works once');
        $this->assertNull($flow->reset_link_account($token));
    }

    public function test_a_weak_new_password_leaves_the_link_usable() {
        $flow = $this->flow();
        UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);
        $flow->request_password_reset('alice@example.com', '203.0.113.1');
        $token = $this->emailed_token('reset_password.php');

        $this->assertNotNull($flow->reset_password($token, 'short'));
        $this->assertNull($flow->reset_password($token, 'brand new horse'));
    }

    public function test_resetting_the_password_confirms_the_email_too() {
        $flow = $this->flow();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com');
        $flow->request_password_reset('alice@example.com', '203.0.113.1');

        $flow->reset_password($this->emailed_token('reset_password.php'), 'brand new horse');

        $this->assertTrue(UserAccount::load($this->db, $account->userid)->is_verified());
    }

    public function test_asking_to_reset_an_unknown_address_sends_nothing_but_answers_the_same() {
        $flow = $this->flow();
        $this->assertTrue($flow->request_password_reset('nobody@example.com', '203.0.113.1'));
        $this->assertSame([], $this->outbox->sent);
    }

    public function test_reset_emails_are_limited_per_address() {
        $flow = $this->flow();
        UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);
        for ($i = 0; $i < AccountFlow::EMAILS_PER_ADDRESS; $i++) {
            $this->assertTrue($flow->request_password_reset('alice@example.com', "203.0.113.$i"));
        }

        $this->assertFalse($flow->request_password_reset('alice@example.com', '198.51.100.7'));
        $this->assertCount(AccountFlow::EMAILS_PER_ADDRESS, $this->outbox->sent);
    }

    // --- tokens ---

    public function test_tokens_are_stored_only_as_hashes() {
        $this->flow();
        $token = AccountToken::issue($this->db, 1, AccountToken::RESET, 60);

        $stmt = $this->db->prepare("SELECT token_hash FROM account_tokens");
        $stmt->execute();
        $this->assertSame([hash('sha256', $token)], array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'token_hash'));
    }

    public function test_expired_tokens_do_not_work_and_are_purged() {
        $this->flow();
        $expired = AccountToken::issue($this->db, 1, AccountToken::RESET, -1);
        AccountToken::issue($this->db, 2, AccountToken::RESET, 60);

        $this->assertNull(AccountToken::peek($this->db, $expired, AccountToken::RESET));
        $this->assertNull(AccountToken::consume($this->db, $expired, AccountToken::RESET));
        AccountToken::issue($this->db, 3, AccountToken::VERIFY, -1);
        $this->assertSame(1, AccountToken::purge_expired($this->db));
    }

    public function test_a_token_only_works_for_its_purpose() {
        $this->flow();
        $token = AccountToken::issue($this->db, 1, AccountToken::VERIFY, 60);
        $this->assertNull(AccountToken::consume($this->db, $token, AccountToken::RESET));
        $this->assertSame(1, AccountToken::consume($this->db, $token, AccountToken::VERIFY));
    }

    // --- rate limits ---

    public function test_rate_limits_count_within_a_window_and_start_over_after_it() {
        $this->flow();
        $this->assertTrue(RateLimit::hit($this->db, 'b', 2, 100, now: 1000));
        $this->assertTrue(RateLimit::hit($this->db, 'b', 2, 100, now: 1050));
        $this->assertFalse(RateLimit::hit($this->db, 'b', 2, 100, now: 1099));
        $this->assertTrue(RateLimit::hit($this->db, 'b', 2, 100, now: 1100), 'a new window');
        $this->assertTrue(RateLimit::hit($this->db, 'other', 2, 100, now: 1099), 'buckets are separate');
    }

    // --- password reset with an authenticator code ---

    // An account with an authenticator app already on (set directly:
    // adding one normally goes through TwoFactorChange's emailed link).
    private function account_with_two_factor(string $name = 'Alice', ?string $email = 'alice@example.com'): array {
        $account = UserAccount::create($this->db, $name, 'correct horse', $email, verified: true);
        $secret = Totp::generate_secret();
        $account->totp_secret = $secret;
        $account->totp_last_step = Totp::step() - 1;
        $account->save($this->db);
        return [$account, $secret, RecoveryCode::generate($this->db, $account->userid)];
    }

    public function test_a_two_factor_code_leads_straight_to_the_reset_page() {
        $flow = $this->flow();
        [$account, $secret] = $this->account_with_two_factor();

        $result = $flow->reset_with_code('ALICE', Totp::code($secret, Totp::step()), '203.0.113.1');

        $this->assertNull($result['problem']);
        $this->assertSame($account->userid, $flow->reset_link_account($result['token'])->userid);
        $this->assertNull($flow->reset_password($result['token'], 'brand new horse'));
        $this->assertSame([], $this->outbox->sent, 'no email needed');
    }

    public function test_the_code_reset_works_by_email_address_and_with_a_recovery_code() {
        $flow = $this->flow();
        [, , $codes] = $this->account_with_two_factor();

        $this->assertNotNull($flow->reset_with_code('Alice@Example.com', $codes[0], '203.0.113.1')['token']);
    }

    public function test_the_code_reset_refuses_wrong_codes_and_accounts_without_two_factor() {
        $flow = $this->flow();
        $this->account_with_two_factor();
        UserAccount::create($this->db, 'Bob', 'correct horse', 'bob@example.com', verified: true);

        $this->assertNull($flow->reset_with_code('Alice', '000000', '203.0.113.1')['token']);
        $this->assertNull($flow->reset_with_code('Bob', '000000', '203.0.113.1')['token']);
        $this->assertNull($flow->reset_with_code('Nobody', '000000', '203.0.113.1')['token']);
    }

    // --- changing the email address ---

    public function test_an_email_change_is_approved_from_the_old_address_then_confirmed_at_the_new_one() {
        $flow = $this->flow();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse', 'old@example.com', verified: true);

        $this->assertSame('approval_sent', $flow->request_email_change($account, 'New@Example.com', [], '203.0.113.1')['status']);
        $this->assertSame('old@example.com', end($this->outbox->sent)->to);
        $this->assertStringContainsString('new@example.com', end($this->outbox->sent)->text);
        $this->assertSame('new@example.com', $flow->pending_email_change($account));
        $approve = preg_replace('/.*\?approve=([0-9a-f]{64}).*/s', '$1', end($this->outbox->sent)->text);
        $this->assertSame('old@example.com', UserAccount::load($this->db, $account->userid)->email, 'nothing changes yet');

        $this->assertSame('new@example.com', $flow->approve_email_change($approve));
        $this->assertSame('new@example.com', end($this->outbox->sent)->to);
        $this->assertNull($flow->approve_email_change($approve), 'a link works once');
        $confirm = preg_replace('/.*\?confirm=([0-9a-f]{64}).*/s', '$1', end($this->outbox->sent)->text);

        $this->assertSame('new@example.com', $flow->confirm_new_email($confirm)->email);
        $this->assertSame('new@example.com', UserAccount::load($this->db, $account->userid)->email);
        $this->assertSame('old@example.com', end($this->outbox->sent)->to, 'the old address is told');
        $this->assertStringContainsString('changed to new@example.com', end($this->outbox->sent)->text);
        $this->assertNull($flow->pending_email_change($account));
    }

    public function test_with_two_factor_on_only_a_second_factor_approves_an_email_change() {
        $flow = $this->flow();
        [$account, $secret] = $this->account_with_two_factor();

        $without = $flow->request_email_change($account, 'new@example.com', [], '203.0.113.1');
        $this->assertNull($without['status'], 'no link to the old address instead');
        $this->assertSame([], $this->outbox->sent);

        $result = $flow->request_email_change($account, 'new@example.com', ['code' => Totp::code($secret, Totp::step())], '203.0.113.1');
        $this->assertSame('confirm_sent', $result['status']);
        $this->assertCount(1, $this->outbox->sent);
        $this->assertSame('new@example.com', $this->outbox->sent[0]->to);
        $this->assertStringContainsString('email_change.php?confirm=', $this->outbox->sent[0]->text);
    }

    public function test_a_recovery_code_approves_an_email_change_too() {
        $flow = $this->flow();
        [$account, , $codes] = $this->account_with_two_factor();
        $this->assertSame('confirm_sent', $flow->request_email_change($account, 'new@example.com', ['code' => $codes[0]], '203.0.113.1')['status']);
    }

    public function test_a_security_key_approves_an_email_change() {
        $flow = $this->flow();
        $this->reset_tables(SecurityKey::class);
        $account = UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);
        $authenticator = new SoftAuthenticator();
        $service = WebAuthnService::for_site();
        $request = $service->registration_options($account->userid, 'Alice', []);
        $registered = $service->finish_registration($authenticator->register($request['options']), $request['challenge']);
        SecurityKey::add_pending($this->db, $account->userid, $registered['credential_id'], $registered['public_key'], 0, 'Key')->activate($this->db);
        $_SESSION = [];

        $answer = $authenticator->sign(TwoFactor::key_request($this->db, $account, 'email_change'));

        $this->assertSame('confirm_sent', $flow->request_email_change($account, 'new@example.com', $answer, '203.0.113.1')['status']);
    }

    public function test_a_wrong_second_factor_approves_nothing() {
        $flow = $this->flow();
        [$account] = $this->account_with_two_factor();

        $result = $flow->request_email_change($account, 'new@example.com', ['code' => '000000'], '203.0.113.1');

        $this->assertNull($result['status']);
        $this->assertSame(TwoFactor::problem('wrong'), $result['problem']);
        $this->assertSame([], $this->outbox->sent);
    }

    public function test_the_password_never_approves_an_email_change() {
        $flow = $this->flow();
        $plain = UserAccount::create($this->db, 'Plain', 'correct horse', null, verified: true);

        $this->assertFalse(AccountFlow::can_change_email($plain));
        $this->assertSame(AccountFlow::CANNOT_CHANGE_EMAIL, $flow->request_email_change($plain, 'p@example.com', ['password' => 'correct horse'], '203.0.113.1')['problem']);
        $this->assertSame([], $this->outbox->sent);
    }

    public function test_an_email_change_to_a_taken_or_the_same_address_is_refused() {
        $flow = $this->flow();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@example.com', verified: true);
        UserAccount::create($this->db, 'Bob', 'correct horse', 'bob@example.com', verified: true);

        $this->assertSame('That email address already has an account.', $flow->request_email_change($account, 'BOB@example.com', [], '203.0.113.1')['problem']);
        $this->assertSame("That's already your email address.", $flow->request_email_change($account, 'alice@example.com', [], '203.0.113.1')['problem']);
        $this->assertNotNull($flow->request_email_change($account, 'nope', [], '203.0.113.1')['problem']);
    }

    public function test_an_address_taken_before_confirming_stops_the_change() {
        $flow = $this->flow();
        [$account, $secret] = $this->account_with_two_factor();
        $flow->request_email_change($account, 'new@example.com', ['code' => Totp::code($secret, Totp::step())], '203.0.113.1');
        $confirm = preg_replace('/.*\?confirm=([0-9a-f]{64}).*/s', '$1', end($this->outbox->sent)->text);
        UserAccount::create($this->db, 'Quick', 'correct horse', 'new@example.com');

        $this->assertNull($flow->confirm_new_email($confirm));
        $this->assertSame('alice@example.com', UserAccount::load($this->db, $account->userid)->email);
    }
}
