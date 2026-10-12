<?php
// The equipment-benefits dialog's data: what equipping inventory item
// $inventory_index to $slot (or, with -1, taking off what's in $slot)
// would do to one of the player's own heroes -- worked out on a copy,
// nothing is changed. See CharacterSheet::preview(). Answers
// receive_equipment_preview(charid, index, slot, preview or null).
require_once INCLUDE_GAME . 'character_sheet.php';

function preview_equipment($charid = 0, $inventory_index = -1, $slot = '')
    {
    $character = CharacterSheet::for_player(get_database(), (int) $GLOBALS['userid'], (int) $charid);
    $preview = $character === null ? null : CharacterSheet::preview($character, (int) $inventory_index, (string) $slot);
    queue_response('receive_equipment_preview', (int) $charid, (int) $inventory_index, (string) $slot, $preview);
    }
?>
