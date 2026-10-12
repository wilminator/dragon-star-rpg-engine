<?php
// What a hero can use or give between fights (FieldUse::options()):
// answers receive_field_options(options, or null for a hero not in the
// player's party).
require_once INCLUDE_GAME . 'field_use.php';

function field_options($charid = 0)
    {
    queue_response('receive_field_options', FieldUse::options_for(get_database(), (int) $GLOBALS['userid'], (int) $charid));
    }
?>
