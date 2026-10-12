<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'ai_profile.php';
require_once INCLUDE_GAME . 'ai.php';
require_once INCLUDE_GAME . 'animation_effect.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'monster_side.php';

/**
 * How well the computer plays a fighter (AiProfile): by level, give or
 * take a few, with the specialty read from its abilities, and never
 * omnipotent for a player's heroes.
 */
class AiProfileTest extends TestCase {

    private function ability(int $type, Effect $effect): ABILITY {
        return new ABILITY('test', $type, new EffectSpec($effect, EffectSpec::TARGET_INDIVIDUAL, 10, 0, 0),
            0, '', 'bignone.png', 'none', new AnimationEffect());
    }

    private function spell(Effect $effect): ABILITY {
        return $this->ability(ABILITY::TYPE_SPELL, $effect);
    }

    private function skill(Effect $effect): ABILITY {
        return $this->ability(ABILITY::TYPE_SKILL, $effect);
    }

    public function test_the_specialty_comes_from_what_the_abilities_do() {
        $this->assertSame(AiProfile::FIGHTER, AiProfile::specialty_of([]), 'nothing but weapons');
        $this->assertSame(AiProfile::HEALER, AiProfile::specialty_of([$this->spell(Effect::HEAL), $this->spell(Effect::HURT)]));
        $this->assertSame(AiProfile::HEALER, AiProfile::specialty_of([$this->spell(Effect::REVIVE), $this->skill(Effect::HEAL), $this->skill(Effect::HURT), $this->skill(Effect::HURT), $this->skill(Effect::HURT)]),
            'two in five is enough');
        $this->assertSame(AiProfile::PUMMELER, AiProfile::specialty_of([$this->spell(Effect::REVIVE), $this->skill(Effect::HURT), $this->skill(Effect::HURT)]),
            'one in three is not');
        $this->assertSame(AiProfile::MAGE, AiProfile::specialty_of([$this->spell(Effect::HURT), $this->spell(Effect::HURT), $this->skill(Effect::HURT)]));
        $this->assertSame(AiProfile::CASTER, AiProfile::specialty_of([$this->spell(Effect::INCREASE_STATS), $this->spell(Effect::DECREASE_STATS), $this->spell(Effect::HURT)]));
        $this->assertSame(AiProfile::PUMMELER, AiProfile::specialty_of([$this->skill(Effect::HURT), $this->skill(Effect::INCREASE_STATS)]));
        $this->assertSame(AiProfile::FIGHTER, AiProfile::specialty_of([$this->skill(Effect::INCREASE_STATS)]));
    }

    public function test_it_climbs_with_level() {
        $at = fn(int $level) => AiProfile::for_level($level, [], false, 0);
        $this->assertSame(['ai_action' => 0, 'ai_goal' => 0, 'ai_target' => 0, 'ai_experience' => 50], $at(1));
        $this->assertSame(AiProfile::STUPID, $at(4)['ai_action']);
        $this->assertSame(AiProfile::NORMAL, $at(5)['ai_action'], 'a stage before the specialty');
        $this->assertSame(AiProfile::FIGHTER, $at(10)['ai_action'], 'the specialty (no abilities: weapons)');
        $this->assertSame(AiProfile::GOAL_DESTRUCTOR, $at(10)['ai_goal']);
        $this->assertSame(AiProfile::SHARP, $at(20)['ai_action']);
        $this->assertSame(AiProfile::SMART, $at(40)['ai_action']);
        $this->assertSame(['ai_action' => 11, 'ai_goal' => 1, 'ai_target' => 7, 'ai_experience' => 0], $at(50), 'a monster can be omnipotent');
    }

    public function test_a_players_heroes_are_never_omnipotent() {
        foreach ([30, 45, 60, 100] as $level) {
            $profile = AiProfile::for_level($level, [], true, 0);
            $this->assertLessThanOrEqual(AiProfile::SHARP, $profile['ai_action'], "level $level");
            $this->assertLessThanOrEqual(AiProfile::TARGET_WISE, $profile['ai_target']);
            $this->assertGreaterThanOrEqual(AiProfile::EXP_GENERAL, $profile['ai_experience']);
        }
    }

    public function test_the_level_is_give_or_take_a_few_each_time() {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[AiProfile::for_level(5, [], true)['ai_action']] = true;
        }
        ksort($seen);
        $this->assertSame([AiProfile::STUPID, AiProfile::NORMAL], array_keys($seen), 'a level-5 hero plays like a 2 to an 8');
        $this->assertSame(AiProfile::STUPID, AiProfile::for_level(1, [], true, -3)['ai_action'], 'never below level 1');
    }

    public function test_heroes_get_it_at_the_start_of_a_fight_and_monsters_keep_their_own() {
        $fight = new FIGHT('bg', 'music');
        $heroes = new PARTY();
        $group = new GROUP();
        $hero = CHARACTER::create_hero('Jordie', 1, 25, null);
        $group->add_hero($hero);
        $heroes->add($group);
        $fight->add($heroes);
        $monsters = new PARTY();
        $monsters->monster = true;
        $mgroup = new GROUP();
        $monster = CHARACTER::create_hero('Woodman', 1, 25, null);
        $monster->ai_action = 3;
        $mgroup->add_hero($monster);
        $monsters->add($mgroup);
        $fight->add($monsters);
        FightSetup::apply_hero_ai($fight);
        $this->assertSame(AiProfile::SHARP, $hero->ai_action, 'level 25, give or take 3: Sharp');
        $this->assertSame(3, $monster->ai_action, 'set in the monster editor');
    }
}
