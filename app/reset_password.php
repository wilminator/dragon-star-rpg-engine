<?php
// Where the password-reset email's link leads: choose a new password.
// Page: templates/account/reset_password.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'account_pages.php';
account_page_start();

$flow = account_flow();
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$account = $flow->reset_link_account($token);
$values = ['done' => false, 'valid' => $account !== null, 'problem' => null, 'token' => $token,
    'username' => $account?->username ?? '', 'min_password_length' => UserAccount::MIN_PASSWORD_LENGTH];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account !== null) {
    $password = (string) ($_POST['password'] ?? '');
    if (!csrf_valid()) {
        $values['problem'] = 'The form expired. Please try again.';
    } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
        $values['problem'] = "The two passwords don't match.";
    } else {
        $values['problem'] = $flow->reset_password($token, $password);
        $values['done'] = $values['problem'] === null;
    }
}

render('account/reset_password.html.twig', $values);
