<?php
require_once INCLUDE_GAME . 'action_target.php';

function generate_ui_options(CHARACTER $character): array {
    //Build item list
    $item_list = [];
    $full_item_list = [];
    $qty_list = [];
    foreach ($character->inventory as $index => $inventory_slot) {
        $item = ITEM::get($inventory_slot['item']);
        if ($item->use_effect_spec->effect != Effect::NONE)
            $item_list[] = array($index, $item->name, $item->usable_in_field());
        $full_item_list[] = array($index, $item->name, true);
        $qty_list[] = $inventory_slot['qty'];
    }

    //Build spell list
    $skill_list = [];
    $spell_list = [];
    foreach ($character->abilities as $index => $abilityid) {
        $myability = ABILITY::get($abilityid);
        if ($myability->type == ABILITY::TYPE_SKILL) { //is skill
            $skill_list[] = [$index, "{$myability->name} ($myability->mp_used)", $myability->usable_in_field() && ($character->current['MP'] >= $myability->mp_used), $abilityid];
        } else { //is spell
            $spell_list[] = [$index, "{$myability->name} ($myability->mp_used)", $myability->usable_in_field() && ($character->current['MP'] >= $myability->mp_used), $abilityid];
        }
    }
    usort($skill_list, 'ability_list_sort');
    usort($spell_list, 'ability_list_sort');

    // Build the list of available actions.
    $action_list = [];
    if (isset($character)) {
        //Build action list
        if (count($item_list) > 0)
            $action_list[2] = 'Use Item';
        if (count($spell_list) > 0)
            $action_list[5] = 'Cast Spell';
        if (count($skill_list) > 0)
            $action_list[4] = 'Use Skill';
        if (count($character->inventory) > 0) {
            $action_list[9] = 'Give Item';
            $action_list[1001] = 'Sell Item';
        }
    }
    return [$item_list, $full_item_list, $qty_list, $skill_list, $spell_list, $action_list];
}

function ability_list_sort(array $a, array $b) {
    $cmp = - ($a[2] <=> $b[2]);
    if ($cmp !== 0) {
        return $cmp;
    }
    return ($a[1] <=> $b[1]);
}

// Death check exempts:
//   3, 8 -- equip/unequip, pre-fight preparation, no reason to require being alive
//   9    -- give item
//   1001 -- sell item
function perform_field_action(
    PARTY $party,
    int $action,
    int $actionindex,
    ActionTarget $actor_target,
    ActionTarget $recipient_target
): string {
    # Get the actor.
    $actor = $actor_target->get_character($party);

    //If the person is dead, then skip.
    if ($actor->get_current('HP') == 0 && !in_array($action, [3, 8, 9, 1001])) {
        return "{$actor->name} is dead and can't do this action.";
    }

    # Set the command and using values.
    $actor->command = $action;
    $actor->using = $actionindex;
    $actor->target = $recipient_target->to_array();

    # Have the actor perform their action.
    $action_list = [];
    $actor->perform_action($party, $action_list);

    $results = convert_action_list_to_text($action_list, $party);
    $text = array_filter($results, fn($x) => $x[0] == 'TextOutput');
    $p_blocks = array_map(fn($text) => "<p>{$text[1]}</p>", $text);
    return implode("\n", $p_blocks);
}