<?php
// The hub's shop as a conversation (Store::dialog_for()): answers
// receive_store(dialog text, or null without a party).
require_once INCLUDE_GAME . 'store.php';

function store_open()
    {
    queue_response('receive_store', Store::dialog_for(get_database(), (int) $GLOBALS['userid']));
    }
?>
