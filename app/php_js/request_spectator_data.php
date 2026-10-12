<?php
require_once 'php_js/spectator_common.php';

// A spectator's first request: the fight as it stands (the whole fight,
// for a replay), seated with the requested party. Answers with the same receive_fight_data message a
// participant gets, so the client plays it back the same way.
function request_spectator_data($fight_guid = null, $party = null, $team_guid = null)
    {
    $spectator = spectator_open($fight_guid, $party, $team_guid);
    if ($spectator !== null)
        spectator_queue_view($spectator->get_view(spectator_exit_url($spectator->is_replay, is_string($team_guid) && $team_guid !== '' ? $team_guid : null)));
    }
?>
