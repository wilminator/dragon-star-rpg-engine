<?php
// Sends another confirmation link to an account that hasn't confirmed
// its email yet. Answers the same way whether or not the address has
// such an account. Page: templates/account/email_request.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'account_pages.php';
account_page_start();

$check = HumanCheck::from_environment();
$values = [
    'heading' => 'Resend the confirmation email',
    'explanation' => "Enter the address you signed up with and we'll send a new confirmation link.",
    'done_message' => "If that address has an account waiting to be confirmed, we've sent it a new link.",
    'action' => 'resend_confirmation.php',
    'done' => false, 'problem' => null, 'email' => '',
] + bot_check_values($check);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['email'] = trim((string) ($_POST['email'] ?? ''));
    $bot = bot_check_problem($check, $_POST);
    if (!csrf_valid()) {
        $values['problem'] = 'The form expired. Please try again.';
    } elseif ($bot === 'bot') {
        $values['done'] = true;
    } elseif ($bot !== null) {
        $values['problem'] = $bot;
    } elseif (!account_flow()->resend_confirmation($values['email'], client_ip())) {
        $values['problem'] = 'Too many emails asked for. Please try again later.';
    } else {
        $values['done'] = true;
    }
}

render('account/email_request.html.twig', $values);
