<?php
require_once 'php_js/spectator_common.php';

// The spectator's counterpart of request_resource_manifest: every
// ability and item image/sound the fight could use, so the client can
// preload them.
function request_spectator_resource_manifest($fight_guid = null, $party = null, $team_guid = null)
    {
    $spectator = spectator_open($fight_guid, $party, $team_guid);
    if ($spectator === null)
        return;
    $resource_ids = $spectator->collect_resource_ids();
    queue_response('receive_resource_manifest', $resource_ids['abilities'], $resource_ids['items']);
    }
?>
