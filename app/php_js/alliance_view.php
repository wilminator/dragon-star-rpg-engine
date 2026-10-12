<?php
// The hub's Alliance dialog (AllianceScreen::view()): answers
// receive_alliance(view, or null without a team).
require_once INCLUDE_GAME . 'alliance_screen.php';

function alliance_view()
    {
    queue_response('receive_alliance', AllianceScreen::view(get_database(), (int) $GLOBALS['userid']));
    }
?>
