<?php
// A player's own account: change password; change email address
// (approved by a second factor if the account has one, otherwise by a
// link to the current address -- never the password -- then confirmed
// at the new address; see AccountFlow); and two-factor sign-in with an
// authenticator app and/or security keys, where every change is
// confirmed from an emailed link (see TwoFactorChange).
// Page: templates/account/manage.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_login(AUTH_LOGIN);
redirect_on_hold($userid, 'dse', 'on_hold.php', 'index.php');

require_once INCLUDE_GAME . 'account_pages.php';
require_once INCLUDE_GAME . 'two_factor_change.php';
require_once INCLUDE_GAME . 'player_profile.php';

$db = get_database();
$account = UserAccount::load($db, (int) $userid);
$flow = account_flow();
$changes = new TwoFactorChange($db, mailer());
$messages = [];
$problems = [];
$check_email = "Check your email: we've sent a link to {$account->email} to confirm the change. Nothing changes until it's followed.";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $problem = null;
    $asked = false;
    if (!csrf_valid()) {
        $problem = 'The form expired. Please try again.';
    } elseif ($action === 'profile') {
        $problem = PlayerProfile::update($db, $account, (string) ($_POST['handle'] ?? ''),
            ($_POST['is_public'] ?? '') === '1', ($_POST['searchable'] ?? '') === '1');
        if ($problem === null) {
            $messages[] = 'Profile saved.';
        }
    } elseif ($action === 'password') {
        $new = (string) ($_POST['new_password'] ?? '');
        if (!$account->password_matches((string) ($_POST['current_password'] ?? ''))) {
            $problem = "Your current password wasn't right.";
        } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
            $problem = "The two new passwords don't match.";
        } else {
            try {
                $account->set_password($new);
                $account->save($db);
                $messages[] = 'Password changed.';
            } catch (InvalidArgumentException $e) {
                $problem = $e->getMessage();
            }
        }
    } elseif ($action === 'email') {
        $result = $flow->request_email_change($account, (string) ($_POST['new_email'] ?? ''), $_POST, client_ip());
        $problem = $result['problem'];
        if ($result['status'] === 'approval_sent') {
            $messages[] = "We've sent a link to your current address, {$account->email}. Follow it to approve the change.";
        } elseif ($result['status'] === 'confirm_sent') {
            $messages[] = "Approved. We've sent a link to the new address; follow it to finish the change.";
        }
    } elseif ($action === 'start_app') {
        $_SESSION['totp_setup'] = Totp::generate_secret();
    } elseif ($action === 'cancel_app') {
        unset($_SESSION['totp_setup']);
    } elseif ($action === 'add_app' && isset($_SESSION['totp_setup'])) {
        $problem = $changes->request_add_app($account, $_SESSION['totp_setup'], (string) ($_POST['code'] ?? ''));
        if ($problem === null) {
            unset($_SESSION['totp_setup']);
            $asked = true;
        }
    } elseif ($action === 'add_key') {
        $challenge = TwoFactor::take_challenge('register', WebAuthnService::answered_challenge($_POST));
        $problem = $challenge === '' ? 'Please try adding the key again.'
            : $changes->request_add_key($account, $_POST, $challenge, (string) ($_POST['key_name'] ?? ''));
        $asked = $problem === null;
    } elseif ($action === 'remove_app') {
        $problem = $changes->request_remove_app($account);
        $asked = $problem === null;
    } elseif ($action === 'remove_key') {
        $problem = $changes->request_remove_key($account, (int) ($_POST['keyid'] ?? 0));
        $asked = $problem === null;
    } elseif ($action === 'new_codes') {
        $problem = $changes->request_new_recovery_codes($account);
        $asked = $problem === null;
    }
    if ($problem !== null) {
        $problems[] = $problem;
    }
    if ($asked) {
        $messages[] = $check_email;
    }
}

$two_factor = TwoFactor::is_enabled($db, $account);

// Starting a security-key registration, and a key check for the email
// change, needs APP_URL.
$register_options = null;
$email_key_options = null;
try {
    $service = WebAuthnService::for_site();
    $registration = $service->registration_options($account->userid, $account->username, SecurityKey::load_for($db, $account->userid, true));
    TwoFactor::remember_challenge('register', $registration['challenge']);
    $register_options = $registration['options'];
    $email_key_options = $two_factor ? TwoFactor::key_request($db, $account, 'email_change') : null;
} catch (RuntimeException $e) {
    $problems[] = 'Security keys are unavailable: ' . $e->getMessage();
}

$setup = null;
if (!TwoFactor::has_app($account) && isset($_SESSION['totp_setup'])) {
    $setup = [
        'secret' => trim(chunk_split($_SESSION['totp_setup'], 4, ' ')),
        'qr' => totp_qr_image(Totp::uri($_SESSION['totp_setup'], $account->username)),
    ];
}

render('account/manage.html.twig', [
    'account' => [
        'username' => $account->username,
        'email' => $account->email,
        'verified' => $account->is_verified(),
        'two_factor' => $two_factor,
        'app' => TwoFactor::has_app($account),
        'recovery_codes_left' => RecoveryCode::remaining($db, $account->userid),
        'can_change_email' => AccountFlow::can_change_email($account),
    ],
    'keys' => array_map(fn($key) => ['keyid' => $key->keyid, 'name' => $key->name, 'created_at' => $key->created_at,
        'last_used_at' => $key->last_used_at], SecurityKey::load_for($db, $account->userid)),
    'pending_email' => $flow->pending_email_change($account),
    'pending_changes' => $changes->pending($account),
    'setup' => $setup,
    'register_options' => $register_options,
    'second' => ['app' => TwoFactor::has_app($account), 'key_options' => $email_key_options],
    'messages' => $messages,
    'problems' => $problems,
    'min_password_length' => UserAccount::MIN_PASSWORD_LENGTH,
    'profile' => (function () use ($db, $account) {
        $profile = PlayerProfile::load($db, $account->userid);
        return ['handle' => $profile->handle, 'is_public' => $profile->is_public, 'searchable' => $profile->searchable,
            'guid' => PLAYER::guid_for($db, $account->userid)];
    })(),
]);
