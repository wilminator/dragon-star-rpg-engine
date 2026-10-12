<?php
// One account, for admins holding MANAGE_USERS: change its username or
// email, confirm its email, put it on hold, set a new password, and
// assign its rights. Also lists its teams, each linking to that team's
// fights in the fight browser, and its monster tokens and alliance, with
// a way to grant tokens. Page: templates/admin/user.html.twig.

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
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'view.php';

$db = get_database();
$target = UserAccount::load($db, (int) ($_GET['userid'] ?? 0));
if ($target === null) {
    header('Location: users.php');
    exit;
}

$problems = [];
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $problems[] = 'The form expired. Please try again.';
    } elseif (isset($_POST['grant_tokens'])) {
        $amount = trim((string) $_POST['grant_tokens']);
        if (!ctype_digit($amount) || (int) $amount < 1 || (int) $amount > 100) {
            $problems[] = 'Grant from 1 to 100 tokens.';
        } else {
            MonsterToken::grant($db, $target->userid, (int) $amount, (int) $userid);
            GameLog::write('security', 'notice', 'tokens_granted', "Granted $amount monster tokens to account {$target->userid}.",
                null, ['userid' => (int) $userid], $db);
            $saved = true;
        }
    } else {
        $problems = AccountAdmin::update($db, (int) $userid, $target, [
            'username' => (string) ($_POST['username'] ?? ''),
            'email' => (string) ($_POST['email'] ?? ''),
            'on_hold' => ($_POST['on_hold'] ?? '') === '1',
            'verified' => ($_POST['verified'] ?? '') === '1',
            'permissions' => array_values(array_filter((array) ($_POST['rights'] ?? []), 'is_string')),
            'password' => (string) ($_POST['password'] ?? ''),
            'two_factor_off' => ($_POST['two_factor_off'] ?? '') === '1',
        ]);
        $saved = $problems === [];
    }
}

render('admin/user.html.twig', [
    'account' => AccountAdmin::view($db, $target),
    'all_rights' => PermissionGrant::ALL,
    'teams' => array_map(fn($team) => ['teamid' => $team->teamid, 'name' => $team->name],
        array_values(TEAM::load_all_by_playerid($db, $target->userid))),
    'tokens' => MonsterToken::balance($db, $target->userid),
    'alliance' => (function () use ($db, $target) {
        $alliance = Alliance::load($db, $target->userid);
        return $alliance === null ? null : ['teamids' => $alliance->teamids, 'state' => $alliance->state,
            'minutes_left' => (int) ceil($alliance->seconds_left / 60), 'overtime_minutes' => (int) ceil($alliance->overtime / 60)];
    })(),
    'created' => isset($_GET['created']),
    'saved' => $saved,
    'problems' => $problems,
]);
