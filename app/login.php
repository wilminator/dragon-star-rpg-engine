<?php
// Logging in: username and password, then -- for accounts using
// two-factor sign-in -- a code from the authenticator app, a security
// key, or a recovery code. Page: templates/account/login.html.twig.

# Where the player goes once logged in.
define('LOGIN_SUCCESS', './index.php');

define('INCLUDE_GAME', 'include/');
# Start the session first to ensure it gets created.
require_once INCLUDE_GAME . 'auth.php';

# Every DB-backed entity, so ensure_all_databases_exist() below can see
# each one's class. Loaded here rather than left to happen lazily
# elsewhere, since this is specifically the one place guaranteed to run
# before any other request-handling code could possibly touch the
# database -- see ensure_all_databases_exist()'s own comment in
# common.php for why that matters.
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_action.php';
require_once INCLUDE_GAME . 'pending_action.php';
require_once INCLUDE_GAME . 'fight_semaphore.php';
require_once INCLUDE_GAME . 'fight_player_state.php';
require_once INCLUDE_GAME . 'fight_save.php';
require_once INCLUDE_GAME . 'hero.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'monster.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'monster_side.php';
require_once INCLUDE_GAME . 'player_profile.php';
require_once INCLUDE_GAME . 'view.php';
ensure_all_databases_exist(get_database());

# Already logged in: straight to the game.
authenticate_login(LOGIN_SUCCESS, true);

$problem = null;
$unverified = false;
$step = 'password';

if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_two_factor']);
} elseif (isset($_GET['held'])) {
    $problem = 'This account is on hold.';
}

// The first login attempt after a new encryption key appears moves
// everything encrypted onto it (see KeyRotation); logins wait meanwhile.
$rotation = 'none';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rotation = KeyRotation::run_if_needed(get_database());
    } catch (SecretBoxException $e) {
        log_error('Encryption keys: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rotation === 'busy') {
    $problem = 'The site is updating its security keys. Please try again in a minute.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $problem = 'The form expired. Please try again.';
    } elseif (($_POST['step'] ?? '') === 'code') {
        $failure = authenticate_two_factor($_POST);
        authenticate_login(LOGIN_SUCCESS, true);
        $problem = $failure === 'expired' ? 'That took too long. Please log in again.' : TwoFactor::problem((string) $failure);
    } else {
        $failure = authenticate_user((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        # If the login was successful, this will bounce the player to the game.
        authenticate_login(LOGIN_SUCCESS, true);
        $unverified = $failure === 'unverified';
        $problem = match ($failure) {
            'needs_code' => null,
            'unverified' => 'Please confirm your email address first, using the link we sent you.',
            'on_hold' => 'This account is on hold.',
            'too_many' => 'Too many attempts. Please wait a few minutes and try again.',
            default => 'Username or password failed.',
        };
    }
}

$second = ['app' => false, 'key_options' => null];
$pending = pending_two_factor_account();
if ($pending !== null) {
    $step = 'code';
    $second = [
        'app' => TwoFactor::has_app($pending),
        'key_options' => TwoFactor::key_request(get_database(), $pending, 'login'),
    ];
}

// A new install has no accounts until one is made from the command line.
$no_admin = PermissionGrant::holders(get_database(), 'MANAGE_USERS') === [];

render('account/login.html.twig', ['step' => $step, 'problem' => $problem, 'unverified' => $unverified, 'second' => $second,
    'no_admin' => $no_admin]);
