<?php
// What the party could sell in the shop (Store::goods()): answers
// receive_store_goods(goods).
require_once INCLUDE_GAME . 'store.php';

function store_goods()
    {
    queue_response('receive_store_goods', Store::goods(get_database(), (int) $GLOBALS['userid']));
    }
?>
