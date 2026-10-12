<?php
// Where the link confirming a two-factor change leads (see
// TwoFactorChange). Opening the link only shows what it would do; the
// change is made by the button, so a mail scanner that fetches links
// can't confirm it by itself. Page: templates/account/two_factor_change.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'account_pages.php';
require_once INCLUDE_GAME . 'two_factor_change.php';

$changes = new TwoFactorChange(get_database(), mailer());
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$link = $changes->describe_link($token);
$values = ['state' => $link === null ? 'failed' : 'ask', 'token' => $token,
    'username' => $link['username'] ?? '', 'change' => $link['change'] ?? '', 'recovery_codes' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link !== null && csrf_valid()) {
    if (($_POST['decision'] ?? '') === 'cancel') {
        $values['state'] = $changes->cancel($token) ? 'cancelled' : 'failed';
    } else {
        $result = $changes->confirm($token);
        $values['state'] = $result === null ? 'failed' : 'confirmed';
        $values['recovery_codes'] = $result['recovery_codes'] ?? null;
    }
}

render('account/two_factor_change.html.twig', $values);
