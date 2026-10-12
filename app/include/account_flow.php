<?php
require_once INCLUDE_GAME . 'user_account.php';
require_once INCLUDE_GAME . 'account_token.php';
require_once INCLUDE_GAME . 'rate_limit.php';
require_once INCLUDE_GAME . 'mail.php';
require_once INCLUDE_GAME . 'site.php';
require_once INCLUDE_GAME . 'view.php';
require_once INCLUDE_GAME . 'two_factor.php';

// What the account pages do: sign up, confirm an email address, resend
// the confirmation, reset a forgotten password (by emailed link, or by
// two-factor code), and change a logged-in player's email address. Pages
// check the honeypot and Turnstile (human_check.php) and CSRF
// themselves, then call these.
//
// Changing an email address takes two steps: the change is approved
// either by a link sent to the current address or by a two-factor code,
// and then the new address is confirmed by a link sent to it (so a
// mistyped address can't lock the player out). The old address is told
// once the change is made.
//
// Sign-up makes an account that can't log in until the emailed link is
// followed. Asking for a password reset (or another confirmation email)
// always answers the same way, whether or not the address has an
// account, so the pages can't be used to find out who plays.
class AccountFlow {
    const VERIFY_LIFETIME = 48 * 3600;
    const RESET_LIFETIME = 3600;
    // A correct two-factor code only opens the reset page for a moment.
    const CODE_RESET_LIFETIME = 600;
    const EMAIL_CHANGE_LIFETIME = 24 * 3600;

    // Attempts allowed per hour.
    const SIGNUPS_PER_IP = 5;
    const EMAILS_PER_ADDRESS = 3;
    const EMAILS_PER_IP = 10;

    public function __construct(private Database $db, private Mailer $mailer) {}

    // Returns ['account' => ?UserAccount, 'problem' => ?string,
    // 'mail_failed' => bool]. On success the account exists and the
    // confirmation email has been sent, unless mail_failed (then the
    // player can ask for it again).
    public function sign_up(string $username, string $email, string $password, string $ip): array {
        if (!RateLimit::hit($this->db, "signup-ip:$ip", self::SIGNUPS_PER_IP, 3600)) {
            return ['account' => null, 'problem' => 'Too many sign-ups from here. Please try again later.', 'mail_failed' => false];
        }
        if (UserAccount::normalize_email($email) === null) {
            return ['account' => null, 'problem' => 'Please give an email address; we send a link to it to confirm the account.', 'mail_failed' => false];
        }
        try {
            $account = UserAccount::create($this->db, $username, $password, $email, verified: false);
        } catch (InvalidArgumentException $e) {
            return ['account' => null, 'problem' => $e->getMessage(), 'mail_failed' => false];
        }
        return ['account' => $account, 'problem' => null, 'mail_failed' => !$this->send_confirmation($account)];
    }

    // Confirms the address a token was sent to. The account, or null if
    // the link is unknown, used or expired.
    public function confirm_email(string $token): ?UserAccount {
        $userid = AccountToken::consume($this->db, $token, AccountToken::VERIFY);
        $account = $userid === null ? null : UserAccount::load($this->db, $userid);
        if ($account !== null && !$account->is_verified()) {
            $account->email_verified_at = time();
            $account->save($this->db);
        }
        return $account;
    }

    // Sends another confirmation email if the address belongs to an
    // unconfirmed account. False only when over the rate limit.
    public function resend_confirmation(string $email, string $ip): bool {
        if (!$this->within_email_limits($email, $ip)) {
            return false;
        }
        $account = UserAccount::load_by_email($this->db, $email);
        if ($account !== null && !$account->is_verified()) {
            $this->send_confirmation($account);
        }
        return true;
    }

    // Sends a reset link if the address has an account. False only when
    // over the rate limit.
    public function request_password_reset(string $email, string $ip): bool {
        if (!$this->within_email_limits($email, $ip)) {
            return false;
        }
        $account = UserAccount::load_by_email($this->db, $email);
        if ($account !== null) {
            $token = AccountToken::issue($this->db, $account->userid, AccountToken::RESET, self::RESET_LIFETIME);
            $this->send($account, 'Reset your DragonStar password', 'email/reset_password.txt.twig', [
                'link' => app_url('reset_password.php?token=' . $token),
                'hours' => intdiv(self::RESET_LIFETIME, 3600),
            ]);
        }
        return true;
    }

    // The account a reset link is for, if it still works (for showing
    // the new-password form).
    public function reset_link_account(string $token): ?UserAccount {
        $userid = AccountToken::peek($this->db, $token, AccountToken::RESET);
        return $userid === null ? null : UserAccount::load($this->db, $userid);
    }

    // Sets a new password from a reset link. Returns null on success,
    // otherwise why not. Following the link also proves the address, so
    // an unconfirmed account becomes confirmed.
    public function reset_password(string $token, string $password): ?string {
        $problem = UserAccount::password_problem($password);
        if ($problem !== null) {
            return $problem;
        }
        $userid = AccountToken::consume($this->db, $token, AccountToken::RESET);
        $account = $userid === null ? null : UserAccount::load($this->db, $userid);
        if ($account === null) {
            return 'That reset link has expired or was already used. Please ask for a new one.';
        }
        $account->set_password($password);
        $account->email_verified_at ??= time();
        $account->save($this->db);
        return null;
    }

    // Forgot password, with an authenticator instead of email: when the
    // account (by username or email) uses two-factor sign-in and the code
    // is right, returns a token for reset_password.php. Otherwise null
    // with the reason: ['token' => ?string, 'problem' => ?string].
    public function reset_with_code(string $username_or_email, string $code, string $ip): array {
        if (!RateLimit::hit($this->db, "code-reset-ip:$ip", self::EMAILS_PER_IP, 3600)) {
            return ['token' => null, 'problem' => 'Too many tries. Please try again later.'];
        }
        $account = str_contains($username_or_email, '@')
            ? UserAccount::load_by_email($this->db, $username_or_email)
            : UserAccount::load_by_username($this->db, $username_or_email);
        $result = $account === null ? 'wrong' : TwoFactor::check($this->db, $account, $code);
        if ($result !== 'ok') {
            return ['token' => null, 'problem' => $result === 'too_many' ? TwoFactor::problem($result)
                : "That didn't work. Check the name and the code (two-factor sign-in has to be turned on for the account)."];
        }
        return ['token' => AccountToken::issue($this->db, $account->userid, AccountToken::RESET, self::CODE_RESET_LIFETIME), 'problem' => null];
    }

    // --- changing a logged-in player's email address ---

    // Starts a change to $new_email. An account with two-factor sign-in
    // must approve it with a second factor, sent in $second_factor (the
    // form's fields: 'code', or a security key's answer to
    // TwoFactor::key_request(..., 'email_change')); nothing else will do.
    // Otherwise it's approved by a link to the current address. The
    // password never approves a change: whoever has it could then take
    // the account over.
    //
    // Returns ['status' => 'approval_sent' (a link went to the current
    // address), 'confirm_sent' (approved; a link went to the new
    // address) or null, 'problem' => ?string].
    public function request_email_change(UserAccount $account, string $new_email, array $second_factor, string $ip): array {
        $fail = fn(string $problem) => ['status' => null, 'problem' => $problem];
        if (!self::can_change_email($account)) {
            return $fail(self::CANNOT_CHANGE_EMAIL);
        }
        if (!RateLimit::hit($this->db, "email-ip:$ip", self::EMAILS_PER_IP, 3600)) {
            return $fail('Too many emails asked for. Please try again later.');
        }
        $new_email = UserAccount::normalize_email($new_email) ?? '';
        $problem = $this->new_email_problem($account, $new_email);
        if ($problem !== null) {
            return $fail($problem);
        }

        if (TwoFactor::is_enabled($this->db, $account)) {
            $result = TwoFactor::verify($this->db, $account, $second_factor, 'email_change');
            if ($result !== 'ok') {
                return $fail(TwoFactor::problem($result));
            }
        } else {
            $token = AccountToken::issue($this->db, $account->userid, AccountToken::EMAIL_CHANGE, self::EMAIL_CHANGE_LIFETIME, $new_email);
            $sent = $this->send($account, 'Approve changing your DragonStar email address', 'email/approve_email_change.txt.twig', [
                'new_email' => $new_email,
                'link' => app_url('email_change.php?approve=' . $token),
                'hours' => intdiv(self::EMAIL_CHANGE_LIFETIME, 3600),
            ]);
            return $sent ? ['status' => 'approval_sent', 'problem' => null] : $fail("We couldn't send the email just now. Please try again later.");
        }

        return $this->send_new_address_confirmation($account, $new_email)
            ? ['status' => 'confirm_sent', 'problem' => null]
            : $fail("We couldn't send the email just now. Please try again later.");
    }

    const CANNOT_CHANGE_EMAIL = 'This account has no email address to approve a change from. Ask an admin.';

    // Whether the player can change their address themselves -- only the
    // starting test accounts have none.
    public static function can_change_email(UserAccount $account): bool {
        return $account->email !== null;
    }

    // The link sent to the current address. Returns the new address a
    // confirmation link went to, or null if the link is unknown, used,
    // expired, or the new address has been taken since.
    public function approve_email_change(string $token): ?string {
        $found = AccountToken::consume_with_data($this->db, $token, AccountToken::EMAIL_CHANGE);
        $account = $found === null ? null : UserAccount::load($this->db, $found['userid']);
        if ($account === null || $this->new_email_problem($account, (string) $found['data']) !== null) {
            return null;
        }
        return $this->send_new_address_confirmation($account, (string) $found['data']) ? (string) $found['data'] : null;
    }

    // The link sent to the new address: makes the change and tells the
    // old address. The account, or null if the link doesn't work.
    public function confirm_new_email(string $token): ?UserAccount {
        $found = AccountToken::consume_with_data($this->db, $token, AccountToken::EMAIL_NEW);
        $account = $found === null ? null : UserAccount::load($this->db, $found['userid']);
        if ($account === null || $this->new_email_problem($account, (string) $found['data']) !== null) {
            return null;
        }
        $old_email = $account->email;
        $account->email = (string) $found['data'];
        $account->email_verified_at = time();
        $account->save($this->db);
        if ($old_email !== null) {
            $this->send_to($old_email, $account, 'Your DragonStar email address was changed', 'email/email_changed.txt.twig',
                ['new_email' => $account->email]);
        }
        return $account;
    }

    // The change waiting for its new address to be confirmed, if any.
    public function pending_email_change(UserAccount $account): ?string {
        AccountToken::ensure_table_exists($this->db);
        $stmt = $this->db->prepare("SELECT data FROM account_tokens WHERE userid = ? AND purpose IN (?, ?) AND expires_at >= ?
            ORDER BY expires_at DESC");
        $stmt->execute([$account->userid, AccountToken::EMAIL_CHANGE, AccountToken::EMAIL_NEW, time()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row['data'];
    }

    private function new_email_problem(UserAccount $account, string $new_email): ?string {
        if ($new_email === '') {
            return 'Please give the new email address.';
        }
        $problem = UserAccount::email_problem($new_email);
        if ($problem !== null) {
            return $problem;
        }
        if ($new_email === $account->email) {
            return "That's already your email address.";
        }
        $owner = UserAccount::load_by_email($this->db, $new_email);
        if ($owner !== null && $owner->userid !== $account->userid) {
            return 'That email address already has an account.';
        }
        return null;
    }

    private function send_new_address_confirmation(UserAccount $account, string $new_email): bool {
        AccountToken::revoke_all($this->db, $account->userid, AccountToken::EMAIL_CHANGE);
        $token = AccountToken::issue($this->db, $account->userid, AccountToken::EMAIL_NEW, self::EMAIL_CHANGE_LIFETIME, $new_email);
        return $this->send_to($new_email, $account, 'Confirm your new DragonStar email address', 'email/confirm_new_email.txt.twig', [
            'link' => app_url('email_change.php?confirm=' . $token),
            'hours' => intdiv(self::EMAIL_CHANGE_LIFETIME, 3600),
        ]);
    }

    private function within_email_limits(string $email, string $ip): bool {
        $by_ip = RateLimit::hit($this->db, "email-ip:$ip", self::EMAILS_PER_IP, 3600);
        $by_address = RateLimit::hit($this->db, 'email-to:' . (UserAccount::normalize_email($email) ?? ''), self::EMAILS_PER_ADDRESS, 3600);
        return $by_ip && $by_address;
    }

    private function send_confirmation(UserAccount $account): bool {
        $token = AccountToken::issue($this->db, $account->userid, AccountToken::VERIFY, self::VERIFY_LIFETIME);
        return $this->send($account, 'Confirm your DragonStar account', 'email/confirm_email.txt.twig', [
            'link' => app_url('confirm_email.php?token=' . $token),
            'hours' => intdiv(self::VERIFY_LIFETIME, 3600),
        ]);
    }

    private function send(UserAccount $account, string $subject, string $template, array $values): bool {
        return $this->send_to((string) $account->email, $account, $subject, $template, $values);
    }

    private function send_to(string $address, UserAccount $account, string $subject, string $template, array $values): bool {
        try {
            $text = view()->render($template, $values + ['username' => $account->username]);
            $this->mailer->send($address, $subject, $text);
            return true;
        } catch (MailException | RuntimeException $e) {
            log_error("Couldn't send '$subject' to account {$account->userid}: " . $e->getMessage());
            return false;
        }
    }
}
