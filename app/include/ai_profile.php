<?php
require_once INCLUDE_GAME . 'constants.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'effects.php';

/**
 * How well a fighter plays when the computer plays it: its AI action,
 * goal, target and experience (constants.php), from its level and what
 * its abilities are good for. The one place that decides this: heroes
 * get it at the start of every fight (FightSetup::apply_hero_ai), and
 * the monster editor offers it as a recommendation.
 *
 * By level, each fight give or take up to LEVEL_JITTER levels, so a
 * party doesn't flip all at once:
 *
 *   under 5    Stupid     anything it has, targets at random
 *   5 - 9      Normal     a stage before its specialty
 *   10 - 19    specialty  Healer, Caster, Mage, Pummeler or Fighter,
 *                          read from its abilities (never its job)
 *   20 and up  Sharp      with sharper targeting and experience
 *
 * A player's heroes stop at Sharp action, Wise targeting and General
 * experience; only monsters go on to Smart and Omnipotent.
 */
final class AiProfile {
    const LEVEL_JITTER = 3;

    const STUPID = 0, NORMAL = 1, HEALER = 2, PUMMELER = 4, FIGHTER = 5, CASTER = 7, MAGE = 8, SHARP = 9, SMART = 10, OMNIPOTENT = 11;
    const GOAL_RANDOM = 0, GOAL_DESTRUCTOR = 1, GOAL_SCHEMER = 2, GOAL_PROTECTOR = 4;
    const TARGET_STUPID = 0, TARGET_NORMAL = 2, TARGET_SMART = 4, TARGET_WISE = 6, TARGET_OMNIPOTENT = 7;
    // Experience: the lower, the less its judgement is skewed.
    const EXP_RECRUIT = 50, EXP_PRIVATE = 40, EXP_SERGEANT = 30, EXP_LIEUTENANT = 20, EXP_COLONEL = 10, EXP_GENERAL = 5, EXP_OMNIPOTENT = 0;

    /**
     * ['ai_action', 'ai_goal', 'ai_target', 'ai_experience'] for a
     * fighter of $level with $abilityids. $player: one of a player's
     * heroes (never omnipotent). $jitter: levels to add, null for a
     * random -LEVEL_JITTER..+LEVEL_JITTER (0 for the editor's
     * recommendation).
     */
    public static function for_level(int $level, array $abilityids, bool $player, ?int $jitter = null): array {
        $jitter ??= random_int(-self::LEVEL_JITTER, self::LEVEL_JITTER);
        $level = max(1, $level + $jitter);
        $specialty = self::specialty($abilityids);
        if ($level < 5) {
            $profile = [self::STUPID, self::GOAL_RANDOM, self::TARGET_STUPID, self::EXP_RECRUIT];
        } elseif ($level < 10) {
            $profile = [self::NORMAL, self::GOAL_RANDOM, self::TARGET_NORMAL, self::EXP_PRIVATE];
        } elseif ($level < 20) {
            $profile = [$specialty, self::goal_for($specialty), self::TARGET_SMART, self::EXP_SERGEANT];
        } elseif ($level < 30) {
            $profile = [self::SHARP, self::goal_for($specialty), self::TARGET_WISE, self::EXP_LIEUTENANT];
        } elseif ($level < 40) {
            $profile = [self::SHARP, self::goal_for($specialty), self::TARGET_WISE, self::EXP_COLONEL];
        } elseif ($level < 50) {
            $profile = [self::SMART, self::goal_for($specialty), self::TARGET_WISE, self::EXP_GENERAL];
        } else {
            $profile = [self::OMNIPOTENT, self::goal_for($specialty), self::TARGET_OMNIPOTENT, self::EXP_OMNIPOTENT];
        }
        if ($player) {
            $profile[0] = min($profile[0], self::SHARP);
            $profile[2] = min($profile[2], self::TARGET_WISE);
            $profile[3] = max($profile[3], self::EXP_GENERAL);
        }
        return array_combine(['ai_action', 'ai_goal', 'ai_target', 'ai_experience'], $profile);
    }

    // Sets $fighter's AI for this fight.
    public static function apply(CHARACTER $fighter, bool $player, ?int $jitter = null): void {
        foreach (self::for_level((int) $fighter->level, $fighter->abilities, $player, $jitter) as $field => $value) {
            $fighter->$field = $value;
        }
    }

    /**
     * What a fighter's combat abilities make it best at, as an AI
     * action: Healer when a good share heal or revive, Mage or Caster
     * when mostly spells (Mage if they mostly hurt), Pummeler when mostly
     * hurting skills, else Fighter (weapons first).
     */
    public static function specialty(array $abilityids): int {
        $abilities = [];
        foreach ($abilityids as $abilityid) {
            try {
                $abilities[] = ABILITY::get((int) $abilityid);
            } catch (\Throwable $e) {
                // gone from the game: nothing to go on
            }
        }
        return self::specialty_of($abilities);
    }

    // The same, from the ABILITY objects themselves.
    public static function specialty_of(array $abilities): int {
        $heal = $hurt_spell = $other_spell = $hurt_skill = $other_skill = 0;
        foreach ($abilities as $ability) {
            if (!$ability->usable_in_combat()) {
                continue;
            }
            $effect = $ability->effect_spec->effect;
            $spell = $ability->type == ABILITY::TYPE_SPELL;
            if ($effect == Effect::HEAL || $effect == Effect::REVIVE) {
                $heal++;
            } elseif ($effect == Effect::HURT || $effect == Effect::SLAY) {
                $spell ? $hurt_spell++ : $hurt_skill++;
            } else {
                $spell ? $other_spell++ : $other_skill++;
            }
        }
        $total = $heal + $hurt_spell + $other_spell + $hurt_skill + $other_skill;
        if ($total === 0) {
            return self::FIGHTER;
        }
        if ($heal * 5 >= $total * 2) { // 40% or more
            return self::HEALER;
        }
        $spells = $hurt_spell + $other_spell;
        $skills = $hurt_skill + $other_skill;
        if ($spells > $skills) {
            return $hurt_spell >= $other_spell ? self::MAGE : self::CASTER;
        }
        return $hurt_skill > 0 ? self::PUMMELER : self::FIGHTER;
    }

    public static function goal_for(int $action): int {
        return match ($action) {
            self::HEALER => self::GOAL_PROTECTOR,
            self::CASTER => self::GOAL_SCHEMER,
            default => self::GOAL_DESTRUCTOR,
        };
    }

    /**
     * The hero level whose typical PXP a monster's $pxp matches: the
     * lowest level at which the median hero (one of each job) has at
     * least that much. For the monster editor, which has PXP, not levels.
     */
    public static function level_for_pxp(int $pxp): int {
        static $medians = null;
        if ($medians === null) {
            $by_level = [];
            foreach (array_keys(JOB::load_all_from_cache()) as $jobid) {
                $hero = CHARACTER::create_hero('reference', (int) $jobid, 1, null);
                for ($level = 1; $level <= 100; $level++) {
                    $by_level[$level][] = $hero->calculate_pxp();
                    $hero->exp = $hero->need;
                    $hero->level();
                }
            }
            $medians = [];
            foreach ($by_level as $level => $values) {
                sort($values);
                $medians[$level] = $values[intdiv(count($values), 2)];
            }
        }
        foreach ($medians as $level => $median) {
            if ($median >= $pxp) {
                return $level;
            }
        }
        return 100;
    }
}
