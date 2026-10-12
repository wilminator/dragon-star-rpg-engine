<?php
// A step in the formation (PartyState::move()): 'l', 'r', 'u' or 'd'
// for the hero at $group/$position. Answers receive_hub_state(state).
require_once INCLUDE_GAME . 'hub_state.php';

function move_hero($group = 0, $position = 0, $direction = '')
    {
    $db = get_database();
    PartyState::move($db, (int) $GLOBALS['userid'], (int) $group, (int) $position, (string) $direction);
    queue_response('receive_hub_state', HubState::for_player($db, (int) $GLOBALS['userid']));
    }
?>
