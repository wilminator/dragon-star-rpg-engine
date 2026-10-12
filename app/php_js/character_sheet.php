<?php
// The status dialog's data for one of the player's own heroes: see
// CharacterSheet. Answers receive_character_sheet(sheet), or
// receive_character_sheet(null) for a hero that isn't theirs.
require_once INCLUDE_GAME . 'character_sheet.php';

function character_sheet($charid = 0)
    {
    $character = CharacterSheet::for_player(get_database(), (int) $GLOBALS['userid'], (int) $charid);
    queue_response('receive_character_sheet', $character === null ? null : CharacterSheet::sheet($character));
    }
?>
