<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once INCLUDE_GAME . 'mail.php';

/**
 * Covers include/mail.php: choosing the transport from environment
 * variables, the log transport, and the OAuth mailbox (token refresh,
 * refresh-token rotation, and the connect flow) with the provider's
 * token endpoint faked. Actually talking SMTP is PHPMailer's job and
 * isn't exercised here -- try it from admin/mail.php against mailpit.
 */
class MailSqliteTest extends SqliteTestCase {

    private array $posts = [];

    private function mailbox(array $replies, string $fixed_refresh_token = '', string $kind = 'gmail'): OAuthMailbox {
        $this->reset_tables(AppSetting::class);
        return new OAuthMailbox($this->db, $kind, OAuthProvider::for($kind, 'contoso'), 'game@example.com',
            'client-id', 'client-secret', $fixed_refresh_token,
            function (string $url, array $fields) use (&$replies) {
                $this->posts[] = [$url, $fields];
                return array_shift($replies) ?? [];
            });
    }

    // --- choosing a transport ---

    public function test_mail_is_logged_when_nothing_is_configured() {
        $mailer = Mailer::from_environment($this->db, []);
        $this->assertInstanceOf(LogTransport::class, $mailer->transport());
        $this->assertSame('DragonStar', $mailer->from_name);
    }

    public function test_an_smtp_server() {
        $mailer = Mailer::from_environment($this->db, [
            'MAIL_TRANSPORT' => 'smtp', 'MAIL_HOST' => 'mail.example.com', 'MAIL_PORT' => '465',
            'MAIL_ENCRYPTION' => 'ssl', 'MAIL_USERNAME' => 'game', 'MAIL_PASSWORD' => 'secret',
            'MAIL_FROM' => 'noreply@example.com', 'MAIL_FROM_NAME' => 'The Game',
        ]);
        $transport = $mailer->transport();
        $this->assertInstanceOf(SmtpTransport::class, $transport);
        $this->assertSame(['mail.example.com', 465, 'ssl', 'game'], [$transport->host, $transport->port, $transport->encryption, $transport->username]);
        $this->assertNull($transport->oauth_mailbox());
        $this->assertSame(['noreply@example.com', 'The Game'], [$mailer->from, $mailer->from_name]);
    }

    public function test_gmail_and_outlook_log_in_with_oauth() {
        $env = ['MAIL_USERNAME' => 'game@example.com', 'MAIL_OAUTH_CLIENT_ID' => 'id', 'MAIL_OAUTH_CLIENT_SECRET' => 'secret'];

        $gmail = Mailer::from_environment($this->db, $env + ['MAIL_TRANSPORT' => 'gmail'])->transport();
        $this->assertSame('smtp.gmail.com', $gmail->host);
        $this->assertSame('Google', $gmail->oauth_mailbox()->provider->name);

        $outlook = Mailer::from_environment($this->db, $env + ['MAIL_TRANSPORT' => 'outlook', 'MAIL_OAUTH_TENANT' => 'contoso'])->transport();
        $this->assertSame('smtp.office365.com', $outlook->host);
        $this->assertStringContainsString('/contoso/oauth2/v2.0/token', $outlook->oauth_mailbox()->provider->token_url);
    }

    public function test_oauth_without_its_settings_is_refused() {
        $this->expectExceptionMessage('MAIL_TRANSPORT=gmail needs MAIL_OAUTH_CLIENT_ID.');
        Mailer::from_environment($this->db, ['MAIL_TRANSPORT' => 'gmail', 'MAIL_USERNAME' => 'game@example.com']);
    }

    public function test_unknown_settings_are_refused() {
        foreach ([['MAIL_TRANSPORT' => 'pigeon'], ['MAIL_TRANSPORT' => 'smtp', 'MAIL_ENCRYPTION' => 'rot13']] as $env) {
            try {
                Mailer::from_environment($this->db, $env);
                $this->fail('accepted ' . json_encode($env));
            } catch (MailException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // --- sending ---

    public function test_send_hands_the_message_to_the_transport() {
        $transport = new MemoryTransport();
        (new Mailer($transport, 'noreply@example.com'))->send('player@example.com', 'Hello', 'Text', '<p>Text</p>');

        $this->assertCount(1, $transport->sent);
        $this->assertSame(['player@example.com', 'Hello', 'Text', '<p>Text</p>'],
            [$transport->sent[0]->to, $transport->sent[0]->subject, $transport->sent[0]->text, $transport->sent[0]->html]);
    }

    public function test_send_refuses_a_bad_address() {
        $this->expectException(MailException::class);
        (new Mailer(new MemoryTransport(), 'noreply@example.com'))->send('not an address', 'Hello', 'Text');
    }

    public function test_the_log_transport_appends_each_message() {
        $path = tempnam(sys_get_temp_dir(), 'mail');
        $mailer = new Mailer(new LogTransport($path), 'noreply@example.com');
        $mailer->send('a@example.com', 'First', 'One');
        $mailer->send('b@example.com', 'Second', 'Two');

        $log = file_get_contents($path);
        unlink($path);
        $this->assertStringContainsString("To: a@example.com\nSubject: First\n\nOne", $log);
        $this->assertStringContainsString("To: b@example.com\nSubject: Second\n\nTwo", $log);
    }

    // --- OAuth ---

    public function test_an_unconnected_mailbox_cannot_send() {
        $mailbox = $this->mailbox([]);
        $this->assertFalse($mailbox->is_connected());
        $this->expectExceptionMessage("isn't connected yet");
        $mailbox->access_token();
    }

    public function test_connecting_keeps_the_refresh_token_in_the_database() {
        $mailbox = $this->mailbox([['access_token' => 'at1', 'refresh_token' => 'rt1', 'expires_in' => 3600]]);

        $mailbox->finish_connect('the-code', 'https://game.example.com/admin/mail.php');

        $this->assertStringStartsWith('enc1:', AppSetting::get($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING), 'kept encrypted');
        $this->assertSame('rt1', $mailbox->refresh_token());
        $this->assertTrue($mailbox->is_connected());
        [$url, $fields] = $this->posts[0];
        $this->assertSame('https://oauth2.googleapis.com/token', $url);
        $this->assertSame(['authorization_code', 'the-code', 'https://game.example.com/admin/mail.php', 'client-id', 'client-secret'],
            [$fields['grant_type'], $fields['code'], $fields['redirect_uri'], $fields['client_id'], $fields['client_secret']]);
    }

    public function test_connecting_without_getting_a_refresh_token_fails() {
        $mailbox = $this->mailbox([['access_token' => 'at1']]);
        $this->expectException(MailException::class);
        $mailbox->finish_connect('the-code', 'https://game.example.com/admin/mail.php');
    }

    public function test_access_tokens_come_from_the_refresh_token_and_are_reused_until_they_expire() {
        $mailbox = $this->mailbox([['access_token' => 'at1', 'expires_in' => 3600]]);
        AppSetting::set($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING, 'rt1');

        $this->assertSame('at1', $mailbox->access_token());
        $this->assertSame('at1', $mailbox->access_token());
        $this->assertCount(1, $this->posts, 'only asked once');
        $this->assertSame(['refresh_token', 'rt1'], [$this->posts[0][1]['grant_type'], $this->posts[0][1]['refresh_token']]);
    }

    public function test_a_new_refresh_token_from_the_provider_replaces_the_stored_one() {
        $mailbox = $this->mailbox([['access_token' => 'at1', 'refresh_token' => 'rt2']], kind: 'outlook');
        AppSetting::set($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING, 'rt1');

        $mailbox->access_token();

        $this->assertSame('rt2', $mailbox->refresh_token());
        $this->assertStringContainsString('SMTP.Send', $this->posts[0][1]['scope']);
    }

    public function test_a_refresh_token_from_the_environment_wins_and_is_never_stored() {
        $mailbox = $this->mailbox([['access_token' => 'at1', 'refresh_token' => 'rt2']], fixed_refresh_token: 'env-token');

        $mailbox->access_token();

        $this->assertSame('env-token', $this->posts[0][1]['refresh_token']);
        $this->assertNull(AppSetting::get($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING));
        $this->assertTrue($mailbox->refresh_token_from_environment());
    }

    public function test_a_refused_refresh_says_why() {
        $mailbox = $this->mailbox([['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']]);
        AppSetting::set($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING, 'rt1');

        $this->expectExceptionMessage('invalid_grant: Token has been expired or revoked.');
        $mailbox->access_token();
    }

    public function test_the_xoauth2_login_string() {
        $mailbox = $this->mailbox([['access_token' => 'at1']]);
        AppSetting::set($this->db, OAuthMailbox::REFRESH_TOKEN_SETTING, 'rt1');

        $this->assertSame("user=game@example.com\001auth=Bearer at1\001\001", base64_decode($mailbox->getOauth64()));
    }

    public function test_the_consent_address() {
        $url = $this->mailbox([])->authorize_url('https://game.example.com/admin/mail.php', 'state123');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['client-id', 'https://game.example.com/admin/mail.php', 'code', 'https://mail.google.com/', 'state123', 'offline', 'game@example.com'],
            [$query['client_id'], $query['redirect_uri'], $query['response_type'], $query['scope'], $query['state'], $query['access_type'], $query['login_hint']]);
    }
}
