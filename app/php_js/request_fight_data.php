<?php
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'fight_communicator.php';

function request_fight_data($fightid=0)
    {
    $db = get_database();
    $fight_communicator = new FightCommunicator($db, $GLOBALS['userid']);
    // Explicit fightid or auto-find (fightid=0). A participant's own
    // fight only: watching anyone else's, or a replay, goes through
    // the spectator calls instead.
    $result = $fight_communicator->get_fight_view($fightid);
    //If the fight is not found then bail;
    if($result===null)
        {
        queue_response('fight_terminate','app.php'); // the hub (or team selection, with no team)
        return;
        }
    extract($result);

    //Recalculate combat actions.
    $combat_playback=$action_list;

    log_debug('fight', 'Sending fight data', fn() => ['sequence' => $sequence, 'prefight' => $client_prefight,
        'playback' => $combat_playback, 'fight' => $client_fight, 'timeout' => $timeout]);

    //Send the response
    // client_prefight/client_fight, not the raw prefight/$fight: a
    // freshly-hydrated character only has command/using/target set at
    // all for whoever has a PendingAction row this round (see
    // apply_pending_actions()), and prefight (a raw, dehydrated
    // snapshot) never carries target at all for anyone, own team
    // included (target isn't part of CHARACTER::DEHYDRATE_PROPERTIES).
    // client_fight/client_prefight are what get_fight_view() already
    // built via FIGHT::to_client() to
    // guarantee a real, always-present command/using/target for the
    // player's own team regardless, and correctly omit target for
    // anyone else's, by design (see CHARACTER::to_client()'s own
    // docblock for why).
    queue_response('receive_fight_data',$sequence,$client_prefight,$combat_playback,$client_fight,$timeout,$redirect,$round_log);
    }
?>
