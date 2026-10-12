<?php
// The inn (Inn::rest()) for every team in the party. Answers
// receive_inn_rest(problem or null, state). Not during a fight: the
// fight has its own copy of the heroes.
require_once INCLUDE_GAME . 'hub_state.php';
require_once INCLUDE_GAME . 'fight_communicator.php';

function rest_at_inn()
    {
    $db = get_database();
    $userid = (int) $GLOBALS['userid'];
    if ((new FightCommunicator($db, $userid))->is_in_active_fight()) {
        queue_response('receive_inn_rest', 'Finish your fight first.', HubState::for_player($db, $userid));
        return;
    }
    $party = PartyState::party($db, $userid);
    if ($party === null) {
        queue_response('receive_inn_rest', 'Pick a team first.', null);
        return;
    }
    $teams = array_map(fn($teamid) => TEAM::load($db, (int) $teamid), array_keys($party->teams));
    $problem = Inn::rest($db, $teams);
    queue_response('receive_inn_rest', $problem, HubState::for_player($db, $userid));
    }
?>
