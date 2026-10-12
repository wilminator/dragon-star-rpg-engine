<?php
// Sends a password-reset link (reset_password.php) to an account's
// email -- answering the same way whether or not the address has an
// account -- or, for an account using two-factor sign-in, takes a code
// from the authenticator app and goes straight to reset_password.php. Page: templates/account/email_request.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'account_pages.php';
account_page_start();

$check = HumanCheck::from_environment();
$values = [
    'heading' => 'Forgot your password?',
    'explanation' => "Enter your account's email address and we'll send you a link to choose a new password.",
    'done_message' => "If that address has an account, we've sent it a link to reset the password.",
    'action' => 'forgot_password.php',
    'done' => false, 'problem' => null, 'email' => '',
    'code_form' => ['who' => '', 'problem' => null],
] + bot_check_values($check);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['with'] ?? '') === 'code') {
    // With an authenticator code instead: straight to the reset page.
    $values['code_form']['who'] = trim((string) ($_POST['who'] ?? ''));
    if (!csrf_valid()) {
        $values['code_form']['problem'] = 'The form expired. Please try again.';
    } else {
        $result = account_flow()->reset_with_code($values['code_form']['who'], (string) ($_POST['code'] ?? ''), client_ip());
        if ($result['token'] !== null) {
            header('Location: reset_password.php?token=' . $result['token']);
            exit;
        }
        $values['code_form']['problem'] = $result['problem'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['email'] = trim((string) ($_POST['email'] ?? ''));
    $bot = bot_check_problem($check, $_POST);
    if (!csrf_valid()) {
        $values['problem'] = 'The form expired. Please try again.';
    } elseif ($bot === 'bot') {
        $values['done'] = true;
    } elseif ($bot !== null) {
        $values['problem'] = $bot;
    } elseif (!account_flow()->request_password_reset($values['email'], client_ip())) {
        $values['problem'] = 'Too many emails asked for. Please try again later.';
    } else {
        $values['done'] = true;
    }
}

render('account/email_request.html.twig', $values);
