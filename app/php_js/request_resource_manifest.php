<?php
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'fight_communicator.php';

function request_resource_manifest($fightid=0)
    {
    $db = get_database();
    $fight_communicator = new FightCommunicator($db, $GLOBALS['userid']);
    // The manifest only needs the current roster (collect_resource_ids()
    // reads $fight->parties directly).
    $result = $fight_communicator->get_fight_view($fightid);
    if($result===null)
        {
        queue_response('fight_terminate','app.php'); // the hub (or team selection, with no team)
        return;
        }

    $resource_ids = $result['fight']->collect_resource_ids();
    queue_response('receive_resource_manifest',$resource_ids['abilities'],$resource_ids['items']);
    }
?>
