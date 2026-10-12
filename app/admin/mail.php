<?php
// Mail settings for admins holding RESET_DB: shows how mail is being
// sent, connects a Gmail or Outlook mailbox over OAuth (when
// MAIL_TRANSPORT is gmail or outlook), and sends a test email.
// Page: templates/admin/mail.html.twig.
//
// Connecting: the admin is sent to Google or Microsoft to sign in as the
// mailbox and allow sending mail; the provider sends them back here with
// a code, which is traded for a refresh token kept in the database (see
// OAuthMailbox). The return address is APP_URL/admin/mail.php, and it
// must be listed in the provider's app registration.

define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_membership_access('dse', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');
if (!check_permission($userid, 'dse', 'RESET_DB')) {
    header('Location: index.php');
    exit;
}

require_once INCLUDE_GAME . 'view.php';
require_once INCLUDE_GAME . 'mail.php';
require_once INCLUDE_GAME . 'site.php';

$db = get_database();
$messages = [];
$problems = [];
$config_problem = null;
$mailer = null;
try {
    $mailer = Mailer::from_environment($db);
} catch (MailException $e) {
    $config_problem = $e->getMessage();
}
$transport = $mailer?->transport();
$mailbox = null;
if ($transport instanceof SmtpTransport) {
    $mailbox = $transport->oauth_mailbox();
}

$redirect_uri = null;
if ($mailbox !== null) {
    try {
        $redirect_uri = app_url('admin/mail.php');
    } catch (RuntimeException $e) {
        $problems[] = $e->getMessage();
    }
}

// --- Back from the provider ---
if (isset($_GET['state']) && $mailbox !== null && $redirect_uri !== null) {
    $expected = $_SESSION['mail_oauth_state'] ?? '';
    unset($_SESSION['mail_oauth_state']);
    if ($expected === '' || !hash_equals($expected, (string) $_GET['state'])) {
        $problems[] = 'That sign-in response was not for this session. Please connect again.';
    } elseif (isset($_GET['error'])) {
        $problems[] = 'Not connected: ' . $_GET['error'] . (isset($_GET['error_description']) ? ' (' . $_GET['error_description'] . ')' : '');
    } else {
        try {
            $mailbox->finish_connect((string) ($_GET['code'] ?? ''), $redirect_uri);
            $messages[] = 'Mailbox connected.';
        } catch (MailException $e) {
            $problems[] = $e->getMessage();
        }
    }
}

// --- Actions ---
$test_to = UserAccount::load($db, (int) $userid)?->email ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_valid()) {
        $problems[] = 'The form expired. Please try again.';
    } elseif ($action === 'connect' && $mailbox !== null && $redirect_uri !== null) {
        $_SESSION['mail_oauth_state'] = bin2hex(random_bytes(16));
        header('Location: ' . $mailbox->authorize_url($redirect_uri, $_SESSION['mail_oauth_state']));
        exit;
    } elseif ($action === 'disconnect') {
        AppSetting::delete($db, OAuthMailbox::REFRESH_TOKEN_SETTING);
        $messages[] = 'Mailbox disconnected.';
    } elseif ($action === 'test' && $mailer !== null) {
        $test_to = trim((string) ($_POST['to'] ?? ''));
        try {
            $mailer->send($test_to, 'DragonStar test email', "This is a test email from DragonStar's mail settings page.");
            $messages[] = "Test email sent to $test_to.";
        } catch (MailException $e) {
            $problems[] = $e->getMessage();
        }
    }
}

render('admin/mail.html.twig', [
    'messages' => $messages,
    'problems' => $problems,
    'config_problem' => $config_problem,
    'from' => $mailer?->from ?? '',
    'from_name' => $mailer?->from_name ?? '',
    'transport' => $transport !== null && method_exists($transport, 'describe') ? $transport->describe() : '',
    'oauth' => $mailbox === null ? null : [
        'provider' => $mailbox->provider->name,
        'username' => $mailbox->username,
        'connected' => $mailbox->is_connected(),
        'from_environment' => $mailbox->refresh_token_from_environment(),
        'redirect_uri' => $redirect_uri ?? '(set APP_URL)',
    ],
    'test_to' => $test_to,
]);
