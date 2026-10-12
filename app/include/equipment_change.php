<?php
require_once INCLUDE_GAME . 'character_sheet.php';
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'field_functions.php';
require_once INCLUDE_GAME . 'fight_communicator.php';

/**
 * Equipping out of combat, for the hub's equip dialog: the same field
 * action setup_equip.php performs (command 3), on the hero in the
 * player's party, saved. Checked first with CharacterSheet::preview(), so
 * only a change the dialog could have shown is made.
 */
final class EquipmentChange {
    // Equips inventory item $index to $slot, or with -1 takes off what's
    // in $slot. Returns [problem or null, message]: the message is what
    // the field action said (plain text).
    public static function make(Database $db, int $playerid, int $charid, int $index, string $slot): array {
        if ((new FightCommunicator($db, $playerid))->is_in_active_fight()) {
            return ['Finish your fight first.', ''];
        }
        $party = PartyState::party($db, $playerid);
        $position = $party?->get_character_position_by_id($charid);
        if ($position === null) {
            return ["That hero isn't in your party.", ''];
        }
        $actor_target = new ActionTarget(0, ...$position);
        $character = $actor_target->get_character($party);
        if (CharacterSheet::preview($character, $index, $slot) === null) {
            return ["That can't go there.", ''];
        }
        $recipient = new ActionTarget($index === -1 ? (int) $character->equipment[$slot] : CharacterSheet::side_of($slot), null, null);
        $said = perform_field_action($party, 3, $index, $actor_target, $recipient);
        $party->store_party($db);
        return [null, trim(html_entity_decode(strip_tags(str_replace('</p>', "\n", $said))))];
    }
}
