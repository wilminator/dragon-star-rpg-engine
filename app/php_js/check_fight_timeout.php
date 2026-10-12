<?php
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'fight_communicator.php';

function check_fight_timeout($sequence_number)
    {
    //Record the error data
    log_debug('fight', "Player {$GLOBALS['userid']} checking the fight timeout");

    $db = get_database();
    $fight_communicator = new FightCommunicator($db, $GLOBALS['userid']);
    $result = $fight_communicator->check_timeout($sequence_number);

    // Not part of this fight at all -- the legacy code never actually
    // checked this (has_timed_out() operated on $_SESSION['fightid']
    // unconditionally, with nothing verifying the calling player
    // belonged to it), a real gap closed here, not a behavior change
    // anyone relied on.
    if ($result['status'] === 'not_in_fight')
        {
        log_error("HACK:\nChecking timeout while not part of an active fight\nClient: $GLOBALS[userid]");
        queue_response('fight_terminate','pick_team.php');
        }
    elseif ($result['status'] === 'stale')
        {
        //We need to reload the fight.
        $view = $fight_communicator->get_fight_view($result['fightid']);
        queue_response('receive_fight_data',$view['sequence'],$view['client_prefight'],$view['action_list'],$view['client_fight'],$view['timeout'],$view['redirect'],$view['round_log'] ?? []);
        }
    elseif ($result['status'] === 'waiting')
        {
        //We are not ready yet.
        queue_response('wait_for_fight_timeout',$result['seconds_remaining'] ?? null);
        }
    else
        {
        //We are the chosen ones- process this fight!
        $view = $fight_communicator->get_fight_view($result['fightid']);
        //Send the response: the raw action list, like every other path.
        //The client builds its own playlist from it; this used to send a
        //server-built playlist, which the client then converted again.
        queue_response('receive_fight_data',$view['sequence'],$view['client_prefight'],$view['action_list'],$view['client_fight'],$view['timeout'],$view['redirect'],$view['round_log'] ?? []);
        }
    }
?>
