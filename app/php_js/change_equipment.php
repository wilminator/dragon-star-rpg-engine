<?php
// The hub's equip dialog, confirmed (EquipmentChange): answers
// receive_equipment_change(problem or null, message, sheet).
require_once INCLUDE_GAME . 'equipment_change.php';

function change_equipment($charid = 0, $inventory_index = -1, $slot = '')
    {
    $db = get_database();
    $playerid = (int) $GLOBALS['userid'];
    [$problem, $message] = EquipmentChange::make($db, $playerid, (int) $charid, (int) $inventory_index, (string) $slot);
    $character = CharacterSheet::for_player($db, $playerid, (int) $charid);
    queue_response('receive_equipment_change', $problem, $message, $character === null ? null : CharacterSheet::sheet($character));
    }
?>
