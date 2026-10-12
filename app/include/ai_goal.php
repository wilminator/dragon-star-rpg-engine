<?php
require_once INCLUDE_GAME . 'effect_spec.php';
require_once INCLUDE_GAME . 'character.php';
require_once INCLUDE_GAME . 'item.php';

function ai_goal(CHARACTER $fighter, CHARACTER $target, int $command, int $subcommand, ?bool $alignment, Effect $effect, bool $is_detrimental) {
    #If this must work on someone who is alive and the target is dead, return 0.
    if ($effect->only_living_targets() && $target->is_dead())
        return 0;
    #Develop a weight
    $weight = ((($alignment === true && !$is_detrimental) || ($alignment === false && $is_detrimental)) ? 1 : -1);
    #Get the value and weight it
    switch ($fighter->ai_goal) {
        case 0: //Random
            return ai_goal_random($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental) * $weight;
        case 1: //Destructor
            return ai_goal_destructor($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental) * $weight;
        case 2: //Schemer
            return ai_goal_schemer($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental) * $weight;
        case 3: //Preventor
            return ai_goal_preventor($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental) * $weight;
        case 4: //Protector
            return ai_goal_protector($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental) * $weight;
    }
    return null;
}

function ai_goal_random(CHARACTER $fighter, CHARACTER $target, int $command, int $subcommand, ?bool $alignment, Effect $effect, bool $is_detrimental) {
    return mt_rand(1, 1000);
}

function ai_goal_protector(CHARACTER $fighter, CHARACTER $target, int $command, int $subcommand, ?bool $alignment, Effect $effect, bool $is_detrimental) {
    return $target->pxp * $target->get_current('HP');
}

function ai_goal_destructor(CHARACTER $fighter, CHARACTER $target, int $command, int $subcommand, ?bool $alignment, Effect $effect, bool $is_detrimental) {
    #Determine the value for this action against this target
    switch ($command) {
        case 0: //Attack left hand
        case 1: //Attack right hand
            $value = ai_weigh_attack_damage($fighter, $target, $command);
            break;
        case 2: //Use item
            $item = ITEM::get($fighter->inventory[$subcommand]['item']);
            $value = ai_weigh_effect_damage($fighter, $target, $item->use_effect_spec);
            break;
        case 4: //Use skill
        case 5: //Cast spell
            $ability = ABILITY::get($fighter->abilities[$subcommand]);
            $value = ai_weigh_effect_damage($fighter, $target, $ability->effect_spec);
            break;
        case 3: //Equip item
        case 8: //Equip weapon and ammo
            //Maybe we should look at this as an option if equipping a better weapon
            //will provide better damage.
            return 0; //This option has a value of 0
        case 6: //Defend
            //Maybe we should look at this as an option if the opposition
            //is strong enough.
            return 0; //This option has a value of 0
        case 7: //Flee
            //Maybe we should look at this as an option if the opposition
            //is strong enough.
            return 0; //This option has a value of 0
        default:
            return null;
    }
    return $value;
}

function ai_goal_schemer(CHARACTER $fighter, CHARACTER $target, int $command, int $subcommand, ?bool $alignment, Effect $effect, bool $is_detrimental) {
    #Determine the value for this action against this target
    switch ($command) {
        case 0: //Attack left hand
        case 1: //Attack right hand
            $value = ai_weigh_attack_damage($fighter, $target, $command)
                * ai_weigh_attack_success($fighter, $target, $command);
            break;
        case 2: //Use item
            $item = ITEM::get($fighter->inventory[$subcommand]['item']);
            $value = ai_weigh_effect_damage($fighter, $target, $item->use_effect_spec);
            break;
        case 4: //Use skill
            $ability = ABILITY::get($fighter->abilities[$subcommand]);
            $value = ai_weigh_effect_damage($fighter, $target, $ability->effect_spec)
                * ai_weigh_attack_success($fighter, $target, $command);
            break;
        case 5: //Cast spell
            $ability = ABILITY::get($fighter->abilities[$subcommand]);
            $value = ai_weigh_effect_damage($fighter, $target, $ability->effect_spec)
                * ai_weigh_cast_success($fighter, $target, $command);
            break;
        case 3: //Equip item
        case 8: //Equip weapon and ammo
            //Maybe we should look at this as an option if equipping a better weapon
            //will provide better damage.
            return 0; //This option has a value of 0
        case 6: //Defend
            //Maybe we should look at this as an option if the opposition
            //is strong enough.
            return 0; //This option has a value of 0
        case 7: //Flee
            //Maybe we should look at this as an option if the opposition
            //is strong enough.
            return 0; //This option has a value of 0
        default:
            return null;
    }
    return $value;
}

function ai_goal_preventor(CHARACTER $fighter, CHARACTER $target, int $command, int $subcommand, ?bool $alignment, Effect $effect, bool $is_detrimental) {
    $value = ai_goal_destructor($fighter, $target, $command, $subcommand, $alignment, $effect, $is_detrimental);
    $hp = $target->get_current('HP');
    if ($hp == 0)
        return 0;
    return floor($value / $hp * 1000);
}

function ai_weigh_attack_success(CHARACTER $fighter, CHARACTER $target, int $command): float {
    $acc = $fighter->get_current('Accuracy', $command);
    $dod = $fighter->get_perceived_stat($target, 'Dodge');
    return $acc / ($acc + $dod);
}

function ai_weigh_attack_damage(CHARACTER $fighter, CHARACTER $target, int $command): int {
    $str = $fighter->get_current('Strength', $command);
    $blo = $fighter->get_perceived_stat($target, 'Block');
    $dmg = ($str - floor($blo / 2));
    #Figure in elemental bonus
    //$dmg*=$GLOBALS['elemental_bonus'][$fighter->get_attack_element($command)][$target->get_defend_element()];
    //Minimum damage is 1.
    if ($dmg < 1)
        $dmg = 1;
    return $dmg;
}

function ai_weigh_effect_damage(CHARACTER $fighter, CHARACTER $target, EffectSpec $effect_spec): ?float {
    switch ($effect_spec->effect) {
        case Effect::NONE: //Nothing
            return 0;
        case Effect::HEAL: //Heal
            if ($target->get_current('HP') == 0)
                return 0;
            //Find amount healed
            $amt = $effect_spec->added / 2 + $effect_spec->base;
            #Figure in elemental bonus
            //$amt*=$GLOBALS['elemental_bonus'][$attribute][$target->get_defend_element()];
            #NOTE:Always return positive.  The calling function handles negative action.
            return $amt;
        case Effect::HURT: //Hurt
            if ($target->get_current('HP') == 0)
                return 0;
            //Find amount damaged
            $dmg = $effect_spec->added / 2 + $effect_spec->base;
            #Figure in elemental bonus
            //$dmg*=$GLOBALS['elemental_bonus'][$attribute][$target->get_defend_element()];
            return $dmg;
        case Effect::REVIVE: //Revive
            if ($target->get_current('HP') == 0)
                return $target->get_current('HP') - $target->get_base('HP');
            $amt = round($target->get_base('HP') * 400.0 / $effect_spec->added);
            #Figure in elemental bonus
            //$amt*=$GLOBALS['elemental_bonus'][${heal attribute}][$target->get_defend_element()];
            #NOTE:Always return positive.  The calling function handles negative action.
            return $amt;
        case Effect::SLAY: //Slay
            break;
        case Effect::INCREASE_STATS: //Increase Stats
            break;
        case Effect::DECREASE_STATS: //Decrease Stats
            break;
        case Effect::STEAL_STATS: //Steal Stats
            break;
        case Effect::CAUSE_GOOD_STATUS: //Cause Good Status
            break;
        case Effect::REMOVE_GOOD_STATUS: //Remove Good Status
            break;
        case Effect::CAUSE_BAD_STATUS: //Cause Bad Status
            break;
        case Effect::REMOVE_BAD_STATUS: //Remove Bad Status
            break;
        case Effect::RESTORE_MP: //Restore MP
            if ($target->get_current('HP') == 0)
                return 0;
            //Find amount healed
            $amt = $effect_spec->added / 2 + $effect_spec->base;
            #NOTE:Always return positive.  The calling function handles negative action.
            return $amt * $amt;
    }
    return null;
}

function ai_weigh_cast_success(CHARACTER $fighter, CHARACTER $target): float {
    $pow = $fighter->get_current('Power');
    $res = $fighter->get_perceived_stat($target, 'Resistance');
    $base = $pow + $res;
    return $pow / $base + min($pow, $res) / $base / 2;
}
