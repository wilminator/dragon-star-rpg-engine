<?php
// Where the email-change links lead: ?approve= (sent to the current
// address) and ?confirm= (sent to the new one). See AccountFlow.
// Opening a link only shows what it would do; the button does it, so a
// mail scanner that fetches links can't approve a change by itself.
// Page: templates/account/email_change.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'account_pages.php';

$db = get_database();
$flow = account_flow();
$kind = isset($_REQUEST['approve']) ? 'approve' : (isset($_REQUEST['confirm']) ? 'confirm' : null);
$token = $kind === null ? '' : (string) $_REQUEST[$kind];
$purpose = $kind === 'approve' ? AccountToken::EMAIL_CHANGE : AccountToken::EMAIL_NEW;
$found = $kind === null ? null : AccountToken::peek_with_data($db, $token, $purpose);

$values = ['result' => $found === null ? 'failed' : 'ask', 'kind' => $kind ?? '', 'token' => $token,
    'new_email' => (string) ($found['data'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $found !== null && csrf_valid()) {
    if ($kind === 'approve') {
        $new_email = $flow->approve_email_change($token);
        $values['result'] = $new_email === null ? 'failed' : 'approved';
    } else {
        $account = $flow->confirm_new_email($token);
        $values['result'] = $account === null ? 'failed' : 'changed';
    }
}

render('account/email_change.html.twig', $values);
