<?php
// Housekeeping (see include/housekeeping.php), for admins holding
// RESET_DB: what the last run removed, running it now, and how long
// fights nothing keeps are kept. Page: templates/admin/housekeeping.html.twig.

define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_membership_access('dse', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');
if (!check_permission($userid, 'dse', 'RESET_DB')) {
    header('Location: index.php');
    exit;
}

require_once INCLUDE_GAME . 'housekeeping.php';
require_once INCLUDE_GAME . 'view.php';

$db = get_database();
$messages = [];
$problems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_valid()) {
        $problems[] = 'The form expired. Please try again.';
    } elseif ($action === 'settings') {
        $days = trim((string) ($_POST['fight_days'] ?? ''));
        if ($days !== '' && !ctype_digit($days)) {
            $problems[] = 'Days is a whole number, or blank to keep fights for good.';
        } else {
            $problems = Housekeeping::set_untagged_fight_days($db, $days === '' ? null : (int) $days);
            if ($problems === []) {
                $messages[] = 'Saved.';
            }
        }
    } elseif ($action === 'run') {
        $removed = Housekeeping::run($db);
        AppSetting::set($db, Housekeeping::RAN_AT, (string) time());
        $messages[] = 'Ran: removed ' . array_sum($removed) . ' rows.';
    }
}

$fight_days = Housekeeping::untagged_fight_days($db);
render('admin/housekeeping.html.twig', [
    'messages' => $messages,
    'problems' => $problems,
    'last' => Housekeeping::last_result($db),
    'interval_minutes' => intdiv(Housekeeping::INTERVAL, 60),
    'fights_per_run' => Housekeeping::FIGHTS_PER_RUN,
    'fight_days' => $fight_days,
    'fights_due' => $fight_days === null ? null : Housekeeping::count_untagged_fights($db, $fight_days),
    'labels' => [
        'rate_limits' => 'Expired attempt counters',
        'account_tokens' => 'Expired email links and 2FA requests',
        'security_keys' => 'Security keys never confirmed',
        'pending_actions' => "Finished fights' leftover commands",
        'monster_seats' => "Finished fights' monster players",
        'semaphores' => "Finished fights' coordination rows",
        'fights' => 'Fights nothing keeps',
    ],
]);
