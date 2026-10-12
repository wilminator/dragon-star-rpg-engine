<?php
// Saving the settings popup (PlayerSettings::save()): answers
// receive_settings(settings as saved).
require_once INCLUDE_GAME . 'player_settings.php';

function save_settings($changes = [])
    {
    queue_response('receive_settings',
        // The RPC layer decodes a JSON object as a PHP object.
        PlayerSettings::save(get_database(), (int) $GLOBALS['userid'],
            is_object($changes) ? get_object_vars($changes) : (is_array($changes) ? $changes : [])));
    }
?>
