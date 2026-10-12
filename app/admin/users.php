<?php
// Account search for admins holding MANAGE_USERS: find accounts by
// part of a username or email, or by id; narrow to accounts on hold,
// unconfirmed, or holding a given right; open one to manage it; or
// create an account (ready to log in, no email confirmation needed).
// Page: templates/admin/users.html.twig.

define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_membership_access('dse', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');
if (!check_permission($userid, 'dse', 'MANAGE_USERS')) {
    header('Location: index.php');
    exit;
}

require_once INCLUDE_GAME . 'account_admin.php';
require_once INCLUDE_GAME . 'view.php';

$db = get_database();

// --- Create an account (POST) ---
$create = ['username' => '', 'email' => '', 'rights' => [], 'problem' => null];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $create['username'] = (string) ($_POST['username'] ?? '');
    $create['email'] = (string) ($_POST['email'] ?? '');
    $create['rights'] = array_values(array_filter((array) ($_POST['rights'] ?? []), 'is_string'));
    if (!csrf_valid()) {
        $create['problem'] = 'The form expired. Please try again.';
    } else {
        try {
            $account = AccountAdmin::create($db, $create['username'], (string) ($_POST['password'] ?? ''),
                $create['email'], $create['rights']);
            header('Location: user.php?userid=' . $account->userid . '&created=1');
            exit;
        } catch (InvalidArgumentException $e) {
            $create['problem'] = $e->getMessage();
        }
    }
}

// --- Search ---
$right = (string) ($_GET['right'] ?? '');
$search = [
    'text' => trim((string) ($_GET['q'] ?? '')),
    'on_hold' => ($_GET['on_hold'] ?? '') === '1',
    'unverified' => ($_GET['unverified'] ?? '') === '1',
    'right' => array_key_exists($right, PermissionGrant::ALL) ? $right : '',
];
$accounts = UserAccount::search($db, $search['text'], [
    'on_hold' => $search['on_hold'],
    'unverified' => $search['unverified'],
    'permission' => $search['right'],
]);

render('admin/users.html.twig', [
    'search' => $search,
    'accounts' => array_map(fn($account) => AccountAdmin::view($db, $account), $accounts),
    'all_rights' => PermissionGrant::ALL,
    'create' => $create,
]);
