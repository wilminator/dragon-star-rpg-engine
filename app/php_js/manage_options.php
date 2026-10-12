<?php
// The hub's Manage team dialog (TeamManagement::options()): answers
// receive_manage_options(options, or null without a party).
require_once INCLUDE_GAME . 'team_management.php';

function manage_options()
    {
    queue_response('receive_manage_options', TeamManagement::options(get_database(), (int) $GLOBALS['userid']));
    }
?>
