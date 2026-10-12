<?php
// Using, transferring or discarding an item, or casting a spell or
// using an ability (skill), between fights
// (FieldUse::use()). Answers receive_field_use(problem or null, message,
// hub state, the hero's options now).
require_once INCLUDE_GAME . 'field_use.php';
require_once INCLUDE_GAME . 'hub_state.php';

function use_thing($charid = 0, $kind = '', $index = -1, $group = null, $char = null, $qty = 0)
    {
    $db = get_database();
    $playerid = (int) $GLOBALS['userid'];
    [$problem, $message] = FieldUse::use($db, $playerid, (int) $charid, (string) $kind, (int) $index,
        $group === null ? null : (int) $group, $char === null ? null : (int) $char, (int) $qty);
    queue_response('receive_field_use', $problem, $message, HubState::for_player($db, $playerid),
        FieldUse::options_for($db, $playerid, (int) $charid));
    }
?>
