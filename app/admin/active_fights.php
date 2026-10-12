<?php
// Every live fight, for admins holding SPECTATE_FIGHTS -- whether or
// not it's open to the public, and including parties a player is
// controlling as monsters. Admins who finish watching a fight are
// returned here (see php_js/spectator_common.php). Admins holding
// PLAY_AGAINST can also take over a fight's monsters (take_over.php).

define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_membership_access('dse', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');
if (!check_permission($userid, 'dse', 'SPECTATE_FIGHTS')) {
    header('Location: index.php');
    exit;
}

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight_spectator.php';
require_once INCLUDE_GAME . 'html/html_spectate.php';

$db = get_database();
$fights = FightSpectator::list_watchable($db, is_admin: true);
// Holders of PLAY_AGAINST may take over monsters no player is present on
// (see MonsterSide::take_over()).
$take_over = null;
if (check_permission($userid, 'dse', 'PLAY_AGAINST')) {
    require_once INCLUDE_GAME . 'monster_side.php';
    require_once INCLUDE_GAME . 'csrf.php';
    $present = [];
    foreach ($fights as $fight) {
        foreach (MonsterSeat::load_all($db, (int) $fight['fightid']) as $seat) {
            if ($seat->present) {
                $present[$fight['fightid']][] = $seat->party_index;
            }
        }
    }
    $take_over = ['url' => 'take_over.php', 'csrf' => csrf_field(), 'present' => $present];
}
html_fight_list('Active fights', $fights, '../spectate_fight.php', 'index.php', show_admin_details: true, take_over: $take_over,
    problem: isset($_GET['problem']) ? (string) $_GET['problem'] : null);
