<?php
require_once INCLUDE_GAME . 'effect_spec.php';
require_once INCLUDE_GAME . 'effects.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'ai_goal.php';
require_once INCLUDE_GAME . 'ai_action.php';
require_once INCLUDE_GAME . 'pointer_index.php';

function ai(&$fight, $pindex, $gindex, $cindex) {
    $index_key = make_index_key($pindex, $gindex, $cindex);
    $fighter = $fight->get_character($index_key);
    $ai_action = $fighter->ai_action;
    $ai = ai2($fight, $pindex, $gindex, $cindex);
    log_debug('ai', "AI choice for $pindex-$gindex-$cindex", fn() => ['choice' => $ai, 'ai_action' => $ai_action]);
    return $ai;
}

function ai2(&$fight, $pindex, $gindex, $cindex) {
    $index_key = make_index_key($pindex, $gindex, $cindex);
    $fighter = $fight->get_character($index_key);
    switch ($fighter->ai_action) {
        case 0: //Stupid
            return ai_action_stupid($fight, $pindex, $gindex, $cindex);
        case 1: //Normal
            return ai_action_normal($fight, $pindex, $gindex, $cindex);
        case 2: //Healer
            return ai_action_healer($fight, $pindex, $gindex, $cindex);
        case 3: //Protector
            //TEMPORARY UNTIL EFFECTS ARE IN PLACE
            return ai_action_healer($fight, $pindex, $gindex, $cindex);
        case 4: //Pummeler
            return ai_action_pummeler($fight, $pindex, $gindex, $cindex);
        case 5: //Fighter
            return ai_action_fighter($fight, $pindex, $gindex, $cindex);
        case 6: //Hinderer
            //TEMPORARY UNTIL EFFECTS ARE IN PLACE
            return ai_action_fighter($fight, $pindex, $gindex, $cindex);
        case 7: //Caster
            return ai_action_caster($fight, $pindex, $gindex, $cindex);
        case 8: //Mage
            return ai_action_mage($fight, $pindex, $gindex, $cindex);
        case 9: //Sharp
            return ai_action_sharp($fight, $pindex, $gindex, $cindex);
        case 10: //Smart
            //TEMPORARY UNTIL EFFECTS ARE IN PLACE
            return ai_action_sharp($fight, $pindex, $gindex, $cindex);
        case 11: //Omnipotent
            //TEMPORARY UNTIL EFFECTS ARE IN PLACE
            return ai_action_sharp($fight, $pindex, $gindex, $cindex);
    }
    return null;
}
function ai_get_effect(CHARACTER &$fighter, $command, $subcommand): ?Effect {
    switch ($command) {
        case 0: //Attack left hand
        case 1: //Attack right hand
            return $fighter->get_weapon_effect_spec($command)->effect;
        case 2: //Use item
            if (!isset($fighter->inventory[$subcommand])) {
                log_error("Bogus inventory subcommand: $subcommand");
                return null;
            }
            $itemid = $fighter->inventory[$subcommand];
            return ITEM::get($itemid)->use_effect_spec->effect;
        case 3: //Equip item
        case 6: //Defend
        case 7: //Flee
        case 8: //Equip weapon and ammo
            return Effect::NONE;
        case 4: //Use skill
        case 5: //Cast spell
            //var_dump($subcommand);
            if (!isset($fighter->abilities[$subcommand])) {
                log_error("Bogus ability subcommand: $subcommand");
                return null;
            }
            $abilityid = $fighter->abilities[$subcommand];
            return ABILITY::get($abilityid)->effect_spec->effect;
    }
    return null;
}

function ai_get_range(&$fighter, $command, $subcommand) {
    switch ($command) {
        case 0: //Attack left hand
            //Calc PXP if this target is attacked
            return $fighter->find_target_range($fighter->equipment['lhand'], $fighter->equipment['lammo']);
        case 1: //Attack right hand
            return $fighter->find_target_range($fighter->equipment['rhand'], $fighter->equipment['rammo']);
        case 2: //Use item
            $itemid = $fighter->inventory[$subcommand];
            return ITEM::get($itemid)->use_effect_spec->targets;
        case 4: //Use skill
        case 5: //Cast spell
            $ability = $fighter->abilities[$subcommand];
            return ABILITY::get($ability)->effect_spec->targets;
        case 3: //Equip item
        case 8: //Equip weapon and ammo
        case 6: //Defend
        case 7: //Flee
            return 0; //This option has a value of 0
    }
    return null;
}

function ai_target(&$fight, $pindex, $gindex, $cindex, &$target_list) {
    $index_key = make_index_key($pindex, $gindex, $cindex);
    $fighter = $fight->get_character($index_key);
    $target = $fighter->ai_target;
    $percent = 100;
    switch ($target) {
        case 0: //Stupid No filtering just return
            return;
        case 1: //Vulture
            //Look for 10%- enemies, if so, then replace target_list
            $new_list = array();
            foreach ($target_list as $key => $rating) {
                list($command, $subcommand, $tpindex, $tgindex, $tcindex) = split_index_key($key);
                if (in_array($tpindex, $fight->parties[$pindex]->get_enemy_parties($fight))) {
                    $index_key = make_index_key($tpindex, $tgindex, $tcindex);
                    $character = $fight->get_character($index_key);
                    if ($character->get_current('HP') > 0 && $character->get_current('HP') / $character->get_base('HP') <= .1)
                        $new_list[$key] = $rating;
                }
            }
            if (count($new_list) > 0)
                $target_list = $new_list;
            return;
        case 3: //Group
            //See if we are the first alive in this group.  If not, then
            //copy from the first NPC alive in this group.
            $index = $cindex - 1;
            while ($index >= 0) {
                $index_key = make_index_key($pindex, $gindex, $index);
                $leader = $fight->get_character($index_key);
                if (!$leader->is_dead()) {
                    list($p, $g, $c) = $leader->target;
                    $command_key = make_index_key($leader->command, $leader->using, $p, $g, $c);
                    $target_list = array($command_key => 0);
                    return;
                }
                $index--;
            }
        case 2: //Normal
            $percent = 50;
            break;
        case 5: //Team
            //See if we are the first alive in this group.  If not, then
            //copy from the first NPC alive in this group.
            $index = $cindex - 1;
            while ($index >= 0) {
                $index_key = make_index_key($pindex, $gindex, $index);
                $leader = $fight->get_character($index_key);
                if (!$leader->is_dead()) {
                    list($p, $g, $c) = $leader->target;
                    $command_key = make_index_key($leader->command, $leader->using, $p, $g, $c);
                    $target_list = array($command_key => 0);
                    return;
                }
                $index--;
            }
        case 4: //Smart
            $percent = 25;
            break;
        case 6: //Wise
            $percent = 10;
            break;
        case 7: //Omnipotent
            $percent = 1;
            break;
    }
    cut_target_list($target_list, $percent);
}

function cut_target_list(&$target_list, $percent) {
    $length = count($target_list);
    $keepers = ceil($length * $percent / 100.0);
    if ($keepers == 0) $keepers = 1;
    $target_list = array_slice($target_list, 0, $keepers);
}

function ai_process_command_on_target(&$fight, &$fighter, $pindex, $command, $subcommand) {
    $hit = false;
    $values = array();
    #Get effect.
    $effect = ai_get_effect($fighter, $command, $subcommand);
    if (is_null($effect)) {
        return $values;
    }
    #Determine if the effect is bad
    $is_detrimental = $effect->is_detrimental();
    foreach (array_keys($fight->parties) as $tpindex) {
        #Determine alignment
        $alignment = ai_get_alignment($fight, $pindex, $tpindex);
        foreach (array_keys($fight->parties[$tpindex]->groups) as $tgindex)
            foreach (array_keys($fight->parties[$tpindex]->groups[$tgindex]->characters) as $tcindex) {
                $index_key = make_index_key($tpindex, $tgindex, $tcindex);
                $target = $fight->get_character($index_key);
                $values[$tpindex][$tgindex][$tcindex] = ai_goal($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental);
                if ($values[$tpindex][$tgindex][$tcindex] != 0) $hit = true;
            }
    }
    //echo "$command $subcommand ";
    if ($hit === false)
        log_error("Oh crap. {$fighter->ai_goal} $is_detrimental $effect " . $effect->only_living_targets());
    //var_dump($values);
    return $values;
}

function ai_process_command_on_selected_targets(&$fight, &$fighter, $pindex, $command, $subcommand, $range, &$targets) {
    $values = array();
    #Get effect.
    $effect = ai_get_effect($fighter, $command, $subcommand);
    if (is_null($effect)) {
        return $values;
    }
    #Determine if the effect is bad
    $is_detrimental = $effect->is_detrimental();
    //Log targets
    log_debug('ai', 'Targets', fn() => $targets);
    foreach (array_keys($fight->parties) as $tpindex) {
        #Determine alignment
        $alignment = ai_get_alignment($fight, $pindex, $tpindex);
        log_debug('ai', "Alignment toward party $tpindex", fn() => ['alignment' => $alignment, 'is_detrimental' => $is_detrimental]);
        foreach (array_keys($fight->parties[$tpindex]->groups) as $tgindex) {
            foreach (array_keys($fight->parties[$tpindex]->groups[$tgindex]->characters) as $tcindex) {
                $index_key = make_index_key($tpindex, $tgindex, $tcindex);
                if (in_array($index_key, $targets)) {
                    $target = $fight->get_character($index_key);
                    $values[$tpindex][$tgindex][$tcindex] = ai_goal($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental);
                } else {
                    $values[$tpindex][$tgindex][$tcindex] = 0;
                }
            }
        }
    }
    //echo "$command $subcommand ";
    //var_dump($values);
    return $values;
}

function ai_get_alignment(&$fight, $pindex, $tpindex) {
    #Determine if the target is an ally
    $ally = in_array($tpindex, $fight->parties[$pindex]->allies) || ($pindex == $tpindex);
    #Determine if the target is an enemy
    $enemy = in_array($tpindex, $fight->parties[$pindex]->enemies);
    #Return our result
    return ($ally ? true : ($enemy ? false : null));
}

function ai_combine_on_range($command, $subcommand, $range, &$values) {
    $retval = array();
    if ($range < EffectSpec::TARGET_PARTY) //Then the affect is auto-targeting and the target does not matter.
    {
        $sum = 0;
        foreach ($values as $tpindex => $party)
            foreach ($party as $tgindex => $group)
                foreach ($group as $tcindex => $value) {
                    if (!is_numeric($value))
                        log_error("Non-numeric value at $tpindex;$tgindex;$tcindex: " . json_encode($values));
                    else
                        $sum += $value;
                }
        $command_key = make_index_key($command, $subcommand, 0, 0, 0);
        $retval[$command_key] = $sum;
    } elseif ($range == EffectSpec::TARGET_PARTY) //Then this is a party target
    {
        foreach ($values as $tpindex => $party) {
            $sum = 0;
            foreach ($party as $tgindex => $group)
                foreach ($group as $tcindex => $value) {
                    if (!is_numeric($value))
                        log_error("Non-numeric value at $tpindex;$tgindex;$tcindex: " . json_encode($values));
                    else
                        $sum += $value;
                }
            $command_key = make_index_key($command, $subcommand, $tpindex, 0, 0);
            $retval[$command_key] = $sum;
        }
    } elseif ($range == EffectSpec::TARGET_GROUP) //Then this is a group target
    {
        foreach ($values as $tpindex => $party)
            foreach ($party as $tgindex => $group) {
                $sum = 0;
                foreach ($group as $tcindex => $value) {
                    if (!is_numeric($value))
                        log_error("Non-numeric value at $tpindex;$tgindex;$tcindex: " . json_encode($values));
                    else
                        $sum += $value;
                }
                $command_key = make_index_key($command, $subcommand, $tpindex, $tgindex, 0);
                $retval[$command_key] = $sum;
            }
    } else //Then this is a ranged attack, target + $range targets on either side
    {
        //var_dump($values);
        foreach ($values as $tpindex => $party)
            foreach ($party as $tgindex => $group)
                foreach ($group as $tcindex => $value) {
                    $sum = 0;
                    for ($index = max(0, $tcindex - $range); $index <= min(count($group) - 1, $tcindex + $range); $index++) {
                        if (!is_numeric($value))
                            log_error("Non-numeric value at $tpindex;$tgindex;$tcindex: " . json_encode($values));
                        else
                            $sum += $value;
                    }
                    $command_key = make_index_key($command, $subcommand, $tpindex, $tgindex, $tcindex);
                    $retval[$command_key] = $sum;
                }
    }
    return $retval;
}
