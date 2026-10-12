<?php
// For admins holding PLAY_AGAINST: take control of a live fight's
// monsters (action=take, from active_fights.php), or hand them back to
// the AI and go back to watching (action=leave, from the fight screen).
// An admin earns no tokens from a fight they took over. See MonsterSide.

define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_membership_access('dse', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');
if (!check_permission($userid, 'dse', 'PLAY_AGAINST') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_once INCLUDE_GAME . 'monster_side.php';
require_once INCLUDE_GAME . 'csrf.php';

$db = get_database();
$fightid = (int) ($_POST['fightid'] ?? 0);
if (!csrf_valid()) {
    header('Location: active_fights.php?problem=' . urlencode('The form expired. Please try again.'));
    exit;
}

switch ((string) ($_POST['action'] ?? '')) {
    case 'take':
        $problem = MonsterSide::take_over($db, $fightid, (int) $userid);
        if ($problem !== null) {
            header('Location: active_fights.php?problem=' . urlencode($problem));
            exit;
        }
        $_SESSION['fightid'] = $fightid;
        header('Location: ../rpg.php');
        exit;

    case 'leave':
        $state = FightPlayerState::load($db, $fightid, (int) $userid);
        MonsterSide::leave_to_spectate($db, $fightid, (int) $userid);
        unset($_SESSION['fightid']);
        try {
            $guid = FIGHT::load($db, $fightid)->guid;
        } catch (\Exception $e) {
            header('Location: active_fights.php');
            exit;
        }
        header('Location: ../spectate_fight.php?fight=' . urlencode($guid) . '&party=' . (int) ($state?->player_party ?? 0));
        exit;
}
header('Location: active_fights.php');
