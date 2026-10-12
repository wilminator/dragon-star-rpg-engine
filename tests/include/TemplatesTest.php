<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'account_admin.php';
require_once INCLUDE_GAME . 'view.php';
require_once INCLUDE_GAME . 'two_factor.php';
require_once INCLUDE_GAME . 'game_log.php';

/**
 * Renders each page template with sample values: catches a misspelled
 * variable (strict_variables makes it an error) or a syntax slip, and
 * checks that player-chosen text is escaped.
 */
class TemplatesTest extends TestCase {

    private function account(array $overrides = []): array {
        return $overrides + [
            'userid' => 7, 'username' => '<script>x</script>', 'email' => 'a@example.com',
            'on_hold' => false, 'verified' => true, 'created_at' => 1700000000, 'last_login_at' => null,
            'rights' => ['MANAGE_USERS'], 'two_factor' => true, 'two_factor_app' => true, 'security_keys' => 2,
        ];
    }

    public function test_the_account_search_page() {
        $html = view()->render('admin/users.html.twig', [
            'search' => ['text' => '"q', 'on_hold' => true, 'unverified' => false, 'right' => 'MANAGE_USERS'],
            'accounts' => [$this->account(), $this->account(['userid' => 8, 'on_hold' => true, 'verified' => false])],
            'all_rights' => PermissionGrant::ALL,
            'create' => ['username' => '', 'email' => '', 'rights' => ['EDIT_ITEM'], 'problem' => 'That username is taken.'],
        ]);

        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('value="&quot;q"', $html);
        $this->assertStringContainsString('<b>On hold</b>, Email not confirmed', $html);
        $this->assertStringContainsString('never', $html);
        $this->assertStringContainsString('name="csrf"', $html);
        $this->assertStringContainsString('That username is taken.', $html);
        $this->assertMatchesRegularExpression('/value="EDIT_ITEM" checked/', $html);
    }

    public function test_the_account_search_page_with_no_matches() {
        $html = view()->render('admin/users.html.twig', [
            'search' => ['text' => '', 'on_hold' => false, 'unverified' => false, 'right' => ''],
            'accounts' => [],
            'all_rights' => PermissionGrant::ALL,
            'create' => ['username' => '', 'email' => '', 'rights' => [], 'problem' => null],
        ]);
        $this->assertStringContainsString('No accounts match.', $html);
    }

    public function test_the_account_page() {
        $html = view()->render('admin/user.html.twig', [
            'account' => $this->account(),
            'all_rights' => PermissionGrant::ALL,
            'teams' => [['teamid' => 3, 'name' => 'Dragon & Co']],
            'tokens' => 4,
            'alliance' => ['teamids' => [3, 5], 'state' => 'active', 'minutes_left' => 42, 'overtime_minutes' => 0],
            'created' => true,
            'saved' => false,
            'problems' => ["You can't put your own account on hold."],
        ]);

        $this->assertStringContainsString('4 tokens.', $html);
        $this->assertStringContainsString('Alliance of teams 3, 5: active, 42 minutes left.', $html);
        $this->assertStringContainsString('name="grant_tokens"', $html);
        $this->assertStringContainsString('Dragon &amp; Co', $html);
        $this->assertStringContainsString('fight_playback.php?team=3', $html);
        $this->assertStringContainsString('You can&#039;t put your own account on hold.', $html);
        $this->assertMatchesRegularExpression('/value="MANAGE_USERS" checked/', $html);
        $this->assertStringContainsString('Account created.', $html);
    }

    public function test_the_mail_settings_page() {
        $values = [
            'messages' => ['Mailbox connected.'], 'problems' => [], 'config_problem' => null,
            'from' => 'noreply@example.com', 'from_name' => 'DragonStar', 'transport' => 'OAuth SMTP smtp.gmail.com:587 (starttls)',
            'oauth' => ['provider' => 'Google', 'username' => 'game@example.com', 'connected' => false,
                        'from_environment' => false, 'redirect_uri' => 'https://game.example.com/admin/mail.php'],
            'test_to' => 'me@example.com',
        ];
        $html = view()->render('admin/mail.html.twig', $values);
        $this->assertStringContainsString('Connect Google mailbox', $html);
        $this->assertStringContainsString('https://game.example.com/admin/mail.php', $html);
        $this->assertStringContainsString('DragonStar &lt;noreply@example.com&gt;', $html);

        $connected = view()->render('admin/mail.html.twig', ['oauth' => ['connected' => true] + $values['oauth']] + $values);
        $this->assertStringContainsString('Disconnect', $connected);

        $broken = view()->render('admin/mail.html.twig', ['config_problem' => 'MAIL_TRANSPORT=gmail needs MAIL_USERNAME.', 'oauth' => null] + $values);
        $this->assertStringContainsString('needs MAIL_USERNAME', $broken);
        $this->assertStringNotContainsString('Send a test email', $broken);
    }

    // --- public account pages ---

    private function bot_values(string $site_key = ''): array {
        return ['honeypot_field' => 'website', 'turnstile_site_key' => $site_key];
    }

    public function test_the_login_page_both_steps() {
        $none = ['app' => false, 'key_options' => null];
        $password = view()->render('account/login.html.twig', ['step' => 'password', 'problem' => 'Please confirm your email address first, using the link we sent you.',
            'unverified' => true, 'second' => $none, 'no_admin' => false]);
        $this->assertStringContainsString('name="password"', $password);
        $this->assertStringContainsString('resend_confirmation.php', $password);
        $this->assertStringContainsString('register.php', $password);
        $this->assertStringNotContainsString('create_account.sh', $password);

        $code = view()->render('account/login.html.twig', ['step' => 'code', 'problem' => null, 'unverified' => false,
            'second' => ['app' => true, 'key_options' => null], 'no_admin' => false]);
        $this->assertStringContainsString('name="code"', $code);
        $this->assertStringNotContainsString('data-webauthn', $code);

        $key = view()->render('account/login.html.twig', ['step' => 'code', 'problem' => null, 'unverified' => false,
            'second' => ['app' => false, 'key_options' => ['publicKey' => ['challenge' => 'abc"def']]], 'no_admin' => false]);
        $this->assertStringContainsString('data-webauthn="get"', $key);
        $this->assertStringContainsString('data-options="{&quot;publicKey&quot;:{&quot;challenge&quot;:&quot;abc\\&quot;def&quot;}}"', $key);
        $this->assertStringContainsString('Or enter a recovery code', $key);

        $fresh = view()->render('account/login.html.twig', ['step' => 'password', 'problem' => null, 'unverified' => false, 'second' => $none, 'no_admin' => true]);
        $this->assertStringContainsString('utils/create_account.sh', $fresh);
    }

    public function test_the_sign_up_page_with_and_without_turnstile() {
        $values = ['done' => false, 'mail_failed' => false, 'problem' => 'That username is taken.', 'username' => '<b>', 'email' => 'a@example.com', 'min_password_length' => 8];
        $plain = view()->render('account/register.html.twig', $values + $this->bot_values());
        $this->assertStringContainsString('name="website"', $plain, 'the honeypot');
        $this->assertStringNotContainsString('cf-turnstile', $plain);
        $this->assertStringContainsString('value="&lt;b&gt;"', $plain);

        $guarded = view()->render('account/register.html.twig', $values + $this->bot_values('1x00000000000000000000AA'));
        $this->assertStringContainsString('data-sitekey="1x00000000000000000000AA"', $guarded);
        $this->assertStringContainsString('challenges.cloudflare.com/turnstile/v0/api.js', $guarded);

        $done = view()->render('account/register.html.twig', ['done' => true, 'mail_failed' => true] + $values + $this->bot_values());
        $this->assertStringContainsString('Check your email', $done);
        $this->assertStringContainsString("couldn't send", $done);
    }

    public function test_the_email_request_pages() {
        $values = ['heading' => 'Forgot your password?', 'explanation' => 'Enter it.', 'done_message' => 'Sent.', 'action' => 'forgot_password.php',
                   'done' => false, 'problem' => null, 'email' => ''] + $this->bot_values();
        $resend = view()->render('account/email_request.html.twig', $values);
        $this->assertStringNotContainsString('authenticator', $resend);

        $forgot = view()->render('account/email_request.html.twig', $values + ['code_form' => ['who' => 'alice', 'problem' => "That didn't work."]]);
        $this->assertStringContainsString('Or use your authenticator app', $forgot);
        $this->assertStringContainsString('value="alice"', $forgot);
    }

    public function test_the_link_landing_pages() {
        $this->assertStringContainsString('Email confirmed', view()->render('account/confirm_email.html.twig', ['confirmed' => true, 'username' => 'Alice']));
        $this->assertStringContainsString("didn't work", view()->render('account/confirm_email.html.twig', ['confirmed' => false, 'username' => '']));
        $change = ['kind' => 'approve', 'token' => 'tok', 'new_email' => 'new@example.com'];
        $ask = view()->render('account/email_change.html.twig', ['result' => 'ask'] + $change);
        $this->assertStringContainsString('name="approve" value="tok"', $ask);
        $this->assertStringContainsString('Approve the change', $ask);
        $this->assertStringContainsString('name="confirm" value="tok"', view()->render('account/email_change.html.twig', ['result' => 'ask', 'kind' => 'confirm'] + $change));
        $this->assertStringContainsString('new@example.com', view()->render('account/email_change.html.twig', ['result' => 'approved'] + $change));
        $this->assertStringContainsString('is now', view()->render('account/email_change.html.twig', ['result' => 'changed'] + $change));

        $link = ['token' => 'tok', 'username' => 'Alice', 'change' => 'add the security key "Yubi"', 'recovery_codes' => null];
        $asking = view()->render('account/two_factor_change.html.twig', ['state' => 'ask'] + $link);
        $this->assertStringContainsString('add the security key &quot;Yubi&quot;', $asking);
        $this->assertStringContainsString('value="cancel"', $asking);
        $done = view()->render('account/two_factor_change.html.twig', ['state' => 'confirmed', 'recovery_codes' => ['abcd-efgh']] + $link);
        $this->assertStringContainsString('abcd-efgh', $done);
        $this->assertStringContainsString('Cancelled', view()->render('account/two_factor_change.html.twig', ['state' => 'cancelled'] + $link));
        $this->assertStringContainsString("didn't work", view()->render('account/two_factor_change.html.twig', ['state' => 'failed'] + $link));

        $reset = ['done' => false, 'valid' => true, 'problem' => null, 'token' => 'abc', 'username' => 'Alice', 'min_password_length' => 8];
        $this->assertStringContainsString('name="token" value="abc"', view()->render('account/reset_password.html.twig', $reset));
        $this->assertStringContainsString('forgot_password.php', view()->render('account/reset_password.html.twig', ['valid' => false] + $reset));
    }

    public function test_the_players_own_account_page() {
        $base = [
            'account' => ['username' => 'Alice', 'email' => 'alice@example.com', 'verified' => true, 'two_factor' => false, 'app' => false,
                          'recovery_codes_left' => 0, 'can_change_email' => true],
            'keys' => [], 'pending_email' => 'new@example.com', 'pending_changes' => [], 'setup' => null,
            'register_options' => ['publicKey' => ['challenge' => 'x']], 'second' => ['app' => false, 'key_options' => null],
            'messages' => [], 'problems' => [], 'min_password_length' => 8,
            'profile' => ['handle' => null, 'is_public' => false, 'searchable' => false, 'guid' => str_repeat('a', 32)],
        ];
        $off = view()->render('account/manage.html.twig', $base);
        $this->assertStringContainsString('value="profile"', $off);
        $this->assertStringNotContainsString('profile.php?p=', $off, 'no link while private');
        $public = view()->render('account/manage.html.twig',
            ['profile' => ['handle' => 'Al<b>', 'is_public' => true, 'searchable' => true, 'guid' => str_repeat('a', 32)]] + $base);
        $this->assertStringContainsString('value="Al&lt;b&gt;"', $public);
        $this->assertStringContainsString('profile.php?p=' . str_repeat('a', 32), $public);
        $this->assertMatchesRegularExpression('/name="searchable" value="1" checked/', $public);
        $this->assertStringContainsString('Set up an authenticator app', $off);
        $this->assertStringContainsString('data-webauthn="create"', $off);
        $this->assertStringContainsString('changing to new@example.com', $off);
        $this->assertStringContainsString("We'll send a link to your current address", $off);
        $this->assertStringNotContainsString('name="code"', $off);

        $setup = view()->render('account/manage.html.twig', ['setup' => ['secret' => 'ABCD EFGH', 'qr' => totp_qr_image(Totp::uri('ABCDEFGH', 'Alice'))]] + $base);
        $this->assertStringContainsString('ABCD EFGH', $setup);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $setup);

        $on = view()->render('account/manage.html.twig', [
            'account' => ['two_factor' => true, 'app' => true, 'recovery_codes_left' => 9] + $base['account'],
            'keys' => [['keyid' => 4, 'name' => 'Yubi <1>', 'created_at' => 1700000000, 'last_used_at' => null]],
            'pending_changes' => ['remove the authenticator app'],
            'second' => ['app' => true, 'key_options' => ['publicKey' => ['challenge' => 'y']]],
        ] + $base);
        $this->assertStringContainsString('Remove the authenticator app', $on);
        $this->assertStringContainsString('Yubi &lt;1&gt;', $on);
        $this->assertStringContainsString('name="keyid" value="4"', $on);
        $this->assertStringContainsString('9 left', $on);
        $this->assertStringContainsString('must be approved with your second factor', $on);
        $this->assertStringContainsString('data-webauthn="get"', $on);
        $this->assertStringContainsString('Waiting for you to follow the emailed link', $on);

        $stuck = view()->render('account/manage.html.twig', ['account' => ['email' => null, 'can_change_email' => false] + $base['account']] + $base);
        $this->assertStringContainsString('Ask an admin', $stuck);
        $this->assertStringNotContainsString('Change email</button>', $stuck);
    }

    public function test_the_emails() {
        foreach (['email/confirm_email.txt.twig', 'email/reset_password.txt.twig', 'email/approve_email_change.txt.twig',
                  'email/confirm_new_email.txt.twig', 'email/email_changed.txt.twig', 'email/two_factor_change.txt.twig'] as $template) {
            $text = view()->render($template, ['username' => 'Al & Co', 'link' => 'https://game.example.com/x?token=abc&y=1', 'hours' => 1,
                'minutes' => 60, 'new_email' => 'n@example.com', 'change' => 'add the security key "Yubi"']);
            $this->assertStringContainsString('Hello Al & Co', $text, "$template is plain text, not HTML-escaped");
            if ($template !== 'email/email_changed.txt.twig') {
                $this->assertStringContainsString('https://game.example.com/x?token=abc&y=1', $text);
            }
        }
    }

    public function test_the_logs_page() {
        $row = ['id' => 5, 'created_at' => 1700000000, 'category' => 'client_error', 'level' => 'error', 'kind' => 'client_error',
            'message' => 'Bad <party>', 'userid' => 42, 'username' => '<Al>', 'fightid' => 7, 'ip' => '203.0.113.1',
            'request' => 'processor.php take_action', 'location' => 'x.php:3', 'details_text' => "{\n  \"a\": \"<b>\"\n}",
            'resolved_at' => null, 'resolved_by' => null, 'resolved_by_name' => null];
        $values = [
            'input' => ['category' => 'client_error', 'level' => '', 'kind' => '', 'player' => '', 'fight' => '', 'from' => '', 'to' => '', 'status' => 'open', 'text' => ''],
            'levels' => GameLog::LEVELS, 'categories' => GameLog::CATEGORIES,
            'problems' => [], 'kinds' => ['client_error'], 'open_counts' => ['client_error' => ['client_error' => 3]],
            'return' => '?category=client_error&status=open', 'limit' => 200, 'messages' => ['Purged 3 entries.'], 'searched' => true,
            'logs' => [
                ['category' => 'browser', 'label' => 'Browsers', 'enabled' => true, 'channels' => [], 'count' => 1234, 'days' => 30, 'rows' => null, 'cull_percent' => 10],
                ['category' => 'debug', 'label' => 'Tracing', 'enabled' => false, 'count' => 0, 'days' => null, 'rows' => null, 'cull_percent' => 0,
                 'channels' => [['name' => 'ai', 'label' => 'AI decisions', 'enabled' => true], ['name' => 'xp', 'label' => 'Experience sharing', 'enabled' => false]]],
            ],
            'entries' => [$row, ['id' => 6, 'userid' => null, 'username' => null, 'fightid' => null,
                'resolved_at' => 1700000100, 'resolved_by' => 1, 'resolved_by_name' => 'boss'] + $row],
        ];
        $html = view()->render('admin/logs.html.twig', $values);

        $this->assertStringContainsString('logs.php?category=client_error&amp;kind=client_error', $html);
        $this->assertStringContainsString('<option value="client_error" selected>', $html);
        $this->assertStringContainsString('Bad &lt;party&gt;', $html);
        $this->assertStringContainsString('&lt;Al&gt;', $html);
        $this->assertStringContainsString('&quot;&lt;b&gt;&quot;', $html);
        $this->assertStringContainsString('Request: processor.php take_action', $html);
        $this->assertStringContainsString('fight_playback.php?fightid=7', $html);
        $this->assertStringContainsString('name="ids[]" value="5"', $html);
        $this->assertStringContainsString('resolved 2023-11-14 by boss', $html);
        $this->assertStringContainsString('name="csrf"', $html);
        $this->assertStringContainsString('Purged 3 entries.', $html);
        $this->assertStringContainsString('<form method="post" action="logs.php" id="log-browser">', $html);
        $this->assertStringContainsString('form="log-browser" name="days" value="30"', $html);
        $this->assertStringContainsString('form="log-browser" name="rows" value=""', $html);
        $this->assertStringContainsString('form="log-browser" name="cull_percent" value="10"', $html);
        $this->assertStringContainsString('form="log-browser" type="checkbox" name="enabled" value="1" checked', $html);
        $this->assertStringNotContainsString('form="log-debug" type="checkbox" name="enabled"', $html, 'debug has channels instead');
        $this->assertStringContainsString('form="log-debug" type="checkbox" name="channels[]" value="ai" checked> AI decisions', $html);
        $this->assertStringContainsString('form="log-debug" type="checkbox" name="channels[]" value="xp"> Experience sharing', $html);

        $empty = view()->render('admin/logs.html.twig', ['entries' => [], 'open_counts' => []] + $values);
        $this->assertStringContainsString('No entries match.', $empty);
        $this->assertStringContainsString('Nothing open.', $empty);
    }

    public function test_the_housekeeping_page() {
        $values = [
            'messages' => ['Saved.'], 'problems' => [], 'interval_minutes' => 10, 'fights_per_run' => 100,
            'last' => ['at' => 1700000000, 'removed' => ['rate_limits' => 7, 'fights' => 3]],
            'fight_days' => 30, 'fights_due' => 1,
            'labels' => ['rate_limits' => 'Expired attempt counters', 'fights' => 'Fights nothing keeps', 'account_tokens' => 'Links'],
        ];
        $html = view()->render('admin/housekeeping.html.twig', $values);
        $this->assertStringContainsString('2023-11-14', $html);
        $this->assertMatchesRegularExpression('#Expired attempt counters</td><td>7<#', $html);
        $this->assertMatchesRegularExpression('#Links</td><td>0<#', $html);
        $this->assertStringContainsString('name="fight_days" value="30"', $html);
        $this->assertStringContainsString('1 fight due for removal now.', $html);
        $this->assertStringContainsString('name="csrf"', $html);

        $off = view()->render('admin/housekeeping.html.twig', ['last' => null, 'fight_days' => null, 'fights_due' => null] + $values);
        $this->assertStringContainsString('Not run yet.', $off);
        $this->assertStringContainsString('Off: fights are kept for good.', $off);
        $this->assertStringContainsString('name="fight_days" value=""', $off);
    }

    public function test_the_monster_queue_page() {
        $values = ['queued' => false, 'problem' => 'Finish your fight first.', 'tokens' => 3,
            'fallback_seconds' => 30, 'lockout_minutes' => 60, 'daily_limit' => 3];
        $html = view()->render('monster_queue.html.twig', $values);
        $this->assertStringContainsString('Finish your fight first.', $html);
        $this->assertMatchesRegularExpression('/within\s+30 seconds/', $html);
        $this->assertStringContainsString('name="action" value="join"', $html);
        $this->assertStringContainsString('Tokens: 3', $html);
        $this->assertStringNotContainsString('fetch(', $html);

        $waiting = view()->render('monster_queue.html.twig', ['queued' => true, 'problem' => null] + $values);
        $this->assertStringContainsString('name="action" value="leave"', $waiting);
        $this->assertStringContainsString("action: 'poll'", $waiting);
        $this->assertStringContainsString('name="csrf"', $waiting);
        $this->assertStringContainsString("'?problem='", $waiting, 'a wait that ends without a fight says why');
        $this->assertStringContainsString('href="pick_team.php"', $html, 'back to team selection');
    }

    public function test_the_public_profile_page() {
        $profile = ['handle' => 'Dragon<Slayer>', 'teams' => [
            ['name' => 'Red & Co', 'heroes' => [['name' => 'Al', 'job' => 'Knight', 'level' => 7]],
             'fights' => [['label' => 'Underdog win', 'rating' => 'the monsters were 2.4× as strong', 'played_at' => 1700000000,
                           'url' => 'spectate_fight.php?fight=abc&team=def']]],
            ['name' => 'Quiet', 'heroes' => [], 'fights' => []],
        ]];
        $html = view()->render('profile.html.twig', ['profile' => $profile]);
        $this->assertStringContainsString('<h2>Dragon&lt;Slayer&gt;</h2>', $html);
        $this->assertStringContainsString('Red &amp; Co', $html);
        $this->assertStringContainsString('<td>Knight</td><td>7</td>', $html);
        $this->assertStringContainsString('href="spectate_fight.php?fight=abc&amp;team=def">Underdog win</a>', $html);
        $this->assertStringContainsString('(the monsters were 2.4× as strong)', $html);
        $this->assertStringContainsString("There's no public profile here.", view()->render('profile.html.twig', ['profile' => null]));

        $search = ['text' => 'dra', 'problem' => null, 'limit' => 50, 'results' => [['handle' => 'Dragon<Slayer>', 'guid' => 'abc']]];
        $html = view()->render('players.html.twig', $search);
        $this->assertStringContainsString('href="profile.php?p=abc">Dragon&lt;Slayer&gt;</a>', $html);
        $this->assertStringContainsString('No one matches.', view()->render('players.html.twig', ['results' => []] + $search));
        $this->assertStringContainsString('Too many', view()->render('players.html.twig', ['problem' => 'Too many searches.'] + $search));
    }
}
