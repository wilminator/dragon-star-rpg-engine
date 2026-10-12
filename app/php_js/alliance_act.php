<?php
// Buying or ending an alliance (AllianceScreen::act()): answers
// receive_alliance_act(problem or null, message, view, hub state).
require_once INCLUDE_GAME . 'alliance_screen.php';
require_once INCLUDE_GAME . 'hub_state.php';

function alliance_act($action = '', $teamids = [])
    {
    $db = get_database();
    $playerid = (int) $GLOBALS['userid'];
    [$problem, $message] = AllianceScreen::act($db, $playerid, (string) $action, is_array($teamids) ? $teamids : []);
    queue_response('receive_alliance_act', $problem, $message, AllianceScreen::view($db, $playerid),
        HubState::for_player($db, $playerid));
    }
?>
