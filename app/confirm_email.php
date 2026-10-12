<?php
// Where the sign-up email's link leads: confirms the address, after
// which the account can log in. Page: templates/account/confirm_email.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'account_pages.php';

$account = account_flow()->confirm_email((string) ($_GET['token'] ?? ''));

render('account/confirm_email.html.twig', [
    'confirmed' => $account !== null,
    'username' => $account?->username ?? '',
]);
