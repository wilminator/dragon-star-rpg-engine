<?php
require_once INCLUDE_GAME . 'constants.php';
require_once INCLUDE_GAME . 'effect.php';
require_once INCLUDE_GAME . 'effect_spec.php';
require_once INCLUDE_GAME . 'action_target.php';
require_once INCLUDE_GAME . 'character.php';

/**
 * $impact_percentage is computed once, by affected_by(), from
 * ActionTarget::expand()'s (intensity, divisor) pair -- do_effect()
 * used to recompute its own copy independently from
 * $effect_spec->targets, which happened to agree with affected_by()'s
 * value for every target scope (verified empirically) but was still
 * duplicated logic with two different inputs, and could drift apart
 * silently if either side changed. Passed in directly instead now.
 */
function do_effect(
    EffectSpec $effect_spec,
    ActionTarget $fighter_target,
    Character $target,
    ActionTarget $target_target,
    float $impact_percentage,
    float $immunity,
    array &$action_list
) :bool {
    switch ($effect_spec->effect) {
        case EFFECT::NONE: //Nothing
            break;
        case EFFECT::HEAL: //Heal
            if ($target->get_current('HP') == 0)
                return false;
            //Find amount healed
            $amt = mt_rand(0, $effect_spec->added) + $effect_spec->base;
            $target->restore_HP($fighter_target, $target_target, $amt, $action_list);
            break;
        case EFFECT::HURT: //Hurt
            if ($target->get_current('HP') == 0)
                return false;
            //Find amount damaged
            $dmg = (int)floor((mt_rand(0, $effect_spec->added) + $effect_spec->base) * $immunity * $impact_percentage);
            $target->inflict_damage($fighter_target, $target_target, $dmg, false, $action_list);
            break;
        case EFFECT::REVIVE: //Revive
            if ($target->get_current('HP') == 0) {
                $target->restore_HP($fighter_target, $target_target, $target->get_base('HP'), $action_list);
                break;
            }
            //Here's the restore rub:
            //$base is the chance out of 100 to be revived.
            //$added is the % life added to the target when successful.

            //Check to see if we revive the target.
            $chance = mt_rand(1, 100);
            if ($chance < $effect_spec->base) {
                //Find amount healed
                $amt = round($target->get_base('HP') * 100.0 / $effect_spec->added);
                $target->restore_HP($fighter_target, $target_target, $amt, $action_list);
            }
            break;
        case EFFECT::SLAY: //Slay
            break;
        case EFFECT::INCREASE_STATS: //Increase Stats
            break;
        case EFFECT::DECREASE_STATS: //Decrease Stats
            break;
        case EFFECT::STEAL_STATS: //Steal Stats
            break;
        case EFFECT::CAUSE_GOOD_STATUS: //Cause Good Status
            break;
        case EFFECT::REMOVE_GOOD_STATUS: //Remove Good Status
            break;
        case EFFECT::CAUSE_BAD_STATUS: //Cause Bad Status
            break;
        case EFFECT::REMOVE_BAD_STATUS: //Remove Bad Status
            break;
        case EFFECT::RESTORE_MP: //Restore MP
            if ($target->get_current('HP') == 0)
                return false;
            //Find amount healed
            $amt = mt_rand(0, $effect_spec->added) + $effect_spec->base;
            $target->restore_MP($fighter_target, $target_target, $amt, $action_list);
            break;
    }
    return true;
}