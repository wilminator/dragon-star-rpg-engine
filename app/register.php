<?php
// Public sign-up: username, email, password. The account can log in
// once the emailed link is followed (confirm_email.php).
// Page: templates/account/register.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'account_pages.php';
account_page_start();

$check = HumanCheck::from_environment();
$values = ['done' => false, 'mail_failed' => false, 'problem' => null, 'username' => '', 'email' => '',
    'min_password_length' => UserAccount::MIN_PASSWORD_LENGTH] + bot_check_values($check);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['username'] = trim((string) ($_POST['username'] ?? ''));
    $values['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $bot = bot_check_problem($check, $_POST);

    if (!csrf_valid()) {
        $values['problem'] = 'The form expired. Please try again.';
    } elseif ($bot === 'bot') {
        $values['done'] = true;
    } elseif ($bot !== null) {
        $values['problem'] = $bot;
    } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
        $values['problem'] = "The two passwords don't match.";
    } else {
        $result = account_flow()->sign_up($values['username'], $values['email'], $password, client_ip());
        $values['problem'] = $result['problem'];
        $values['done'] = $result['account'] !== null;
        $values['mail_failed'] = $result['mail_failed'];
    }
}

render('account/register.html.twig', $values);
