<?php
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'fight_communicator.php';

// A participant's client has finished playing back round $sequence
// (round 0: finished loading). Once every player with a living
// character has said so, the round's deadline drops from the playback
// fallback to the command-entry time -- see
// FightCommunicator::acknowledge_playback().
//
// Answers with wait_for_fight_timeout, which refreshes the player's
// timer and keeps checking while they enter commands, so the lowered
// deadline reaches them even though they haven't committed yet.
function ack_playback($sequence = 0)
    {
    $fight_communicator = new FightCommunicator(get_database(), $GLOBALS['userid']);
    $result = $fight_communicator->acknowledge_playback((int) $sequence);
    switch ($result['status'])
        {
        case 'acknowledged':
            queue_response('wait_for_fight_timeout', $result['seconds_remaining']);
            break;
        case 'stale':
            // A newer round already exists; fetch it.
            queue_response('request_fight_data');
            break;
        }
    }
?>
