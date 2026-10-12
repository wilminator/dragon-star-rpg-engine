<?php
// A new job or look for a hero (TeamManagement): $what is 'job' or
// 'look'. Answers receive_hero_change(problem or null, message, options,
// hub state).
require_once INCLUDE_GAME . 'team_management.php';
require_once INCLUDE_GAME . 'hub_state.php';

function change_hero($charid = 0, $what = '', $id = 0)
    {
    $db = get_database();
    $playerid = (int) $GLOBALS['userid'];
    [$problem, $message] = match ((string) $what) {
        'job' => TeamManagement::change_job($db, $playerid, (int) $charid, (int) $id),
        'look' => TeamManagement::change_look($db, $playerid, (int) $charid, (int) $id),
        default => ['Change what?', ''],
    };
    queue_response('receive_hero_change', $problem, $message, TeamManagement::options($db, $playerid),
        HubState::for_player($db, $playerid));
    }
?>
