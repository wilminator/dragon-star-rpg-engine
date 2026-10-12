<?php
require_once 'php_js/spectator_common.php';

// What a spectator asks while waiting for the next round. Read-only:
// unlike a participant's check_fight_timeout, this never processes an
// expired round, so spectators can never advance a fight.
function poll_spectator_data($fight_guid = null, $party = null, $sequence = 0, $team_guid = null)
    {
    $spectator = spectator_open($fight_guid, $party, $team_guid);
    if ($spectator === null)
        return;
    $result = $spectator->poll((int) $sequence, spectator_exit_url());
    switch ($result['status'])
        {
        case 'waiting':
            queue_response('receive_spectator_wait', $result['sequence'], $result['timeout']);
            break;
        case 'updated':
            spectator_queue_view($result);
            break;
        case 'ended':
            queue_response('receive_spectator_end', $result['redirect']);
            break;
        }
    }
?>
