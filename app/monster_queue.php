<?php
// Fighting as the monsters: join the queue, wait for the next fight
// someone starts (or a practice fight after MonsterQueue::FALLBACK_SECONDS),
// and go to it. The page checks in every couple of seconds (action=poll);
// a player who closes it drops out of the queue. Reached from team
// selection (pick_team.php). See include/monster_side.php.
// Page: templates/monster_queue.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_login(AUTH_LOGIN);
redirect_on_hold($userid, 'dse', 'on_hold.php', 'index.php');

require_once INCLUDE_GAME . 'monster_side.php';
require_once INCLUDE_GAME . 'view.php';

$db = get_database();
// Why the last wait ended without a fight (the page's poll said so).
$problem = match ((string) ($_GET['problem'] ?? '')) {
    'no_teams' => 'There was no practice fight to start: you need an active team with heroes in it.',
    'failed' => "The practice fight couldn't start. The problem has been logged.",
    default => null,
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'poll') {
        header('Content-Type: application/json');
        echo json_encode(csrf_valid() ? MonsterQueue::poll($db, (int) $userid) : ['state' => 'expired']);
        exit;
    }
    if (!csrf_valid()) {
        $problem = 'The form expired. Please try again.';
    } elseif ($action === 'join') {
        $problem = MonsterQueue::join($db, (int) $userid);
    } elseif ($action === 'leave') {
        MonsterQueue::leave($db, (int) $userid);
        header('Location: pick_team.php');
        exit;
    }
}

// Already in a fight (seated as the monsters, or their own): go there.
if (FightPlayerState::find_active_fightid_for_player($db, (int) $userid) !== null) {
    MonsterQueue::leave($db, (int) $userid);
    header('Location: rpg.php');
    exit;
}

render('monster_queue.html.twig', [
    'queued' => MonsterQueue::is_queued($db, (int) $userid),
    'problem' => $problem,
    'tokens' => MonsterToken::balance($db, (int) $userid),
    'fallback_seconds' => MonsterQueue::FALLBACK_SECONDS,
    'lockout_minutes' => intdiv(MonsterLockout::SECONDS, 60),
    'daily_limit' => MonsterToken::DAILY_LIMIT,
]);
