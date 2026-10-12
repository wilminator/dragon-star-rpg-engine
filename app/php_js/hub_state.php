<?php
// The hub's picture of the party (HubState): answers
// receive_hub_state(state), or null if the player hasn't picked a team.
require_once INCLUDE_GAME . 'hub_state.php';

function hub_state()
    {
    queue_response('receive_hub_state', HubState::for_player(get_database(), (int) $GLOBALS['userid']));
    }
?>
