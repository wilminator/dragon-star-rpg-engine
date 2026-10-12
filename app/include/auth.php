<?php
//Local replacement for the old shared "common_auth" library.
//The original site hosted several apps under one login/permission
//system, keyed by a $context string ('dse' = this game). Now that
//this game is the only context, $context/$else_redirect args are
//kept on these functions purely so the ~40 existing call sites don't
//need their arguments rewritten - they're accepted but unused.

# This is intended to be updated with the default login url.
define('AUTH_LOGIN', '/login.php');

require_once INCLUDE_GAME . 'user_account.php';

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

// Logs the account in when the username (any case) and password
// match, its email has been confirmed, and it isn't on hold. Returns
// null on success, otherwise why not: 'invalid' (no such account, or
// the wrong password -- deliberately indistinguishable), 'too_many'
// (see LOGIN_ATTEMPTS_*), 'unverified', 'on_hold', or 'needs_code': the
// account uses two-factor sign-in, and authenticate_two_factor() must be
// given a code within TWO_FACTOR_LOGIN_SECONDS to finish.
function authenticate_user($username, $password, ?string $ip = null): ?string {
    $db = get_database();
    $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!login_attempt_allowed($db, (string) $username, $ip)) {
        return 'too_many';
    }
    $account = UserAccount::load_by_username($db, (string) $username);
    if ($account === null) {
        // As slow as checking a real password, so response times don't
        // reveal which usernames exist.
        password_verify((string) $password, DUMMY_PASSWORD_HASH);
        return 'invalid';
    }
    if (!$account->password_matches((string) $password)) {
        return 'invalid';
    }
    RateLimit::clear($db, login_account_bucket((string) $username));
    if (!$account->is_verified()) {
        return 'unverified';
    }
    if ($account->on_hold) {
        return 'on_hold';
    }
    if (TwoFactor::is_enabled($db, $account)) {
        $account->save($db); // keeps a rehashed password
        $_SESSION['pending_two_factor'] = ['userid' => $account->userid, 'at' => time()];
        return 'needs_code';
    }
    complete_login($db, $account);
    return null;
}

const TWO_FACTOR_LOGIN_SECONDS = 300;

// Password attempts allowed per window: per account (so one account
// can't be guessed at quickly, from however many machines) and per
// address (so one machine can't try many accounts). An account's count
// starts over once its password is given correctly.
const LOGIN_ATTEMPTS_PER_ACCOUNT = 10;
const LOGIN_ATTEMPTS_PER_IP = 30;
const LOGIN_ATTEMPT_WINDOW = 900;

// A fixed Argon2id hash (of nothing anyone uses), checked against when
// there's no such account. Fixed rather than made per request: making
// one costs as much as checking one.
const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Y1RFWmsza0hTNXpoWGRLcw$rHKXTPbjuIl7p7zCLJI8jwctpNH4D3/dMfjHPLPjPFQ';

function login_account_bucket(string $username): string {
    return 'login-account:' . strtolower(trim($username));
}

function login_attempt_allowed(Database $db, string $username, string $ip): bool {
    $by_account = RateLimit::hit($db, login_account_bucket($username), LOGIN_ATTEMPTS_PER_ACCOUNT, LOGIN_ATTEMPT_WINDOW);
    $by_ip = RateLimit::hit($db, "login-ip:$ip", LOGIN_ATTEMPTS_PER_IP, LOGIN_ATTEMPT_WINDOW);
    return $by_account && $by_ip;
}

// The account waiting for its two-factor code, if the password was
// given recently enough.
function pending_two_factor_account(): ?UserAccount {
    $pending = $_SESSION['pending_two_factor'] ?? null;
    if (!is_array($pending) || $pending['at'] + TWO_FACTOR_LOGIN_SECONDS < time()) {
        unset($_SESSION['pending_two_factor']);
        return null;
    }
    return UserAccount::load(get_database(), (int) $pending['userid']);
}

// The second step of logging in, from the form's fields: a code from
// the app or a recovery code ('code'), or a security key's answer to
// TwoFactor::key_request(..., 'login'). Null when right and the player
// is now logged in, otherwise 'expired' (start over), 'too_many' or
// 'wrong'.
function authenticate_two_factor(array $post): ?string {
    $account = pending_two_factor_account();
    if ($account === null || $account->on_hold) {
        unset($_SESSION['pending_two_factor']);
        return 'expired';
    }
    $db = get_database();
    $result = TwoFactor::verify($db, $account, $post, 'login');
    if ($result !== 'ok') {
        return $result;
    }
    unset($_SESSION['pending_two_factor']);
    complete_login($db, $account);
    return null;
}

function complete_login(Database $db, UserAccount $account): void {
    session_regenerate_id(true);
    $_SESSION['userid'] = $account->userid;
    $account->last_login_at = time();
    $account->save($db); // also keeps a rehashed password
    require_once INCLUDE_GAME . 'housekeeping.php';
    Housekeeping::after_response($db);
}

# Verify that the user is logged in.
function is_logged_in() {
    return isset($_SESSION['userid']);
}

# Get the logged in user's ID.
function get_logged_in_userid() {
    return is_logged_in() ? $_SESSION['userid'] : false;
}

/*
Requires a logged-in user. 
Default action redirects to $login_url and stops when not logged in.
If $redirect_when_logged_in is true, the redirect happens only when the user is logged in.
*/
function authenticate_login(string $redirect_url, bool $redirect_when_logged_in = false) {
    if (is_logged_in() === $redirect_when_logged_in) {
        header('Location: ' . $redirect_url);
        exit;
    }
    return get_logged_in_userid();
}

//Old library distinguished "just logged in" (membership) from "logged
//in with a specific permission". With a single context those collapse
//into the same check.
function authenticate_membership_access($context, $login_url, $fail_redirect) {
    return authenticate_login($login_url);
}

//Requires a logged-in user holding a specific permission.
function authenticate_permission_access($context, $permission, $login_url, $fail_redirect_prefix) {
    $userid = authenticate_login($login_url);
    if (!check_permission($userid, $context, $permission)) {
        header('Location: ' . $fail_redirect_prefix . 'index.php?denied=1');
        exit;
    }
    return $userid;
}

//Non-redirecting permission check, used to decide what to show.
function check_permission($userid, $context, $permission) {
    return PermissionGrant::has(get_database(), (int) $userid, $permission);
}

//Redirects to $hold_page if the account is suspended.
function is_on_hold($userid) {
    return UserAccount::load(get_database(), (int) $userid)?->on_hold ?? false;
}

// An account put on hold while logged in is logged out and sent to the
// login page, which says why. $hold_page is accepted but unused, like
// $context: the page it named never existed.
function redirect_on_hold($userid, $context, $hold_page, $else_redirect) {
    if (is_on_hold($userid)) {
        log_out();
        header('Location: ' . AUTH_LOGIN . '?held=1');
        exit;
    }
}

function log_out(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

//No-op now - membership only meant something when this app was one of
//several sharing a login system. Kept so call sites don't need editing.
function add_context_membership($userid, $context) {
}
