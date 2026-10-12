<?php

/**
 * function.php
 * global functions
 * @version $Id$
 * @copyright 2003
 **/

require_once INCLUDE_GAME . 'ai.php';
require_once INCLUDE_GAME . 'pointer_index.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'character.php';


function randomize(int $value, int $deviation = 15): int {
    return (int)floor(mt_rand(100 - $deviation, 100 + $deviation) * $value / 100.0);
}

function make_input(string $name, string $objval, ?array $attrs = null): void {
    $objval = htmlspecialchars($objval);
    echo "<input name=\"$name\" value=\"{$objval}\"";
    if (is_array($attrs))
        foreach ($attrs as $attr => $value) {
            echo " $attr=\"$value\"";
        }
    echo ">\n";
}

function make_textarea($name, $objval, $attrs = null): void {
    $objval = htmlspecialchars($objval);
    echo "<textarea name=\"$name\"";
    if (is_array($attrs))
        foreach ($attrs as $attr => $value) {
            echo " $attr=\"$value\"";
        }
    echo ">{$objval}</textarea>";
}

function make_select(string $name, string $objval, array $source, ?array $attrs = null): void {
    echo "<select name=\"$name\"";
    if (is_array($attrs))
        foreach ($attrs as $attr => $value) {
            echo " $attr=\"$value\"";
        }
    echo ">\n";
    foreach ($source as $value => $tag) {
        $tag = htmlentities($tag);
        $value = htmlentities($value);
        echo "<option value=\"$value\"" . ($objval == $value ? ' selected' : '') . ">$tag</option>\n";
    }
    echo "</select>\n";
}

function make_checkbox(string $name, bool $checked, ?array $attrs = null): void {
    echo "<input type=\"checkbox\" name=\"$name\"";
    if ($checked)
        echo " checked=\"checked\"";
    if (is_array($attrs))
        foreach ($attrs as $attr => $value)
            echo " $attr=\"$value\"";
    echo ">\n";
}

function sum_n(int $n): int {
    return (int)($n * ($n + 1) / 2);
}

function count_ones(int $value): int {
    $count = 0;
    while ($value > 0) {
        if ($value & 1) {
            $count++;
        }
        $value = $value >> 1;
    }
    return $count;
}

function make_hero(string $name, int $jobid, int $level, int $personalityid, array $inventory, array $equipment, int $charid = 0): CHARACTER {
    $character = CHARACTER::create_hero($name, $jobid, $level, $personalityid, $charid);
    foreach ($inventory as $item)
        $character->add_item($item['item'], $item['qty']);
    foreach ($equipment as $equipment_slot)
        $character->equip_item($equipment_slot['slot'], $equipment_slot['side']);
    if (!is_null($character->equipment['lhand']) && is_null($character->equipment['rhand']))
        $character->command = 0;
    else
        $character->command = 1;
    return $character;
}

function make_hero_party($hero_array) {
    $groups = array();
    foreach ($hero_array as $index => $hero) {
        list($name, $job, $level, $personality, $group, $inventory, $equipment) = $hero;
        $character = make_hero($name, $job, $level, $personality, $inventory, $equipment, $index);
        if (!isset($groups[$group]))
            $groups[$group] = new GROUP;
        while ($groups[$group]->add_hero($character)) {
            $group = ($group + 1) % 3;
        }
    }
    $party = new PARTY;
    foreach (array_keys($groups) as $index)
        $party->add($groups[$index]);
    return $party;
}

function array_keys_strict($haystack, $needle) {
    $retval = array();
    foreach ($haystack as $key => $value)
        if ($value === $needle)
            $retval[] = $key;
    return $retval;
}

function ability_sort($a, $b) {
    $a_name = ABILITY::get($a)->name;
    $b_name = ABILITY::get($b)->name;
    if ($a_name > $b_name) {
        return 1;
    } elseif ($a_name < $b_name) {
        return -1;
    }
    return 0;
}

function find_slot(string $slot, ?int $side): string {
    if (in_array($slot, array('hand', 'ammo', 'arm')))
        return ($side == 0 ? 'l' : 'r') . $slot;
    return $slot;
}

function halflife($start, $end) {
    if (is_null($end))
        $final = 2;
    else
        $final = 2 - pow(.5, $end - 1);
    if ($start < 1)
        return $final;
    $initial = 2 - pow(.5, $start - 1);
    return $final - $initial;
}

function speed_multiplier($times) {
    return ($times + 1) / 2.0;
}

function process_fight(FIGHT $fight, &$sequence, $players) {
    //Determine which parties have NPCs.
    log_debug('fight', 'Processing a fight round', fn() => ['players' => $players]);
    $pc_teams = array();
    $player_as_monster = array();
    foreach ($players as $player)
        if ($player['teamid'] > 0)
            $pc_teams[] = $player['teamid'];
        else
            $player_as_monster[] = $player['player_party'];
    log_debug('fight', 'Who controls what', fn() => ['players' => $players, 'pc_teams' => $pc_teams, 'player_monsters' => $player_as_monster]);

    //Give NPCs a job to do.
    foreach ($fight->parties as $ptynumber => $party)
        if (!in_array($ptynumber, $player_as_monster)) {
            foreach ($party->groups as $grpnumber => $group)
                foreach ($group->characters as $chrnumber => $hero)
                    //If not dead and not a controlled team or part of a player-monster party, then process
                    if (
                        !$fight->parties[$ptynumber]->groups[$grpnumber]->characters[$chrnumber]->is_dead()
                        && (is_null($fight->parties[$ptynumber]->groups[$grpnumber]->characters[$chrnumber]->teamid))
                        || !in_array($fight->parties[$ptynumber]->groups[$grpnumber]->characters[$chrnumber]->teamid, $pc_teams)
                    ) {
                        #Hey, let's try the AI!
                        $fight->set_npc_action($ptynumber, $grpnumber, $chrnumber);
                        log_debug('fight', "Ran AI for $ptynumber-$grpnumber-$chrnumber");
                    } else {
                        log_debug('fight', "Skipped AI for $ptynumber-$grpnumber-$chrnumber",
                            fn() => ['teamid' => $fight->parties[$ptynumber]->groups[$grpnumber]->characters[$chrnumber]->teamid]);
                    }
        }


    //generate a combat sequence to playback.
    $action_list = $fight->do_combat();
    $sequence++;
    return $action_list;
}

// $player_party = index to player's party.
function convert_action_list_to_playlist(array $action_list, int $player_party, ActionScope $base_object): array {
    $playback_actions = array();
    $parties = $base_object->get_parties();

    $grouped_lists = preprocess_action_list($action_list);
    foreach ($grouped_lists as $index => $group) {
        $id_list = $group['id'];
        $message_list = $group['message'];
        $effect_list = $group['effect'];
        $event_list = $group['event'];

        if (count($event_list) == 0 && count($effect_list) == 0) {
            continue;
        }

        // Get the actor, recipient, and range.
        list($actor, $recipient, $range) = process_id_actions($id_list);
        list($party_index, $group_index, $char_index) = $actor->to_array();

        //Get this actor's personality.
        $hero_personality = PERSONALITY::get($actor->personalityid);

        //Process the event list.
        foreach ($event_list as $event_index => $event) {
            $type = $event[0];
            $data = $event[1];
            switch ($type) {
                case 'EquipSlot':
                    $item = $data[0];
                    $slot = $data[1];
                    $playback_actions[] = array(0, 'equip_item', $party_index, $group_index, $char_index, $item, $slot);
                    break;
                case 'UnequipSlot':
                    $slot = $data[0];
                    $playback_actions[] = array(0, 'unequip_item', $party_index, $group_index, $char_index, $slot);
                    break;
                case 'UseItem':
                    $item = $data[0];
                    $playback_actions[] = array(0, 'consume_item', $party_index, $group_index, $char_index, $item, true);
                    break;
                case 'ExpendAmmo':
                    $ammo = $data[0];
                    $playback_actions[] = array(0, 'consume_item', $party_index, $group_index, $char_index, $ammo, false);
                    break;
                case 'AlterStat':
                    $target_party_index = $data[0];
                    $target_group_index = $data[1];
                    $target_char_index = $data[2];
                    $stat = $data[3];
                    $amount = $data[4];
                    $playback_actions[] = array(0, 'alter_stat', $target_party_index, $target_group_index, $target_char_index, $stat, $amount);
                    break;
                case 'GiveItem':
                    $target_party_index = $data[0];
                    $target_group_index = $data[1];
                    $target_char_index = $data[2];
                    $qty = $data[3];
                    $playback_actions[] = array(0, 'give_item', $target_party_index, $target_group_index, $target_char_index, $qty);
                    break;
            }
        }

        //Move main actor on screen and remove the Turn effect.
        $action = array_shift($id_list);
        $playback_actions[] = array(0, 'present_group', $party_index, $group_index);
        if ($recipient) {
            list($target_party_index, $target_group_index, $target_char_index) = $recipient->to_array();
        }
        //If there is an effect, then pull the group id for it.
        //Otherwise, just use 'Target'.
        if (count($effect_list) > 0) {

            $effect = split_index_key($effect_list[0]['group']);
            $target_party_index = $effect[0];
            $target_group_index = $effect[1];
        }
        /*
        #!!IMPORTANT!!#
        Either this action affects the same or opposite sides.
        If the action effects the opposite sides,
            ($party_index==$player_party)^($target_party_index==$player_party)
        then show the other team, delay, then do the action sequence.
        If not, then do the action sequence THEN call the party.
        */
        if (($party_index == $player_party) ^ ($target_party_index == $player_party)) {
            $playback_actions[] = array(0, 'present_group', $target_party_index, $target_group_index);
            $playback_actions[] = array(1);
            $seen = true;
        } else
            $seen = false;
        //Set the function call and target stack.
        $target_list = array();
        $impact_animation = null;
        //Do action sequence (the attack actions)
        foreach ($message_list as $event_index => $event) {
            $type = $event[0];
            $data = $event[1];
            switch ($type) {
                //Starting actions (messages)
                case 'Attack':
                    $item = ITEM::get($data[0]);
                    //Call js that makes the actor attack
                    switch ($item->fight_effect_type) {
                        case 'close':
                            $playback_actions[] = $hero_personality->attack_close->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                            break;
                        case 'throw':
                            $playback_actions[] = $hero_personality->attack_throw->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                            break;
                        case 'shoot':
                            $playback_actions[] = $hero_personality->attack_shoot->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                            break;
                    }
                    //Set the js that shows the impact of the attack (sword slashes, etc)
                    $impact_animation = $item->fight_animation_effect;
                    break;
                case 'Item':
                    $item = ITEM::get($data);
                    //Show title
                    $playback_actions[] = array(0, 'set_fight_message', $item->name);
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    //Call the js that shows the actor using the item
                    $playback_actions[] = $hero_personality->item->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                    //standard JS that causes the message to dissapear before continuing.
                    $playback_actions[] = array(1);
                    //Set the js that shows the impact of the item (bomb tossed, explosions, etc)
                    $item->use_animation_effect;
                    break;
                case 'Spell':
                    $spell = ABILITY::get($data);
                    //Show title
                    $playback_actions[] = array(0, 'set_fight_message', $spell->name);
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    //Call JS that makes the person cast a spell.
                    //Call the js that shows the actor casting the spell
                    $playback_actions[] = $hero_personality->spell->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                    //standard JS that causes the message to dissapear before continuing.
                    $playback_actions[] = array(1);
                    //Set the js that shows the impact of the spell
                    $impact_animation = $spell->impact;
                    break;
                case 'Skill':
                    $skill = ABILITY::get($data);
                    //Show title
                    $playback_actions[] = array(0, 'set_fight_message', $skill->name);
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    //Call the js that shows the actor using the skill
                    $playback_actions[] = $hero_personality->skill->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                    //standard JS that causes the message to dissapear before continuing.
                    $playback_actions[] = array(1);
                    //Call js that makes the actor attack
                    switch ($skill->skill_effect_type) {
                        case 'close':
                            $playback_actions[] = $hero_personality->attack_close->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                            break;
                        case 'throw':
                            $playback_actions[] = $hero_personality->attack_throw->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                            break;
                        case 'shoot':
                            $playback_actions[] = $hero_personality->attack_shoot->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                            break;
                    }
                    //Set the js that shows the impact of the skill
                    $impact_animation = $skill->impact;
                    break;
                case 'Equip':
                    $itemid = $data[0];
                    $ammo = $data[1];
                    $fight_message = 'Equipping ' . ITEM::get($itemid)->name;
                    if ($ammo)
                        $fight_message .= 'and ' . ITEM::get($ammo)->name;
                    //Call the js that shows the actor changing equipment
                    $playback_actions[] = $hero_personality->equip->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                    //Should ba a part of personality -OR- a
                    //standard JS that uses a standard pic.
                    $playback_actions[] = array(0, 'set_fight_message', $fight_message);
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    $playback_actions[] = array(1);
                    break;
                case 'Unequip':
                    $itemid = $data[0];
                    $ammo = $data[1];
                    $fight_message = 'Removing ' . ITEM::get($itemid)->name;
                    //Call the js that shows the actor changing equipment
                    $playback_actions[] = $hero_personality->equip->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                    //Should ba a part of personality -OR- a
                    //standard JS that uses a standard pic.
                    $playback_actions[] = array(0, 'set_fight_message', $fight_message);
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    $playback_actions[] = array(1);
                    break;
                case 'Defend':
                    break;
                case 'Run':
                    $playback_actions[] = array(0, 'set_fight_message', 'Running Scared!');
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    //Call the js that shows the actor running
                    $playback_actions[] = $hero_personality->flee->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                    $playback_actions[] = array(1);
                    break;
                case 'Give':
                    list($itemid, $given) = $data;
                    $fight_message = 'Giving ' . ITEM::get($itemid)->name;
                    $playback_actions[] = array(0, 'set_fight_message', $fight_message);
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    //Call the js that shows the actor changing equipment
                    $playback_actions[] = $hero_personality->equip->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index);
                    $playback_actions[] = array(1);
                    break;
                case 'Receive':
                    list($given, $target_party_index, $target_group_index, $target_char_index) = $data;
                    if ($given == 0) {
                        $fight_message = 'Full';
                    } elseif ($given == 1) {
                        $fight_message = 'Taken';
                    } else {
                        $fight_message = "$given taken";
                    }
                    $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                    $target_personality = PERSONALITY::get($target->personalityid);
                    $playback_actions[] = array(0, 'set_fight_message', $fight_message);
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $target_party_index, $target_group_index, $target_char_index);
                    //Call the js that shows the actor changing equipment
                    $playback_actions[] = $target_personality->equip->to_playback_action($target_party_index, $target_group_index, $target_char_index, $party_index, $group_index, $char_index);
                    $playback_actions[] = array(1);
                    break;
                case 'NoMP':
                    $playback_actions[] = array(0, 'set_fight_message', 'Not enough MP');
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    $playback_actions[] = array(1);
                    break;
                case 'NoAmmo':
                    $playback_actions[] = array(0, 'set_fight_message', 'Not enough MP');
                    $playback_actions[] = array(0, 'show_fight_message_by_hero', $party_index, $group_index, $char_index);
                    $playback_actions[] = array(1);
                    break;
            }
        }
        foreach ($effect_list as $event_index => $effects) {
            $effect = split_index_key($effects['group']);
            $pty = $effect[0];
            $grp = $effect[1];
            if ($target_party_index != $pty || $target_group_index != $grp || $seen == false) {
                $playback_actions[] = array(0, 'present_group', $pty, $grp);
                $playback_actions[] = array(1);
                $seen = true;
            }
            //process_effects($playback_actions,$effects['effects'],$pre_effect_js,$impact_js,$base_object,$range);
            //$playback_actions[]=array(0,impact_js,pindex,gindex,cindex,tpindex,tgindex,tcindex,range,effects['effects']]);
            //Call the js that shows the impact on the target(s)
            if (!is_null($impact_animation)) {
                $playback_actions[] = $impact_animation->to_playback_action($party_index, $group_index, $char_index, $target_party_index, $target_group_index, $target_char_index, $range, $effects['effects']);
            } else {
                error_log('No impact data');
            }
            $playback_actions[] = array(1);
        }
    }
    log_debug('fight', 'Storing playback actions', fn() => $playback_actions);
    return $playback_actions;
}

function preprocess_action_list(array &$action_list): array {
    $retval = array();

    while (count($action_list) > 0) {
        //Make a list of just one fighter's actions.
        $action_group = array();
        do {
            $action_group[] = array_shift($action_list);
        } while (count($action_list) > 0 && $action_list[0][0] != "Turn");
        //Run thru the list again.  Now separate into lists of
        //Things that just need to be done and things that affect
        //others.
        $event_list = array();  //Lists things like ammo use, item loss, slot mods
        $effect_list = array(); //Lists things like damage, equipping
        $message_list = array(); //Lists what is about to be done.
        $id_list = array();     //Lists who does the action and who is the primary target.
        foreach ($action_group as $index => $action) {
            switch ($action[0]) {
                case 'Attack':
                case 'Item':
                case 'Spell':
                case 'Skill':
                case 'Equip':
                case 'Unequip':
                case 'Defend':
                case 'Run':
                case 'NoMP':
                case 'NoAmmo':
                case 'Give':
                case 'Receive':
                    $message_list[] = $action;
                    break;
                case 'Turn':
                case 'Target':
                    $id_list[] = $action;
                    break;
                case 'Miss':
                case 'Damage':
                case 'Restore':
                case 'Died':
                case 'Revived':
                case 'NoEffect':
                    $effect_list[] = $action;
                    break;
                case 'EquipSlot':
                case 'UnequipSlot':
                case 'UseItem':
                case 'ExpendAmmo':
                case 'UseMP':
                case 'AlterStat':
                case 'GiveItem':
                    $event_list[] = $action;
                    break;
                case 'FightDialog':
                    // The fight's closing words: shown by the page as a
                    // dialog, nothing to say in the text.
                    break;
                default:
                    log_error("Unknown action in action list. $action[0]");
                    break;
            }
        }
        //Sub-parse the effect list into groups
        $groups = array();
        foreach ($effect_list as $index => $effect) {
            $pty = $effect[1][0];
            $grp = $effect[1][1];
            $target = $pty . ';' . $grp;
            if (!in_array($target, $groups))
                $groups[$target] = array();
            $groups[$target][] = $effect;
        }
        $effect_list = array();
        foreach ($groups as $group => $effects)
            $effect_list[] = array('group' => $group, 'effects' => $effects);
        //Only bother if there are messages, other events, or effects (as
        //the client's own preprocess_action_list(): a turn that only says
        //something -- "doesn't have enough MP" -- still says it).
        if (count($message_list) > 0 || count($event_list) > 0 || count($effect_list) > 0)
            $retval[] = array('id' => $id_list, 'message' => $message_list, 'effect' => $effect_list, 'event' => $event_list);
    }
    return $retval;
}

function process_id_actions(array $id_list): array {
    $actor = $recipient = $range = null;
    while ($id_list) {
        $action = array_shift($id_list);
        switch ($action[0]) {
            case 'Turn':
                $actor = new ActionTarget(...$action[1]);
                break;
            case 'Target':
                list($target_party_index, $target_group_index, $target_char_index, $range) = $action[1];
                $recipient = new ActionTarget($target_party_index, $target_group_index, $target_char_index);
        }
    }
    return [$actor, $recipient, $range];
}

function get_target_names(ActionScope $scope, ActionTarget $actor, ?ActionTarget $recipient, EffectSpec $effect_spec): string {
    if ($recipient) {
        switch ($effect_spec->targets) {
            case EffectSpec::TARGET_ALL_PARTIES:
                return " all parties";
            case EffectSpec::TARGET_ALL_ENEMIES:
                return " all enemies";
            case EffectSpec::TARGET_ALL_ALLIES:
                return " all allies";
            case EffectSpec::TARGET_PARTY:
                $party = $scope->get_parties()[$recipient->party_index];
                return ($actor->party_index != $recipient->party_index) ? " {$party->name}" : '';
            case EffectSpec::TARGET_GROUP:
                $group = $recipient->get_group($scope);
                return " {$group->name}";
            case EffectSpec::TARGET_INDIVIDUAL:
                // Only display the target if the actor is not the target.
                $target = $recipient->get_character($scope);
                return ($actor->get_character($scope) != $target) ? " {$target->name}" : '';
            default:
                $names = [];
                foreach ($recipient->expand($scope, $effect_spec) as [$current_target, $target, $intensity, $divisor]) {
                    $names[] = $target->name;
                }
                if (count($names) > 1) {
                    $final_name = array_pop($names);
                    $names[] = 'and ' . $final_name;
                }
                return implode((count($names) > 2 ? ', ' : ' '), $names);
        }
    }
    return '';
}

function convert_action_list_to_text(array $action_list, ActionScope $base_object): array {
    $playlist = [];
    $parties = $base_object->get_parties();
    foreach (preprocess_action_list($action_list) as $group) {
        $id_list = $group['id'];
        $message_list = $group['message'];
        $effect_list = $group['effect'];
        $event_list = $group['event'];

        // Get the actor, recipient, and range.
        list($actor, $recipient, $range) = process_id_actions($id_list);
        $character = $actor->get_character($base_object);

        //Announce the action itself.
        $mp_action = false;
        foreach ($message_list as $message) {
            list($type, $data) = $message;
            switch ($type) {
                case 'Attack':
                    $effect_spec = $character->get_weapon_effect_spec($character->command);
                    $target_preposition = get_target_names($base_object, $actor, $recipient, $effect_spec);
                    $playlist[] = ['TextOutput', "{$character->name} attacks" . ($target_preposition !== '' ? " {$target_preposition}" : '') . "."];
                    break;
                case 'Item':
                    $item = ITEM::get($data);
                    $target_preposition = get_target_names($base_object, $actor, $recipient, $item->use_effect_spec);
                    $playlist[] = ['TextOutput', "{$character->name} uses {$item->name}" . ($target_preposition ? " on {$target_preposition}" : '') . "."];
                    break;
                case 'Skill':
                    $ability = ABILITY::get($data);
                    $target_preposition = get_target_names($base_object, $actor, $recipient, $ability->effect_spec);
                    $playlist[] = ['TextOutput', "{$character->name} uses {$ability->name}" . ($target_preposition ? " on {$target_preposition}" : '') . "."];
                    $mp_action = $ability->mp_used > 0;
                    break;
                case 'Spell':
                    $ability = ABILITY::get($data);
                    $target_preposition = get_target_names($base_object, $actor, $recipient, $ability->effect_spec);
                    $playlist[] = ['TextOutput', "{$character->name} casts {$ability->name}" . ($target_preposition ? " on {$target_preposition}" : '') . "."];
                    $mp_action = $ability->mp_used > 0;
                    break;
                case 'Equip':
                    $item = ITEM::get($data[0]);
                    if (count($data) > 1) {
                        $ammo = ITEM::get($data[1]);
                        $playlist[] = ['TextOutput', "{$character->name} equips {$item->name} and {$ammo->name}."];
                    } else {
                        $playlist[] = ['TextOutput', "{$character->name} equips {$item->name}."];
                    }
                    break;
                case 'Unequip':
                    $playlist[] = ['TextOutput', "{$character->name} unequips {$data[0]}."];
                    break;
                case 'Defend':
                    $playlist[] = ['TextOutput', "{$character->name} defends."];
                    break;
                case 'Run':
                    $playlist[] = ['TextOutput', "{$character->name} runs."];
                    break;
                case 'NoMP':
                    $playlist[] = ['TextOutput', "{$character->name} doesn't have enough MP."];
                    break;
                case 'NoAmmo':
                    $playlist[] = ['TextOutput', "{$character->name} ran out of ammo."];
                    break;
                case 'Give':
                    list($itemid) = $data;
                    $item = ITEM::get($itemid);
                    $effect_spec = new EffectSpec(Effect::CAUSE_GOOD_STATUS, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
                    $target_preposition = get_target_names($base_object, $actor, $recipient, $effect_spec);
                    $playlist[] = ['TextOutput', "{$character->name} gives {$item->name}" . ($target_preposition ? " to {$target_preposition}" : '') . "."];
                    break;
                case 'Receive':
                    list($given, $target_party_index, $target_group_index, $target_char_index) = $data;
                    $target = $recipient->get_character($base_object);
                    if ($given == 0) {
                        $playlist[] = ['TextOutput', "{$target->name} cannot take it."];
                    } elseif ($target == $character) {
                        $playlist[] = ['TextOutput', "{$target->name} moves it around in the backpack."];
                    } else {
                        if ($given == 1) {
                            $playlist[] = ['TextOutput', "{$target->name} takes it."];
                        } else {
                            $playlist[] = ['TextOutput', "{$target->name} takes {$given}."];
                        }
                    }
                    break;
            }
        }

        //Placeholder for the eventual animation this action plays.
        $playlist[] = ['Animation', $character?->personalityid, $message_list];
        $playlist[] = ['Sync'];

        //Per-recipient outcomes, already grouped by target group.
        foreach ($effect_list as $group_entry) {
            foreach ($group_entry['effects'] as $effect) {
                list($type, $data) = $effect;
                switch ($type) {
                    case 'Miss':
                        list($target_party_index, $target_group_index, $target_char_index) = $data;
                        $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                        $playlist[] = ['TextOutput', "{$target->name} evades!"];
                        break;
                    case 'Damage':
                        list($target_party_index, $target_group_index, $target_char_index, $amount, $critical) = $data;
                        $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                        $playlist[] = ['TextOutput', "{$target->name} takes $amount damage." . ($critical ? " Critical hit!" : "")];
                        break;
                    case 'Restore':
                        list($target_party_index, $target_group_index, $target_char_index, $stat, $amount) = $data;
                        $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                        if ($amount > 0) {
                            $playlist[] = ['TextOutput', "{$target->name}'s $stat increases by $amount."];
                        } elseif ($amount < 0) {
                            $playlist[] = ['TextOutput', "{$target->name}'s $stat decreases by " . (-$amount) . "."];
                        }
                        break;
                    case 'Revived':
                        list($target_party_index, $target_group_index, $target_char_index) = array_slice($data, 0, 3);
                        $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                        $playlist[] = ['TextOutput', "{$target->name} has revived!"];
                        break;
                    case 'Died':
                        list($target_party_index, $target_group_index, $target_char_index) = array_slice($data, 0, 3);
                        $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                        $playlist[] = ['TextOutput', "{$target->name} has fallen!"];
                        break;
                    case 'NoEffect':
                        list($target_party_index, $target_group_index, $target_char_index) = $data;
                        $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                        $playlist[] = ['TextOutput', "{$target->name} is unaffected."];
                        break;
                }
            }
            $playlist[] = ['Sync'];
        }

        //Side effects: consumption, ammo, stat changes from the action itself.
        foreach ($event_list as $event) {
            list($type, $data) = $event;
            switch ($type) {
                case 'AlterStat':
                    list($target_party_index, $target_group_index, $target_char_index, $stat, $amount) = $data;
                    // If this is the first AlterStat and magic was used, skip it! It's the MP loss for using abilities.
                    if ($mp_action) {
                        $mp_action = false;
                    } elseif ($amount != 0) {
                        $target = $parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                        $playlist[] = ['TextOutput', "{$target->name}'s $stat " . ($amount > 0 ? "increases" : "decreases") . " by " . abs($amount) . "."];
                    }
                    break;
                    //UseItem/ExpendAmmo/EquipSlot/GiveItem are pure client-rendering bookkeeping; no text needed.
            }
        }
    }
    return $playlist;
}

function string_to_array($string, $height, $width) {
    $retval = array();
    $count = 0;
    for ($y = 0; $y < $height; $y++)
        for ($x = 0; $x < $width; $x++) {
            $retval[$y][$x] = ord($string[$count]);
            $count++;
        }
    return $retval;
}

function array_to_string($array, $height, $width) {
    $retval = '';
    for ($y = 0; $y < $height; $y++)
        for ($x = 0; $x < $width; $x++)
            $retval .= chr($array[$y][$x]);
    return $retval;
}
