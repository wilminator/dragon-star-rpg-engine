<?php
// Selling in the shop (Store::sell()): answers receive_store_trade(problem
// or null, message, hub state).
require_once INCLUDE_GAME . 'store.php';
require_once INCLUDE_GAME . 'hub_state.php';

function store_sell($charid = 0, $index = -1, $qty = 0)
    {
    $db = get_database();
    $playerid = (int) $GLOBALS['userid'];
    [$problem, $message] = Store::sell($db, $playerid, (int) $charid, (int) $index, (int) $qty);
    queue_response('receive_store_trade', $problem, $message, HubState::for_player($db, $playerid));
    }
?>
