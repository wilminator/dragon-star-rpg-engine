<?php
//Tell where the includes are.
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';

#Globals
require_once INCLUDE_GAME . 'paths.php';
#HTML scritps
require_once INCLUDE_GAME . 'html/html_fight.php';
#Prep the game objects.
require_once INCLUDE_GAME . 'fight.php';

require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'personality.php';
#AI for the monsters
require_once INCLUDE_GAME . 'ai.php';
#We may need the javascript file stack.
require_once INCLUDE_GAME . 'html/html_javascript.php';

//New way!
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'monster_side.php';

require_once INCLUDE_GAME . 'auth.php';

# Watching someone else's fight, or a replay, is spectate_fight.php.
if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

# Do LOGIN stuff :)
$userid = authenticate_login(AUTH_LOGIN);
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

if (isset($_SESSION['fightid']))
    $fightid = $_SESSION['fightid'];
else
    $fightid = 0;

$db = get_database();
$fight_communicator = new FightCommunicator($db, $userid);
$status = $fight_communicator->get_fight_status($fightid);
if ($status === null) {
    header('Location: pick_team.php');
    exit;
}
if (!$status['active']) {
    unset($_SESSION['fightid']);
    header('Location: app.php');
    exit;
}

$_SESSION['fightid'] = $status['fightid'];

# A monster player coming back to a fight they'd left takes control again.
if ($status['teamid'] === CHARACTER::MONSTER_TEAMID) {
    MonsterSide::rejoin($db, $status['fightid'], $userid);
}

// Render the HTML for the combat interface
# An admin controlling monsters can hand them back to the AI.
$overlay = '';
if ($status['teamid'] === CHARACTER::MONSTER_TEAMID && check_permission($userid, 'dse', 'PLAY_AGAINST')) {
    require_once INCLUDE_GAME . 'csrf.php';
    $overlay = '<form method="post" action="admin/take_over.php" style="position:fixed;top:4px;right:4px;z-index:1000">'
        . csrf_field() . '<input type="hidden" name="fightid" value="' . (int) $status['fightid'] . '">'
        . '<button type="submit" name="action" value="leave">Back to spectating</button></form>';
}
html_combat($status['fightid'], $status['player_party'], $status['teamid'], null, null, $status['teamids'], $overlay);
