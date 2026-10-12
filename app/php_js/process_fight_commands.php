<?php
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'fight_communicator.php';

function process_fight_commands(int $sequence_number)
    {
    //Record the error data
    log_debug('fight', "Player {$GLOBALS['userid']} processing fight commands");

    $db = get_database();
    $fight_communicator = new FightCommunicator($db, $GLOBALS['userid']);
    $result = $fight_communicator->confirm_round($sequence_number);

    // not_in_fight covers both "never in this fight" and "fight
    // already resolved" -- confirm_round() only ever finds an active
    // fight to advance in the first place, so both legacy cases
    // (get_fight() returning false; $active=="no") collapse into one
    // here, and both produced the identical client response anyway.
    if ($result['status'] === 'not_in_fight')
        {
        log_error("HACK:\nTrying to advance a fight while not part of an active fight\nClient: $GLOBALS[userid]");
        queue_response('fight_terminate','pick_team.php');
        return false;
        }

    if ($result['status'] === 'stale')
        {
        log_debug('fight', "Playerid {$GLOBALS['userid']} tried to update fight {$result['fightid']} to sequence $sequence_number, but fight is already at {$result['sequence']}. Sending refresh.");
        // Explicit fightid, not the no-argument auto-find form: the
        // fight could have become Resolved in the same instant that
        // made this submission stale, which auto-find can't see.
        $view = $fight_communicator->get_fight_view($result['fightid']);
        queue_response('receive_fight_data',$view['sequence'],$view['client_prefight'],$view['action_list'],$view['client_fight'],$view['timeout'],$view['redirect'],$view['round_log'] ?? []);
        return false;
        }

    if ($result['status'] === 'waiting')
        {
        log_debug('fight', "Fight not ready to advance.");
        queue_response('wait_for_fight_timeout');
        return false;
        }

    // 'processed'
    log_debug('fight', "Processed fight {$result['fightid']} sequence {$result['sequence']}");
    $view = $fight_communicator->get_fight_view($result['fightid']);
    queue_response('receive_fight_data',$view['sequence'],$view['client_prefight'],$view['action_list'],$view['client_fight'],$view['timeout'],$view['redirect'],$view['round_log'] ?? []);
    log_debug('fight', "Queued receive_fight_data for player $GLOBALS[userid]");
    return true;
    }
?>
