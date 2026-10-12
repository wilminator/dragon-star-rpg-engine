<?php
require_once INCLUDE_GAME . 'two_factor.php';
require_once INCLUDE_GAME . 'account_token.php';
require_once INCLUDE_GAME . 'mail.php';
require_once INCLUDE_GAME . 'site.php';
require_once INCLUDE_GAME . 'view.php';

// Every change to an account's second factors -- adding or removing the
// authenticator app, adding or removing a security key, making new
// recovery codes -- is asked for on the account page and only happens
// when confirmed from the link emailed to the account's address
// (two_factor_change.php). Someone who has got into a logged-in session,
// but not the player's email, can't add a factor of their own (and then
// use it to change the email address) or strip the player's.
//
// A new app or key is tested first -- the app's code, or the key's
// registration -- so the email only confirms something known to work.
// The first factor an account gets comes with recovery codes, shown on
// the confirmation page.
class TwoFactorChange {
    const LIFETIME = 3600;

    const ADD_APP = '2fa_add_app';
    const ADD_KEY = '2fa_add_key';
    const REMOVE_APP = '2fa_remove_app';
    const REMOVE_KEY = '2fa_remove_key';
    const NEW_CODES = '2fa_new_codes';
    const ALL = [self::ADD_APP, self::ADD_KEY, self::REMOVE_APP, self::REMOVE_KEY, self::NEW_CODES];

    public function __construct(private Database $db, private Mailer $mailer) {}

    // --- asking (from the account page); each returns null when the
    // confirmation email has been sent, otherwise why not ---

    // $secret: the key shown while setting up; $code: what the app shows
    // for it, proving it's set up right.
    public function request_add_app(UserAccount $account, string $secret, string $code): ?string {
        if (TwoFactor::has_app($account)) {
            return 'An authenticator app is already set up. Remove it first to use a different one.';
        }
        $step = Totp::matching_step($secret, $code);
        if ($step === null) {
            return "That code didn't match. Check the app is set up with this key and try the newest code.";
        }
        // The secret, and the step of the code used to test it (spent) --
        // encrypted while it waits, like the account's own.
        try {
            $data = SecretBox::encrypt($this->db, "$secret:$step", 'totp-pending:' . $account->userid);
        } catch (SecretBoxException $e) {
            log_error('Two-factor setup: ' . $e->getMessage());
            return "Two-factor sign-in can't be set up right now. Please tell the admins.";
        }
        return $this->ask($account, self::ADD_APP, $data);
    }

    // $post: the browser's answer to a WebAuthnService registration
    // started with $challenge.
    public function request_add_key(UserAccount $account, array $post, string $challenge, string $name): ?string {
        try {
            $registered = WebAuthnService::for_site()->finish_registration($post, $challenge);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        $key = SecurityKey::add_pending($this->db, $account->userid, $registered['credential_id'],
            $registered['public_key'], $registered['sign_count'], $name);
        return $this->ask($account, self::ADD_KEY, (string) $key->keyid);
    }

    public function request_remove_app(UserAccount $account): ?string {
        return TwoFactor::has_app($account) ? $this->ask($account, self::REMOVE_APP, null) : 'No authenticator app is set up.';
    }

    public function request_remove_key(UserAccount $account, int $keyid): ?string {
        $key = SecurityKey::load($this->db, $keyid);
        if ($key === null || $key->userid !== $account->userid || !$key->active) {
            return 'That security key is not on this account.';
        }
        return $this->ask($account, self::REMOVE_KEY, (string) $keyid);
    }

    public function request_new_recovery_codes(UserAccount $account): ?string {
        return TwoFactor::is_enabled($this->db, $account) ? $this->ask($account, self::NEW_CODES, null)
            : 'Recovery codes come with two-factor sign-in, which is off.';
    }

    // --- the link ---

    // What a link would do, for the confirmation page to show:
    // ['username' => string, 'change' => string], or null if the link
    // doesn't work.
    public function describe_link(string $token): ?array {
        $found = AccountToken::find($this->db, $token);
        if ($found === null || !in_array($found['purpose'], self::ALL, true)) {
            return null;
        }
        $account = UserAccount::load($this->db, $found['userid']);
        return $account === null ? null
            : ['username' => $account->username, 'change' => $this->describe($found['purpose'], $found['data'])];
    }

    // Makes the change. ['change' => string, 'recovery_codes' => ?array]
    // -- recovery codes when this gave the account its first factor, or
    // new codes were asked for -- or null if the link doesn't work (or
    // the change no longer makes sense).
    public function confirm(string $token): ?array {
        $found = AccountToken::find($this->db, $token);
        if ($found === null || !in_array($found['purpose'], self::ALL, true)
            || AccountToken::consume($this->db, $token, $found['purpose']) === null) {
            return null;
        }
        $account = UserAccount::load($this->db, $found['userid']);
        if ($account === null) {
            return null;
        }
        $had_factor = TwoFactor::is_enabled($this->db, $account);
        $change = $this->describe($found['purpose'], $found['data']);

        switch ($found['purpose']) {
            case self::ADD_APP:
                if (TwoFactor::has_app($account)) {
                    return null;
                }
                try {
                    $pending = SecretBox::decrypt((string) $found['data'], 'totp-pending:' . $account->userid);
                } catch (SecretBoxException $e) {
                    log_error('Two-factor setup: ' . $e->getMessage());
                    return null;
                }
                [$secret, $tested_step] = explode(':', $pending) + [1 => 0];
                $account->totp_secret = $secret;
                $account->totp_last_step = (int) $tested_step;
                $account->save($this->db);
                break;
            case self::ADD_KEY:
                $key = SecurityKey::load($this->db, (int) $found['data']);
                if ($key === null || $key->userid !== $account->userid) {
                    return null;
                }
                $key->activate($this->db);
                break;
            case self::REMOVE_APP:
                $account->totp_secret = null;
                $account->totp_last_step = null;
                $account->save($this->db);
                break;
            case self::REMOVE_KEY:
                $key = SecurityKey::load($this->db, (int) $found['data']);
                if ($key === null || $key->userid !== $account->userid) {
                    return null;
                }
                $key->delete($this->db);
                break;
        }

        $has_factor = TwoFactor::is_enabled($this->db, $account);
        $codes = null;
        if ($has_factor && (!$had_factor || $found['purpose'] === self::NEW_CODES)) {
            $codes = RecoveryCode::generate($this->db, $account->userid);
        } elseif (!$has_factor) {
            RecoveryCode::delete_all($this->db, $account->userid);
        }
        return ['change' => $change, 'recovery_codes' => $codes];
    }

    // Drops a change instead (the confirmation page's Cancel).
    public function cancel(string $token): bool {
        $found = AccountToken::find($this->db, $token);
        if ($found === null || !in_array($found['purpose'], self::ALL, true)) {
            return false;
        }
        AccountToken::consume($this->db, $token, $found['purpose']);
        $this->discard_pending_key($found);
        return true;
    }

    // Changes waiting for their link, described, for the account page.
    public function pending(UserAccount $account): array {
        return array_map(fn($t) => $this->describe($t['purpose'], $t['data']),
            AccountToken::outstanding($this->db, $account->userid, self::ALL));
    }

    // --- inside ---

    private function ask(UserAccount $account, string $purpose, ?string $data): ?string {
        if ($account->email === null) {
            return 'Two-factor changes are confirmed by email, and this account has no email address. Ask an admin.';
        }
        // A newer request of the same kind replaces an older one.
        foreach (AccountToken::outstanding($this->db, $account->userid, [$purpose]) as $old) {
            $this->discard_pending_key($old);
        }
        $token = AccountToken::issue($this->db, $account->userid, $purpose, self::LIFETIME, $data);
        try {
            $text = view()->render('email/two_factor_change.txt.twig', [
                'username' => $account->username,
                'change' => $this->describe($purpose, $data),
                'link' => app_url('two_factor_change.php?token=' . $token),
                'minutes' => intdiv(self::LIFETIME, 60),
            ]);
            $this->mailer->send($account->email, 'Confirm a change to your DragonStar sign-in', $text);
        } catch (MailException | RuntimeException $e) {
            log_error("Couldn't send a two-factor confirmation to account {$account->userid}: " . $e->getMessage());
            AccountToken::consume($this->db, $token, $purpose);
            $this->discard_pending_key(['purpose' => $purpose, 'data' => $data]);
            return "We couldn't send the confirmation email just now. Please try again later.";
        }
        return null;
    }

    private function discard_pending_key(array $token): void {
        if ($token['purpose'] !== self::ADD_KEY) {
            return;
        }
        $key = SecurityKey::load($this->db, (int) $token['data']);
        if ($key !== null && !$key->active) {
            $key->delete($this->db);
        }
    }

    private function describe(string $purpose, ?string $data): string {
        $key_name = fn() => SecurityKey::load($this->db, (int) $data)?->name ?? 'a security key';
        return match ($purpose) {
            self::ADD_APP => 'add an authenticator app for sign-in codes',
            self::ADD_KEY => 'add the security key "' . $key_name() . '"',
            self::REMOVE_APP => 'remove the authenticator app',
            self::REMOVE_KEY => 'remove the security key "' . $key_name() . '"',
            self::NEW_CODES => 'make new recovery codes (the old ones stop working)',
            default => 'change two-factor sign-in',
        };
    }
}
