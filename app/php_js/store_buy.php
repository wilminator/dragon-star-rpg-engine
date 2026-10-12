<?php
// Buying in the shop (Store::buy()): answers receive_store_trade(problem
// or null, message, hub state).
require_once INCLUDE_GAME . 'store.php';
require_once INCLUDE_GAME . 'hub_state.php';

function store_buy($itemid = 0, $charid = 0, $qty = 0)
    {
    $db = get_database();
    $playerid = (int) $GLOBALS['userid'];
    [$problem, $message] = Store::buy($db, $playerid, (int) $itemid, (int) $charid, (int) $qty);
    queue_response('receive_store_trade', $problem, $message, HubState::for_player($db, $playerid));
    }
?>
