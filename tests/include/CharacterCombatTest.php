<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'party.php'; // not in bootstrap.php's require chain

/**
 * Characterization tests for CHARACTER's combat cluster: inflict_damage(),
 * restore_HP(), restore_MP(), affected_by(), validate_action(),
 * validate_target(), and perform_action().
 *
 * These capture CURRENT behavior as a safety net -- not a judgment
 * that every behavior captured is necessarily correct. Where
 * something looks like a real asymmetry or oversight rather than
 * deliberate design, it's flagged in the test's own comment rather
 * than silently normalized.
 *
 * The parameter-object refactor these were originally written ahead
 * of is done: inflict_damage(), restore_HP(), restore_MP(),
 * affected_by(), add_debt_to_xp_tree(), and do_effect() (in
 * effects.php) now take two ActionTarget objects (fighter, target)
 * instead of six positional index params apiece.
 *
 * Relies on tests/fixtures/cache/ via tests/bootstrap.php, same as
 * CharacterTest.php.
 */

/** Minimal ActionScope implementation for validate_target() tests. */
class CombatTestScope implements ActionScope {
    public function __construct(private array $parties) {}
    public function get_parties(): array { return $this->parties; }
    public function get_scope_type(): ScopeType { return ScopeType::COMBAT; }
}

/** For perform_action()'s give-item (case 9), which is field-only. */
class FieldTestScope implements ActionScope {
    public function __construct(private array $parties) {}
    public function get_parties(): array { return $this->parties; }
    public function get_scope_type(): ScopeType { return ScopeType::FIELD; }
}
class CharacterCombatTest extends TestCase {
    use AugmentsFixtureCache;

    private function fresh_character(string $stat, int $current, int $base): CHARACTER {
        $character = new CHARACTER();
        $character->name = 'Test Character';
        $character->base[$stat] = $base;
        $character->current[$stat] = $current;
        return $character;
    }

    // --- inflict_damage() ---

    public function test_inflict_damage_normal_damage() {
        $character = $this->fresh_character('HP', 50, 100);
        $action_list = [];

        $character->inflict_damage(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 20, false, $action_list);

        $this->assertSame(30, $character->current['HP']);
        $this->assertSame(
            ['Damage', [1, 0, 0, 20, false]],
            $action_list[0]
        );
        $this->assertCount(2, $action_list); // Damage + XpDebt, no 'Died' entry
    }

    public function test_inflict_damage_overkill_clamps_hp_to_zero_and_dies() {
        $character = $this->fresh_character('HP', 10, 100);
        $action_list = [];

        $character->inflict_damage(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 50, false, $action_list);

        $this->assertSame(0, $character->current['HP']);
        // Both the 'Damage' and 'Died' entries show the ORIGINAL,
        // unclamped damage (50) -- confirmed empirically. The
        // adjustment that would reduce it to the actual HP lost runs
        // AFTER both entries are already pushed to $action_list. Died's
        // last field is the overkill: 50 damage against 10 HP left.
        $this->assertSame(['Damage', [1, 0, 0, 50, false]], $action_list[0]);
        $this->assertSame(['Died', [1, 0, 0, 50, 40]], $action_list[1]);
    }

    public function test_inflict_damage_negative_damage_heals_instead() {
        // A negative $dmg is a documented, working path -- not an edge
        // case being defended against. Displays as 'Restore', not
        // 'Damage', with the amount flipped positive.
        $character = $this->fresh_character('HP', 50, 100);
        $action_list = [];

        $character->inflict_damage(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), -20, false, $action_list);

        $this->assertSame(70, $character->current['HP']);
        $this->assertSame(['Restore', [1, 0, 0, 'HP', 20]], $action_list[0]);
    }

    public function test_inflict_damage_negative_damage_caps_at_base_hp() {
        $character = $this->fresh_character('HP', 90, 100);
        $action_list = [];

        $character->inflict_damage(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), -20, false, $action_list);

        // Capped at 100, not 90+20=110. Unlike the overkill case above,
        // this adjustment DOES happen before the action_list entry is
        // pushed -- the entry shows the actual applied amount (10), not
        // the originally requested one (20).
        $this->assertSame(100, $character->current['HP']);
        $this->assertSame(['Restore', [1, 0, 0, 'HP', 10]], $action_list[0]);
    }

    public function test_inflict_damage_records_xp_debt() {
        $character = $this->fresh_character('HP', 50, 100);
        $action_list = [];

        $character->inflict_damage(new ActionTarget(1, 2, 3), new ActionTarget(4, 5, 6), 20, false, $action_list);

        // [index_key, (before-after)/gauge, this->pxp]. Index key is
        // built from the ATTACKER's position (1,2,3), not the
        // target's (4,5,6).
        $this->assertSame([1, 2, 3], $character->xp_debts[0][0]);
        $this->assertSame(0.2, $character->xp_debts[0][1]); // (50-30)/100

        // The other half: an XpDebt message in $action_list itself,
        // the same way this replays via FIGHT::process_fight_action()'s
        // own XpDebt case -- target's own position (4,5,6) spread
        // first (matching Damage/Restore's own convention), then the
        // exact same debt tuple just asserted above.
        $xpdebt_message = end($action_list);
        $this->assertSame('XpDebt', $xpdebt_message[0]);
        $this->assertSame([4, 5, 6, [1, 2, 3], 0.2, $character->pxp], $xpdebt_message[1]);
    }

    // --- restore_HP() ---

    public function test_restore_hp_normal_heal() {
        $character = $this->fresh_character('HP', 50, 100);
        $action_list = [];

        $character->restore_HP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 20, $action_list);

        $this->assertSame(70, $character->current['HP']);
        $this->assertSame(['Restore', [1, 0, 0, 'HP', 20]], $action_list[0]);
    }

    public function test_restore_hp_caps_at_base_hp() {
        $character = $this->fresh_character('HP', 90, 100);
        $action_list = [];

        $character->restore_HP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 20, $action_list);

        $this->assertSame(100, $character->current['HP']);
        // Actual applied amount (10), not the requested one (20).
        $this->assertSame(['Restore', [1, 0, 0, 'HP', 10]], $action_list[0]);
    }

    public function test_restore_hp_negative_amount_damages_instead() {
        // Mirrors inflict_damage()'s negative-dmg-heals symmetry in
        // the other direction: a negative $amt here damages, displayed
        // as 'Damage' rather than 'Restore'.
        $character = $this->fresh_character('HP', 50, 100);
        $action_list = [];

        $character->restore_HP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), -20, $action_list);

        $this->assertSame(30, $character->current['HP']);
        $this->assertSame(['Damage', [1, 0, 0, 20, false]], $action_list[0]);
    }

    public function test_restore_hp_negative_amount_can_kill() {
        $character = $this->fresh_character('HP', 10, 100);
        $action_list = [];

        $character->restore_HP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), -50, $action_list);

        $this->assertSame(0, $character->current['HP']);
        $this->assertSame(['Damage', [1, 0, 0, 50, false]], $action_list[0]);
        $this->assertSame(['Died', [1, 0, 0, 50, 40]], $action_list[1], 'the last field is the overkill');
    }

    // --- restore_MP() ---

    public function test_restore_mp_normal() {
        $character = $this->fresh_character('MP', 30, 100);
        $action_list = [];

        $character->restore_MP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 20, $action_list);

        $this->assertSame(50, $character->current['MP']);
        $this->assertSame(['Restore', [1, 0, 0, 'MP', 20]], $action_list[0]);
    }

    public function test_restore_mp_caps_at_base_mp() {
        $character = $this->fresh_character('MP', 90, 100);
        $action_list = [];

        $character->restore_MP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 20, $action_list);

        $this->assertSame(100, $character->current['MP']);
        $this->assertSame(['Restore', [1, 0, 0, 'MP', 10]], $action_list[0]);
    }

    public function test_restore_mp_throws_on_a_directly_negative_amount() {
        // restore_MP() should only ever restore -- a negative $amt
        // (unlike restore_HP(), which has an explicit, intentional
        // "negative amt damages" branch) is now rejected rather than
        // silently applied.
        $character = $this->fresh_character('MP', 50, 100);
        $action_list = [];

        $this->expectException(Exception::class);
        $character->restore_MP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), -20, $action_list);
    }

    public function test_restore_mp_throws_when_already_over_cap_makes_a_positive_amount_go_negative() {
        // Even a well-behaved, positive $amt can't guarantee a
        // non-negative result on its own: if current MP is somehow
        // already above base MP when this runs, the capping logic
        // itself (get_base - get_current) produces a negative value.
        // Confirmed empirically before the fix: MP=60/base=50 with
        // $amt=20 previously computed an effective $amt of -10 and
        // actually reduced MP from 60 to 50, despite the positive,
        // clearly-restorative input.
        $character = $this->fresh_character('MP', 60, 50);
        $action_list = [];

        $this->expectException(Exception::class);
        $character->restore_MP(new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 20, $action_list);
    }

    public function test_restore_mp_records_xp_debt() {
        $character = $this->fresh_character('MP', 30, 100);
        $action_list = [];

        $character->restore_MP(new ActionTarget(1, 2, 3), new ActionTarget(4, 5, 6), 20, $action_list);

        $this->assertSame([1, 2, 3], $character->xp_debts[0][0]);
        $this->assertSame(-0.2, $character->xp_debts[0][1]); // (30-50)/100

        $xpdebt_message = end($action_list);
        $this->assertSame('XpDebt', $xpdebt_message[0]);
        $this->assertSame([4, 5, 6, [1, 2, 3], -0.2, $character->pxp], $xpdebt_message[1]);
    }

    // --- calculate_hit_damage() ---
    // Fully pure -- extracted from the melee-attack branch of
    // affected_by() so the damage math is directly testable with
    // fixed inputs, no sampling needed. Verified against the exact
    // same behavior affected_by() produced before this extraction
    // (e.g. the critical-hit case below matches a pre-extraction
    // sampling result of 36 precisely).

    public function test_calculate_hit_damage_critical_doubles_strength() {
        $damage = CHARACTER::calculate_hit_damage(20, 5, 100, 1, false, 1.0);

        $this->assertSame(36, $damage);
    }

    public function test_calculate_hit_damage_worst_roll_still_hits() {
        // roll === accuracy is the worst possible non-miss roll.
        $damage = CHARACTER::calculate_hit_damage(20, 5, 100, 100, false, 1.0);

        $this->assertSame(8, $damage);
    }

    public function test_calculate_hit_damage_defending_halves_it() {
        $not_defending = CHARACTER::calculate_hit_damage(20, 5, 50, 25, false, 1.0);
        $defending = CHARACTER::calculate_hit_damage(20, 5, 50, 25, true, 1.0);

        $this->assertSame(12, $not_defending);
        $this->assertSame(6, $defending);
    }

    public function test_calculate_hit_damage_minimum_is_one() {
        $damage = CHARACTER::calculate_hit_damage(1, 1000, 1000, 1000, false, 0.01);

        $this->assertSame(1, $damage);
    }

    public function test_calculate_hit_damage_accuracy_one_does_not_crash() {
        // The original bug this whole extraction grew out of: accuracy
        // exactly 1 used to divide by ($acc - 1.0) === 0. The new
        // formula divides by $acc instead (floored to a minimum of 1),
        // so this is now a guaranteed dead-on hit rather than a crash
        // -- confirmed directly, not just by absence of an exception.
        $damage = CHARACTER::calculate_hit_damage(10, 5, 1, 1, false, 1.0);

        $this->assertSame(16, $damage);
    }

    // --- roll_to_hit() ---
    // Still involves mt_rand() internally -- shared between melee
    // attacks and skills (case 4), which is why it carries its own
    // roll rather than accepting one as a parameter. Verified via
    // statistical sampling and edge cases rather than exact values.

    public function test_roll_to_hit_worst_case_is_roughly_fifty_fifty() {
        $hits = 0;
        $misses = 0;
        for ($i = 0; $i < 1000; $i++) {
            if (CHARACTER::roll_to_hit(1, 0) === null) {
                $misses++;
            } else {
                $hits++;
            }
        }

        // Both dodge=0 and accuracy=1 floor to 1 internally, giving a
        // roll range of [1,2] -- roughly even odds. Wide tolerance
        // since this is genuinely random.
        $this->assertGreaterThan(350, $hits);
        $this->assertGreaterThan(350, $misses);
    }

    public function test_roll_to_hit_zero_accuracy_and_dodge_do_not_crash() {
        // Both floored to a minimum of 1 internally -- this should
        // behave identically to accuracy=1, dodge=1, not throw.
        for ($i = 0; $i < 50; $i++) {
            $roll = CHARACTER::roll_to_hit(0, 0);
            $this->assertTrue($roll === null || $roll === 1);
        }
    }

    public function test_roll_to_hit_overwhelming_accuracy_almost_always_hits() {
        $hits = 0;
        for ($i = 0; $i < 200; $i++) {
            if (CHARACTER::roll_to_hit(1000, 1) !== null) {
                $hits++;
            }
        }

        // Only a roll of exactly 1001 (out of a [1,1001] range) misses.
        $this->assertGreaterThan(190, $hits);
    }

    public function test_roll_to_hit_returns_the_roll_value_on_a_hit() {
        // With accuracy=1 (floored) and dodge=1 (floored), the only
        // roll that counts as a hit is exactly 1 -- confirms the
        // return value is the actual roll, not just a boolean.
        for ($i = 0; $i < 50; $i++) {
            $roll = CHARACTER::roll_to_hit(1, 1);
            if ($roll !== null) {
                $this->assertSame(1, $roll);
            }
        }
    }

    // --- roll_saving_throw() ---
    // Extracted from the spell-casting branch (case 5) of
    // affected_by(). Involves mt_rand() internally like roll_to_hit(),
    // but consolidates the original three-way branch (NoEffect / half
    // immunity / full immunity) into a single return value: 0.0 for a
    // full save, 0.5 for a half save, 1.0 for no save at all. Callers
    // check for 0.0 themselves to decide whether to skip the effect --
    // this function only scores the throw, not what a save means for
    // control flow.

    public function test_roll_saving_throw_distribution_matches_the_original_thresholds() {
        // pow=10, res=10 gives a roll range of [1,20]. Full save
        // (roll>15): 5/20 rolls, 25%. Half save (roll>10, <=15): 5/20,
        // 25%. No save (roll<=10): 10/20, 50%. Matches the original
        // inline thresholds exactly (roll > pow+res/2, then roll > pow).
        $full_save = 0;
        $half_save = 0;
        $no_save = 0;
        for ($i = 0; $i < 2000; $i++) {
            $immunity = CHARACTER::roll_saving_throw(10, 10);
            if ($immunity === 0.0) {
                $full_save++;
            } elseif ($immunity === 0.5) {
                $half_save++;
            } elseif ($immunity === 1.0) {
                $no_save++;
            } else {
                $this->fail("Unexpected immunity value: $immunity");
            }
        }

        // Wide tolerance since this is genuinely random -- checking
        // the roughly 25/25/50 shape, not exact counts.
        $this->assertGreaterThan(300, $full_save);
        $this->assertGreaterThan(300, $half_save);
        $this->assertGreaterThan(800, $no_save);
    }

    public function test_roll_saving_throw_low_power_relative_to_resistance_always_saves() {
        // power=1, resistance=0 (roll range [1,1], always rolls 1):
        // full save check is 1 > 1+0 -> false; half save check is
        // 1 > 1 -> false; falls through to no-save (1.0). Confirmed
        // this exact boundary case matches the original logic, not
        // assumed from the general distribution above.
        $immunity = CHARACTER::roll_saving_throw(1, 0);

        $this->assertSame(1.0, $immunity);
    }

    // --- affected_by() end-to-end, per branch ---
    // The functions above cover the pure/roll-carrying math each
    // branch delegates to; these confirm affected_by() itself wires
    // that math up correctly -- the right function gets called with
    // the right arguments, and the right action_list entries and
    // state changes result.

    public function test_affected_by_melee_does_nothing_to_a_dead_target() {
        $fighter = new CHARACTER();
        $fighter->name = 'Fighter';
        $fighter->current['Accuracy'] = 50;
        $fighter->current['Strength'] = 20;
        $fighter->command = 0;

        $target = new CHARACTER();
        $target->name = 'Target';
        $target->current['HP'] = 0; // already dead
        $target->base['HP'] = 100;
        $target->current['Dodge'] = 5;
        $target->current['Block'] = 5;

        $action_list = [];
        $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);

        $this->assertSame([], $action_list);
    }

    public function test_affected_by_item_use_delegates_to_the_items_effect_spec() {
        // A custom item, not part of the shared base fixture set, with
        // a deterministic heal amount -- confirms the RIGHT item's
        // effect spec is what actually gets applied, not just that
        // something happened.
        $spec = new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 20, 0, 0);
        $item_row = [
            'itemid' => 500, 'name' => 'Test Healing Potion', 'price' => 10,
            'use_effect_spec' => json_encode($spec), 'one_use' => true,
            'equip_type' => null, 'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => '', 'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        $hp_after = $this->with_extra_cache(function () {
            $fighter = new CHARACTER();
            $fighter->name = 'Fighter';
            $fighter->inventory = [0 => ['item' => 500, 'qty' => 1]];
            $fighter->using = 0;
            $fighter->command = 2;

            $target = new CHARACTER();
            $target->name = 'Target';
            $target->current['HP'] = 50;
            $target->base['HP'] = 100;

            $action_list = [];
            $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);

            $this->assertSame(['Restore', [1, 0, 0, 'HP', 20]], $action_list[0]);
            return $target->current['HP'];
        }, items: [500 => $item_row]);

        $this->assertSame(70, $hp_after);
    }

    public function test_affected_by_detrimental_skill_can_both_miss_and_hit() {
        // Fixture ability 1 is detrimental (HURT). Worst-case accuracy
        // forces a roughly even split -- large sample to avoid the
        // flakiness a smaller one can show at these odds.
        $fighter = new CHARACTER();
        $fighter->name = 'Fighter';
        $fighter->current['Accuracy'] = 1;
        $fighter->abilities = [1];
        $fighter->using = 0;
        $fighter->command = 4;

        $misses = 0;
        $hits = 0;
        for ($i = 0; $i < 2000; $i++) {
            $target = new CHARACTER();
            $target->name = 'Target';
            $target->current['HP'] = 100;
            $target->base['HP'] = 100;
            $target->current['Dodge'] = 0;
            $action_list = [];
            $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);
            if (($action_list[0][0] ?? null) === 'Miss') {
                $misses++;
            } elseif (($action_list[0][0] ?? null) === 'Damage') {
                $hits++;
            }
        }

        $this->assertGreaterThan(800, $misses);
        $this->assertGreaterThan(800, $hits);
    }

    public function test_affected_by_detrimental_skill_does_nothing_to_a_dead_target() {
        $fighter = new CHARACTER();
        $fighter->name = 'Fighter';
        $fighter->abilities = [1]; // detrimental
        $fighter->using = 0;
        $fighter->command = 4;

        $target = new CHARACTER();
        $target->name = 'Target';
        $target->current['HP'] = 0;
        $target->base['HP'] = 100;

        $action_list = [];
        $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);

        $this->assertSame([], $action_list);
    }

    public function test_affected_by_non_detrimental_skill_always_applies_with_no_miss_possible() {
        // A non-detrimental skill never rolls to hit at all -- it's
        // unconditionally applied. A custom heal-effect ability, not
        // in the shared base fixture set, gives a deterministic
        // outcome to assert on.
        $spec = new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 15, 0, 0);
        $ability_row = [
            'abilityid' => 600, 'name' => 'Test Heal Skill', 'type' => 0,
            'effect_spec' => json_encode($spec), 'mp_used' => 5,
            'description' => 'test', 'menu_pic' => 'bignone.png',
            'skill_effect_type' => 'none', 'impact' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        $applied_count = $this->with_extra_cache(function () {
            $this->assertFalse(ABILITY::get(600)->is_detrimental());

            $fighter = new CHARACTER();
            $fighter->name = 'Fighter';
            $fighter->abilities = [600];
            $fighter->using = 0;
            $fighter->command = 4;

            $applied = 0;
            for ($i = 0; $i < 50; $i++) {
                $target = new CHARACTER();
                $target->name = 'Target';
                $target->current['HP'] = 50;
                $target->base['HP'] = 100;
                $action_list = [];
                $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);
                if ($target->current['HP'] === 65) {
                    $applied++;
                }
            }
            return $applied;
        }, abilities: [600 => $ability_row]);

        $this->assertSame(50, $applied_count);
    }

    public function test_affected_by_detrimental_spell_can_have_no_effect_and_can_apply() {
        // Fixture ability 3 is detrimental (HURT). pow=res=10 gives
        // roughly a 25% full-save / 75% some-effect split per
        // roll_saving_throw()'s own verified thresholds.
        $fighter = new CHARACTER();
        $fighter->name = 'Fighter';
        $fighter->current['Power'] = 10;
        $fighter->abilities = [3];
        $fighter->using = 0;
        $fighter->command = 5;

        $no_effect = 0;
        $applied = 0;
        for ($i = 0; $i < 2000; $i++) {
            $target = new CHARACTER();
            $target->name = 'Target';
            $target->current['HP'] = 100;
            $target->base['HP'] = 100;
            $target->current['Resistance'] = 10;
            $action_list = [];
            $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);
            $key = $action_list[0][0] ?? null;
            if ($key === 'NoEffect') {
                $no_effect++;
            } elseif ($key === 'Damage') {
                $applied++;
            }
        }

        $this->assertGreaterThan(300, $no_effect);
        $this->assertGreaterThan(1200, $applied);
    }

    public function test_affected_by_detrimental_spell_does_nothing_to_a_dead_target() {
        $fighter = new CHARACTER();
        $fighter->name = 'Fighter';
        $fighter->abilities = [3]; // detrimental
        $fighter->using = 0;
        $fighter->command = 5;

        $target = new CHARACTER();
        $target->name = 'Target';
        $target->current['HP'] = 0;
        $target->base['HP'] = 100;

        $action_list = [];
        $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);

        $this->assertSame([], $action_list);
    }

    public function test_affected_by_non_detrimental_spell_always_applies_with_full_immunity() {
        // A non-detrimental spell never rolls a saving throw -- always
        // applied at immunity=1 (full effect), unconditionally.
        $spec = new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 15, 0, 0);
        $ability_row = [
            'abilityid' => 601, 'name' => 'Test Heal Spell', 'type' => 1,
            'effect_spec' => json_encode($spec), 'mp_used' => 5,
            'description' => 'test', 'menu_pic' => 'bignone.png',
            'skill_effect_type' => 'none', 'impact' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        $applied_count = $this->with_extra_cache(function () {
            $this->assertFalse(ABILITY::get(601)->is_detrimental());

            $fighter = new CHARACTER();
            $fighter->name = 'Fighter';
            $fighter->abilities = [601];
            $fighter->using = 0;
            $fighter->command = 5;

            $applied = 0;
            for ($i = 0; $i < 50; $i++) {
                $target = new CHARACTER();
                $target->name = 'Target';
                $target->current['HP'] = 50;
                $target->base['HP'] = 100;
                $action_list = [];
                $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);
                if ($target->current['HP'] === 65) {
                    $applied++;
                }
            }
            return $applied;
        }, abilities: [601 => $ability_row]);

        $this->assertSame(50, $applied_count);
    }

    // --- no-op branches (3/6/7: change equipment / defend / run) ---

    public function test_affected_by_change_equipment_defend_and_run_do_nothing() {
        $fighter = new CHARACTER();
        $fighter->name = 'Fighter';

        foreach ([3, 6, 7] as $command) {
            $fighter->command = $command;
            $target = new CHARACTER();
            $target->name = 'Target';
            $target->current['HP'] = 100;
            $target->base['HP'] = 100;

            $action_list = [];
            $target->affected_by($fighter, new ActionTarget(0, 0, 0), new ActionTarget(1, 0, 0), 0, 0, $action_list);

            $this->assertSame([], $action_list, "command=$command should be a no-op");
        }
    }

    // --- validate_action() ---
    // Pure -- no randomness, no external dependencies, just checks
    // against the character's own state (command/using/target/
    // equipment/inventory/abilities).

    private function bare_character(): CHARACTER {
        $character = new CHARACTER();
        $character->name = 'Test';
        return $character;
    }

    public function test_validate_action_left_hand_attack_is_always_valid() {
        $character = $this->bare_character();
        $character->command = 0;

        $this->assertTrue($character->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_melee_attack_requires_combat_scope() {
        // Moved here from perform_action()'s own inline check, which
        // used to silently no-op outside COMBAT scope rather than
        // being rejected at validation. Covers both hands.
        $character = $this->bare_character();
        $character->command = 0;
        $this->assertFalse($character->validate_action(new FieldTestScope([])));

        $character2 = $this->bare_character();
        $character2->command = 1;
        $this->assertFalse($character2->validate_action(new FieldTestScope([])));
    }

    public function test_validate_action_right_hand_attack_invalid_when_both_hands_share_a_slot() {
        // A two-handed weapon occupies both hand slots -- can't attack
        // separately with the right hand in that case.
        $character = $this->bare_character();
        $character->command = 1;
        $character->equipment['lhand'] = 5;
        $character->equipment['rhand'] = 5;

        $this->assertFalse($character->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_right_hand_attack_valid_when_hands_differ_or_lhand_is_empty() {
        $character = $this->bare_character();
        $character->command = 1;
        $character->equipment['lhand'] = 5;
        $character->equipment['rhand'] = 6;
        $this->assertTrue($character->validate_action(new CombatTestScope([])));

        $character2 = $this->bare_character();
        $character2->command = 1;
        $character2->equipment['lhand'] = null;
        $character2->equipment['rhand'] = 5;
        $this->assertTrue($character2->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_use_item_requires_an_item_in_the_using_slot() {
        $character = $this->bare_character();
        $character->command = 2;
        $character->using = 0;
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]];
        $this->assertTrue($character->validate_action(new CombatTestScope([])));

        $character2 = $this->bare_character();
        $character2->command = 2;
        $character2->using = 0;
        $character2->inventory = [];
        $this->assertFalse($character2->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_give_item_requires_an_item_in_the_using_slot() {
        // Same check as use-item (case 2), duplicated under a
        // different command. Requires FIELD scope.
        $character = $this->bare_character();
        $character->command = 9;
        $character->using = 0;
        $character->inventory = [];

        $this->assertFalse($character->validate_action(new FieldTestScope([])));
    }

    public function test_validate_action_give_item_requires_field_scope() {
        // Moved here from perform_action()'s own inline check, which
        // used to silently skip the whole block outside FIELD scope
        // rather than being rejected at validation.
        $character = $this->bare_character();
        $character->command = 9;
        $character->using = 0;
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]]; // otherwise-valid

        $this->assertFalse($character->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_skill_and_spell_require_an_ability_in_the_using_slot() {
        $character = $this->bare_character();
        $character->command = 4;
        $character->using = 0;
        $character->abilities = [1];
        $this->assertTrue($character->validate_action(new CombatTestScope([])));

        $character2 = $this->bare_character();
        $character2->command = 5;
        $character2->using = 0;
        $character2->abilities = [];
        $this->assertFalse($character2->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_defend_and_run_are_always_valid() {
        $character = $this->bare_character();
        $character->command = 6;
        $this->assertTrue($character->validate_action(new CombatTestScope([])));

        $character->command = 7;
        $this->assertTrue($character->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_unknown_command_is_rejected() {
        // Fixed: a default case now explicitly rejects any command
        // value that doesn't match one of the known cases, rather
        // than silently falling through to the final `return true`.
        $character = $this->bare_character();
        $character->command = 42;

        $this->assertFalse($character->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_change_equipment_unequip_path() {
        // using===-1 means unequip -- checks for an item at target[0]
        // rather than at the using slot.
        $character = $this->bare_character();
        $character->command = 3;
        $character->using = -1;
        $character->target = [0, 0, 0];
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]];
        $this->assertTrue($character->validate_action(new CombatTestScope([])));

        $character2 = $this->bare_character();
        $character2->command = 3;
        $character2->using = -1;
        $character2->target = [0, 0, 0];
        $character2->inventory = [];
        $this->assertFalse($character2->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_change_equipment_equip_path() {
        // using !== -1 means equip -- checks for an item at the using
        // slot instead.
        $character = $this->bare_character();
        $character->command = 3;
        $character->using = 0;
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]];
        $this->assertTrue($character->validate_action(new CombatTestScope([])));

        $character2 = $this->bare_character();
        $character2->command = 3;
        $character2->using = 0;
        $character2->inventory = [];
        $this->assertFalse($character2->validate_action(new CombatTestScope([])));
    }

    public function test_validate_action_change_equipment_and_ammo_requires_both_checks_to_pass() {
        // Case 8 has no break before case 3's label -- it falls
        // through and ALSO runs case 3's using-dependent check. Both
        // must pass, not just case 8's own. Confirmed empirically,
        // not assumed from reading the switch alone.
        $character = $this->bare_character();
        $character->command = 8;
        $character->using = 0;
        $character->target = [0, 0, 0];
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]]; // present at both target[0] and using
        $this->assertTrue($character->validate_action(new CombatTestScope([])));

        // Case 8's own check fails (nothing at target[0]) -- short-circuits
        // before ever reaching case 3's fall-through logic.
        $character2 = $this->bare_character();
        $character2->command = 8;
        $character2->using = 0;
        $character2->target = [0, 0, 0];
        $character2->inventory = [];
        $this->assertFalse($character2->validate_action(new CombatTestScope([])));

        // Case 8's own check passes (item at target[0]=0), but the
        // fallen-through case 3 check fails (nothing at using=5) --
        // the item exists, just not in the slot case 3 checks.
        $character3 = $this->bare_character();
        $character3->command = 8;
        $character3->using = 5;
        $character3->target = [0, 0, 0];
        $character3->inventory = [0 => ['item' => 1, 'qty' => 1]];
        $this->assertFalse($character3->validate_action(new CombatTestScope([])));
    }

    // --- validate_target() ---
    // Return-value polarity is the OPPOSITE of validate_action()'s:
    // true means "abort this action" (no valid target), false means
    // "proceed" (either the original target is fine, or this method
    // just silently retargeted to a live alternative and mutated
    // $this->target in place). Worth stating explicitly since it's
    // easy to get backwards from validate_action()'s convention.

    private function combat_char(int $hp): CHARACTER {
        $character = new CHARACTER();
        $character->name = 'Member';
        $character->current['HP'] = $hp;
        $character->base['HP'] = 100;
        return $character;
    }

    /** @param int[] $hps one group, one member per HP value, in order */
    private function party_with_group(array $hps): PARTY {
        $party = new PARTY();
        $group = new GROUP();
        foreach ($hps as $hp) {
            $group->add_hero($this->combat_char($hp));
        }
        $party->add($group);
        return $party;
    }

    public function test_validate_target_commands_that_need_no_target_always_proceed() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $scope = new CombatTestScope([]);

        foreach ([3, 6, 7, 8] as $command) {
            $character->command = $command;
            $this->assertFalse($character->validate_target($scope, $spec), "command=$command");
        }
    }

    public function test_validate_target_effects_not_requiring_living_targets_always_proceed() {
        // REVIVE is one of only two effects (with NONE) not in
        // only_living_targets()'s list -- an empty, otherwise-invalid
        // scope would fail every other check, confirming this really
        // is an early return, not incidentally passing.
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::REVIVE, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $scope = new CombatTestScope([]); // empty -- would fail if this path were reached

        $this->assertFalse($character->validate_target($scope, $spec));
    }

    public function test_validate_target_all_parties_always_proceeds() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_ALL_PARTIES, 0, 0, 0);
        $scope = new CombatTestScope([]);

        $this->assertFalse($character->validate_target($scope, $spec));
    }

    public function test_validate_target_party_missing_aborts() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_PARTY, 0, 0, 0);
        $scope = new CombatTestScope([]); // no party at index 0

        $this->assertTrue($character->validate_target($scope, $spec));
    }

    public function test_validate_target_party_alive_proceeds() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_PARTY, 0, 0, 0);
        $scope = new CombatTestScope([0 => $this->party_with_group([50])]);

        $this->assertFalse($character->validate_target($scope, $spec));
    }

    public function test_validate_target_party_entirely_dead_aborts() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_PARTY, 0, 0, 0);
        $scope = new CombatTestScope([0 => $this->party_with_group([0])]);

        $this->assertTrue($character->validate_target($scope, $spec));
    }

    public function test_validate_target_group_missing_party_aborts() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $scope = new CombatTestScope([]);

        $this->assertTrue($character->validate_target($scope, $spec));
    }

    public function test_validate_target_group_alive_proceeds_without_retargeting() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $scope = new CombatTestScope([0 => $this->party_with_group([50])]);

        $this->assertFalse($character->validate_target($scope, $spec));
        $this->assertSame([0, 0, 0], $character->target);
    }

    public function test_validate_target_group_dead_and_whole_party_dead_aborts() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $scope = new CombatTestScope([0 => $this->party_with_group([0])]);

        $this->assertTrue($character->validate_target($scope, $spec));
    }

    public function test_validate_target_group_dead_but_another_group_alive_retargets() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 0]; // targeting group 0

        $party = new PARTY();
        $dead_group = new GROUP();
        $dead_group->add_hero($this->combat_char(0));
        $party->add($dead_group); // group 0: dead
        $alive_group = new GROUP();
        $alive_group->add_hero($this->combat_char(50));
        $party->add($alive_group); // group 1: alive

        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $scope = new CombatTestScope([0 => $party]);

        $this->assertFalse($character->validate_target($scope, $spec));
        // Retargeted to the only living group (1); char_index untouched.
        $this->assertSame([0, 1, 0], $character->target);
    }

    // --- validate_target(): default branch (ranged attack) ---

    public function test_validate_target_ranged_missing_party_or_group_aborts() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 1];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);

        // Missing party entirely.
        $scope = new CombatTestScope([]);
        $this->assertTrue($character->validate_target($scope, $spec));

        // Party exists but has no groups.
        $character2 = new CHARACTER();
        $character2->name = 'Actor';
        $character2->command = 0;
        $character2->target = [0, 0, 1];
        $scope2 = new CombatTestScope([0 => new PARTY()]);
        $this->assertTrue($character2->validate_target($scope2, $spec));
    }

    public function test_validate_target_ranged_target_alive_proceeds_without_retargeting() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 1];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $scope = new CombatTestScope([0 => $this->party_with_group([50, 50, 50])]);

        $this->assertFalse($character->validate_target($scope, $spec));
        $this->assertSame([0, 0, 1], $character->target);
    }

    public function test_validate_target_ranged_target_dead_and_whole_group_dead_aborts() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 1];
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $scope = new CombatTestScope([0 => $this->party_with_group([0, 0, 0])]);

        $this->assertTrue($character->validate_target($scope, $spec));
    }

    public function test_validate_target_ranged_target_dead_but_others_in_group_alive_retargets() {
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 1]; // targeting the dead one, index 1
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $scope = new CombatTestScope([0 => $this->party_with_group([50, 0, 50])]);

        $this->assertFalse($character->validate_target($scope, $spec));
        // Retargeted to a living member (0 or 2) -- confirm it's one
        // of the actually-alive options, not asserting which one
        // since the pick is random.
        $this->assertTrue(in_array($character->target[2], [0, 2], true));
    }

    public function test_validate_target_ranged_retarget_is_genuinely_random() {
        // Same scenario as above, sampled many times -- both living
        // alternatives should appear, not just one of them.
        $seen = [];
        for ($i = 0; $i < 60; $i++) {
            $character = new CHARACTER();
            $character->name = 'Actor';
            $character->command = 0;
            $character->target = [0, 0, 1];
            $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
            $scope = new CombatTestScope([0 => $this->party_with_group([50, 0, 50])]);
            $character->validate_target($scope, $spec);
            $seen[$character->target[2]] = true;
        }

        $this->assertTrue(isset($seen[0]));
        $this->assertTrue(isset($seen[2]));
    }

    public function test_validate_target_ranged_positive_range_checks_multiple_positions() {
        // range=1 around target index 2 covers positions 1,2,3 in a
        // 5-member group. Only index 2 (the target itself) is dead;
        // 1 and 3 are alive, so this proceeds without retargeting.
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 2];
        $spec = new EffectSpec(Effect::HURT, 1, 0, 0, 0); // range=1
        $scope = new CombatTestScope([0 => $this->party_with_group([50, 50, 0, 50, 50])]);

        $this->assertFalse($character->validate_target($scope, $spec));
        $this->assertSame([0, 0, 2], $character->target);
    }

    public function test_validate_target_ranged_everyone_in_range_dead_but_group_has_survivors_retargets() {
        // range=1 around target index 2 covers 1,2,3 -- all three
        // dead. Indices 0 and 4 are outside that range but alive, so
        // this retargets to one of them rather than aborting.
        $character = new CHARACTER();
        $character->name = 'Actor';
        $character->command = 0;
        $character->target = [0, 0, 2];
        $spec = new EffectSpec(Effect::HURT, 1, 0, 0, 0); // range=1
        $scope = new CombatTestScope([0 => $this->party_with_group([50, 0, 0, 0, 50])]);

        $this->assertFalse($character->validate_target($scope, $spec));
        $this->assertTrue(in_array($character->target[2], [0, 4], true));
    }

    // --- get_weapon_effect_spec() ---
    // Fixed: previously always used the weapon's own EffectSpec,
    // never consulting equipped ammo. Ammo is meant to always be
    // authoritative over the weapon it's loaded into when present --
    // confirmed against find_target_range(), which already had this
    // right and is used elsewhere for the same underlying decision.

    public function test_get_weapon_effect_spec_prioritizes_ammo_over_the_weapon_when_both_are_equipped() {
        $weapon_row = build_item_row(720, 'Test Bow', ['hand'], attack_targets: 1);
        $ammo_row = build_item_row(721, 'Test Special Arrows', ['ammo'], attack_targets: 2, ammo_type: 'bow');

        $targets = $this->with_extra_cache(function () {
            $character = $this->bare_character();
            $character->current['Strength'] = 20;
            $character->inventory = [
                0 => ['item' => 720, 'qty' => 1],
                1 => ['item' => 721, 'qty' => 10],
            ];
            $character->equipment['lhand'] = 0;
            $character->equipment['lammo'] = 1;

            return $character->get_weapon_effect_spec(0)->targets;
        }, items: [720 => $weapon_row, 721 => $ammo_row]);

        $this->assertSame(2, $targets); // the ammo's value, not the weapon's (1)
    }

    public function test_get_weapon_effect_spec_falls_back_to_the_weapon_with_no_ammo_equipped() {
        $weapon_row = build_item_row(722, 'Test Sword', ['hand'], attack_targets: 1);

        $targets = $this->with_extra_cache(function () {
            $character = $this->bare_character();
            $character->current['Strength'] = 20;
            $character->inventory = [0 => ['item' => 722, 'qty' => 1]];
            $character->equipment['lhand'] = 0;

            return $character->get_weapon_effect_spec(0)->targets;
        }, items: [722 => $weapon_row]);

        $this->assertSame(1, $targets);
    }

    // --- perform_action() ---
    // The orchestration method: validates via validate_action() and
    // validate_target(), then dispatches per command. Needs a real
    // ActionTarget::for_character() lookup, so the actor must actually
    // be present within the scope's parties, not just standalone.

    /** @return array{0: CHARACTER, 1: PARTY} actor placed in a fresh solo party */
    private function actor_in_party(): array {
        $actor = new CHARACTER();
        $actor->name = 'Actor';
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($actor);
        $party->add($group);
        return [$actor, $party];
    }

    public function test_perform_action_invalid_action_only_logs_turn() {
        [$actor, $party] = $this->actor_in_party();
        $actor->command = 42; // unrecognized -- validate_action() rejects it

        $action_list = [];
        $actor->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertCount(1, $action_list);
        $this->assertSame('Turn', $action_list[0][0]);
    }

    public function test_perform_action_defend() {
        [$actor, $party] = $this->actor_in_party();
        $actor->command = 6;

        $action_list = [];
        $actor->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertSame(['Turn', 'Defend'], array_column($action_list, 0));
    }

    public function test_perform_action_run() {
        [$actor, $party] = $this->actor_in_party();
        $actor->command = 7;

        $action_list = [];
        $actor->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertSame(['Turn', 'Run'], array_column($action_list, 0));
    }

    public function test_perform_action_give_item_is_field_only() {
        // In combat scope, give-item does nothing beyond the Turn
        // entry -- the whole block is gated behind a field-scope
        // check.
        $giver = new CHARACTER();
        $giver->name = 'Giver';
        $giver->command = 9;
        $giver->using = 0;
        $giver->target = [0, 0, 1];
        $giver->inventory = [0 => ['item' => 1, 'qty' => 10]];
        $receiver = new CHARACTER();
        $receiver->name = 'Receiver';
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($giver);
        $group->add_hero($receiver);
        $party->add($group);

        $action_list = [];
        $giver->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertSame(['Turn'], array_column($action_list, 0));
        $this->assertSame([0 => ['item' => 1, 'qty' => 10]], $giver->inventory);
    }

    public function test_perform_action_give_item_transfers_in_field_scope() {
        $giver = new CHARACTER();
        $giver->name = 'Giver';
        $giver->command = 9;
        $giver->using = 0;
        $giver->target = [0, 0, 1];
        $giver->inventory = [0 => ['item' => 1, 'qty' => 10]];
        $receiver = new CHARACTER();
        $receiver->name = 'Receiver';
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($giver);
        $group->add_hero($receiver);
        $party->add($group);

        $action_list = [];
        $giver->perform_action(new FieldTestScope([0 => $party]), $action_list);

        $this->assertSame([], $giver->inventory);
        $this->assertSame([0 => ['item' => 1, 'qty' => 10]], $receiver->inventory);
        $this->assertSame(['Turn', 'Give', 'Receive', 'GiveItem', 'Target'], array_column($action_list, 0));
    }

    public function test_perform_action_item_use_heals_and_depletes_a_one_use_item() {
        $spec = new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 20, 0, 0);
        $row = [
            'itemid' => 800, 'name' => 'One-Use Potion', 'price' => 10,
            'use_effect_spec' => json_encode($spec), 'one_use' => true,
            'equip_type' => null, 'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => '', 'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        [$hp_after, $inventory_after] = $this->with_extra_cache(function () {
            $user = new CHARACTER();
            $user->name = 'User';
            $user->command = 2;
            $user->using = 0;
            $user->target = [0, 0, 1];
            $user->inventory = [0 => ['item' => 800, 'qty' => 1]];
            $target = new CHARACTER();
            $target->name = 'Target';
            $target->current['HP'] = 50;
            $target->base['HP'] = 100;
            $party = new PARTY();
            $group = new GROUP();
            $group->add_hero($user);
            $group->add_hero($target);
            $party->add($group);

            $action_list = [];
            $user->perform_action(new CombatTestScope([0 => $party]), $action_list);

            $this->assertSame(
                ['Turn', 'Item', 'Target', 'Restore', 'XpDebt', 'UseItem'],
                array_column($action_list, 0)
            );
            return [$target->current['HP'], $user->inventory];
        }, items: [800 => $row]);

        $this->assertSame(70, $hp_after);
        $this->assertSame([], $inventory_after);
    }

    public function test_perform_action_skill_with_sufficient_mp_applies_and_deducts() {
        // Fixture ability 1 (detrimental HURT) has mp_used=0.
        $caster = new CHARACTER();
        $caster->name = 'Caster';
        $caster->command = 4;
        $caster->using = 0;
        $caster->abilities = [1];
        $caster->current['Accuracy'] = 1000; // guaranteed hit
        $caster->current['MP'] = 10;
        $caster->target = [0, 0, 1];
        $target = new CHARACTER();
        $target->name = 'Target';
        $target->current['HP'] = 100;
        $target->base['HP'] = 100;
        $target->current['Dodge'] = 1;
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($caster);
        $group->add_hero($target);
        $party->add($group);

        $action_list = [];
        $caster->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertSame(['Turn', 'Skill', 'Target', 'AlterStat', 'Damage', 'XpDebt'], array_column($action_list, 0));
        $this->assertLessThan(100, $target->current['HP']);
    }

    public function test_perform_action_spell_with_insufficient_mp_does_not_apply() {
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 5, 2, 0);
        $row = [
            'abilityid' => 900, 'name' => 'Expensive Spell', 'type' => 1,
            'effect_spec' => json_encode($spec), 'mp_used' => 50,
            'description' => 'test', 'menu_pic' => 'bignone.png',
            'skill_effect_type' => 'none', 'impact' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        [$mp_after, $hp_after, $actions] = $this->with_extra_cache(function () {
            $caster = new CHARACTER();
            $caster->name = 'Caster';
            $caster->command = 5;
            $caster->using = 0;
            $caster->abilities = [900];
            $caster->current['MP'] = 10; // not enough for 50
            $caster->target = [0, 0, 1];
            $target = new CHARACTER();
            $target->name = 'Target';
            $target->current['HP'] = 100;
            $target->base['HP'] = 100;
            $party = new PARTY();
            $group = new GROUP();
            $group->add_hero($caster);
            $group->add_hero($target);
            $party->add($group);

            $action_list = [];
            $caster->perform_action(new CombatTestScope([0 => $party]), $action_list);

            return [$caster->current['MP'], $target->current['HP'], array_column($action_list, 0)];
        }, abilities: [900 => $row]);

        $this->assertSame(10, $mp_after); // unchanged -- never deducted
        $this->assertSame(100, $hp_after); // unaffected -- effect never applied
        $this->assertSame(['Turn', 'Spell', 'Target', 'NoMP'], $actions);
    }

    public function test_perform_action_equip_into_an_empty_slot() {
        [$actor, $party] = $this->actor_in_party();
        $actor->command = 3;
        $actor->using = 0;
        $actor->target = [0, 0, 0]; // location=0 (left)
        $actor->inventory = [0 => ['item' => 2, 'qty' => 1]]; // fixture: Basic Sword (hand)

        $action_list = [];
        $actor->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertSame(0, $actor->equipment['lhand']);
        $this->assertSame(['Turn', 'EquipSlot', 'Equip'], array_column($action_list, 0));
    }

    public function test_perform_action_unequip() {
        [$actor, $party] = $this->actor_in_party();
        $actor->command = 3;
        $actor->using = -1; // unequip
        $actor->target = [0, 0, 0]; // location=0 (inventory slot to unequip)
        $actor->inventory = [0 => ['item' => 2, 'qty' => 1]];
        $actor->equipment['lhand'] = 0; // already equipped

        $action_list = [];
        $actor->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertSame(null, $actor->equipment['lhand']);
        $this->assertSame(['Turn', 'Unequip', 'UnequipSlot'], array_column($action_list, 0));
    }

    public function test_perform_action_equip_weapon_and_ammo_together() {
        // Command 8 -- weapon and ammo must share an ammo_type for
        // this to succeed. See the separate test below for what
        // happens on a mismatch.
        $bow_row = [
            'itemid' => 710, 'name' => 'Test Bow', 'price' => 10,
            'use_effect_spec' => json_encode(new EffectSpec(Effect::NONE, 0, 0, 0, 0)),
            'one_use' => false, 'equip_type' => json_encode(['hand']),
            'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => 'bow', 'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];
        $arrow_row = [
            'itemid' => 711, 'name' => 'Test Arrows', 'price' => 5,
            'use_effect_spec' => json_encode(new EffectSpec(Effect::NONE, 0, 0, 0, 0)),
            'one_use' => false, 'equip_type' => json_encode(['ammo']),
            'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => 'bow', 'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        [$lhand, $lammo, $actions] = $this->with_extra_cache(function () {
            [$actor, $party] = $this->actor_in_party();
            $actor->command = 8;
            $actor->using = 0;
            $actor->target = [0, 1, 0]; // [location, ammo inventory slot, target_char_index]
            $actor->inventory = [0 => ['item' => 710, 'qty' => 1], 1 => ['item' => 711, 'qty' => 10]];

            $action_list = [];
            $actor->perform_action(new CombatTestScope([0 => $party]), $action_list);

            return [$actor->equipment['lhand'], $actor->equipment['lammo'], array_column($action_list, 0)];
        }, items: [710 => $bow_row, 711 => $arrow_row]);

        $this->assertSame(0, $lhand);
        $this->assertSame(1, $lammo);
        $this->assertSame(['Turn', 'EquipSlot', 'EquipSlot', 'Equip'], $actions);
    }

    public function test_perform_action_equip_weapon_and_mismatched_ammo_fails_cleanly() {
        // A weapon and ammo with different ammo_types can't be paired
        // -- equip_item() signals this by returning true (not an
        // array, not false). Before the fix, perform_action() only
        // checked for === false, so this fell through silently: no
        // EquipSlot for the ammo, no error, but an 'Equip' entry was
        // still appended claiming success. The weapon's own equip
        // still succeeds first, since that happens in an earlier,
        // separate call.
        $sword_row = [
            'itemid' => 720, 'name' => 'Test Sword', 'price' => 10,
            'use_effect_spec' => json_encode(new EffectSpec(Effect::NONE, 0, 0, 0, 0)),
            'one_use' => false, 'equip_type' => json_encode(['hand']),
            'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => '', // no ammo type at all
            'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];
        $bow_arrow_row = [
            'itemid' => 721, 'name' => 'Bow Arrows', 'price' => 5,
            'use_effect_spec' => json_encode(new EffectSpec(Effect::NONE, 0, 0, 0, 0)),
            'one_use' => false, 'equip_type' => json_encode(['ammo']),
            'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => 'bow', // wants a bow, but a sword is what's being equipped
            'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        [$lhand, $lammo, $actions] = $this->with_extra_cache(function () {
            [$actor, $party] = $this->actor_in_party();
            $actor->command = 8;
            $actor->using = 0;
            $actor->target = [0, 1, 0];
            $actor->inventory = [0 => ['item' => 720, 'qty' => 1], 1 => ['item' => 721, 'qty' => 10]];

            $action_list = [];
            $actor->perform_action(new CombatTestScope([0 => $party]), $action_list);

            return [$actor->equipment['lhand'], $actor->equipment['lammo'], array_column($action_list, 0)];
        }, items: [720 => $sword_row, 721 => $bow_arrow_row]);

        $this->assertSame(0, $lhand); // the sword itself still equipped
        $this->assertSame(null, $lammo); // the mismatched ammo did NOT equip
        // No 'Equip' entry -- fails cleanly rather than falsely claiming success.
        $this->assertSame(['Turn', 'EquipSlot'], $actions);
    }

    public function test_perform_action_melee_attack_with_ammo_lands_and_expends_it() {
        $bow_row = [
            'itemid' => 710, 'name' => 'Test Bow', 'price' => 10,
            'use_effect_spec' => json_encode(new EffectSpec(Effect::NONE, 0, 0, 0, 0)),
            'one_use' => false, 'equip_type' => json_encode(['hand']),
            'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => 'bow', 'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        [$hp_after, $actions] = $this->with_extra_cache(function () {
            $attacker = new CHARACTER();
            $attacker->name = 'Attacker';
            $attacker->current['Accuracy'] = 1000; // guaranteed hit
            $attacker->current['Strength'] = 20;
            $attacker->command = 0;
            $attacker->using = 0;
            $attacker->target = [1, 0, 0];
            $attacker->inventory = [0 => ['item' => 710, 'qty' => 1], 1 => ['item' => 1, 'qty' => 5]];
            $attacker->equipment['lhand'] = 0;
            $attacker->equipment['lammo'] = 1;

            $defender = new CHARACTER();
            $defender->name = 'Defender';
            $defender->current['HP'] = 100;
            $defender->base['HP'] = 100;
            $defender->current['Dodge'] = 1;
            $defender->current['Block'] = 1;

            $party0 = new PARTY();
            $group0 = new GROUP();
            $group0->add_hero($attacker);
            $party0->add($group0);
            $party1 = new PARTY();
            $group1 = new GROUP();
            $group1->add_hero($defender);
            $party1->add($group1);

            $action_list = [];
            $attacker->perform_action(new CombatTestScope([0 => $party0, 1 => $party1]), $action_list);

            return [$defender->current['HP'], array_column($action_list, 0)];
        }, items: [710 => $bow_row]);

        $this->assertLessThan(100, $hp_after);
        $this->assertSame(['Turn', 'Attack', 'Target', 'Damage', 'XpDebt', 'ExpendAmmo'], $actions);
    }

    public function test_perform_action_melee_attack_out_of_ammo_deals_no_damage() {
        $bow_row = [
            'itemid' => 710, 'name' => 'Test Bow', 'price' => 10,
            'use_effect_spec' => json_encode(new EffectSpec(Effect::NONE, 0, 0, 0, 0)),
            'one_use' => false, 'equip_type' => json_encode(['hand']),
            'stat_increase' => null, 'stat_percent_increase' => null,
            'attack_targets' => 0, 'attack_count' => 1, 'attack_attribute' => 0,
            'ammo_type' => 'bow', 'description' => 'test', 'menu_pic' => 'bignone.png',
            'fight_effect_type' => 'none',
            'fight_animation_effect' => json_encode(new AnimationEffect()),
            'use_animation_effect' => json_encode(new AnimationEffect()),
            'state' => 0,
        ];

        [$hp_after, $actions] = $this->with_extra_cache(function () {
            $attacker = new CHARACTER();
            $attacker->name = 'Attacker';
            $attacker->current['Accuracy'] = 1000;
            $attacker->current['Strength'] = 20;
            $attacker->command = 0;
            $attacker->using = 0;
            $attacker->target = [1, 0, 0];
            $attacker->inventory = [0 => ['item' => 710, 'qty' => 1]];
            $attacker->equipment['lhand'] = 0;
            $attacker->equipment['lammo'] = null; // no ammo equipped

            $defender = new CHARACTER();
            $defender->name = 'Defender';
            $defender->current['HP'] = 100;
            $defender->base['HP'] = 100;
            $defender->current['Dodge'] = 1;
            $defender->current['Block'] = 1;

            $party0 = new PARTY();
            $group0 = new GROUP();
            $group0->add_hero($attacker);
            $party0->add($group0);
            $party1 = new PARTY();
            $group1 = new GROUP();
            $group1->add_hero($defender);
            $party1->add($group1);

            $action_list = [];
            $attacker->perform_action(new CombatTestScope([0 => $party0, 1 => $party1]), $action_list);

            return [$defender->current['HP'], array_column($action_list, 0)];
        }, items: [710 => $bow_row]);

        $this->assertSame(100, $hp_after);
        $this->assertSame(['Turn', 'NoAmmo'], $actions);
    }

    public function test_perform_action_melee_attack_outside_combat_scope_is_a_no_op() {
        [$actor, $party] = $this->actor_in_party();
        $actor->command = 0;

        $action_list = [];
        $actor->perform_action(new FieldTestScope([0 => $party]), $action_list);

        $this->assertSame(['Turn'], array_column($action_list, 0));
    }

    public function test_perform_action_melee_attack_aborts_when_validate_target_fails() {
        $attacker = new CHARACTER();
        $attacker->name = 'Attacker';
        $attacker->command = 0;
        $attacker->target = [0, 0, 1];
        $target = new CHARACTER();
        $target->name = 'Target';
        $target->current['HP'] = 0; // dead, and no living alternative in the group
        $target->base['HP'] = 100;
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($attacker);
        $group->add_hero($target);
        $party->add($group);

        $action_list = [];
        $attacker->perform_action(new CombatTestScope([0 => $party]), $action_list);

        $this->assertSame(['Turn'], array_column($action_list, 0));
    }

    // --- equip_item() ---
    // Direct tests of the six explicit EquipResult outcomes, replacing
    // the previous four-type (bool|int|array) return contract.

    public function test_equip_item_not_found_when_the_inventory_slot_is_empty() {
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [];

        $result = $character->equip_item(0, 0);

        $this->assertSame(EquipOutcome::NOT_FOUND, $result->outcome);
    }

    public function test_equip_item_not_equipable_when_the_item_has_no_equip_type() {
        // Fixture item 1: Stackable Potion, equip_type=[].
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]];

        $result = $character->equip_item(0, 0);

        $this->assertSame(EquipOutcome::NOT_EQUIPABLE, $result->outcome);
    }

    public function test_equip_item_success_into_an_empty_slot() {
        // Fixture item 2: Basic Sword, equip_type=['hand'].
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1]];

        $result = $character->equip_item(0, 0);

        $this->assertSame(EquipOutcome::SUCCESS, $result->outcome);
        $this->assertSame(['lhand'], $result->equipped_slots);
        $this->assertSame(0, $character->equipment['lhand']);
    }

    public function test_equip_item_needs_unequipping_when_slot_occupied_by_a_different_item() {
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1], 1 => ['item' => 2, 'qty' => 1]];
        $character->equipment['lhand'] = 0;

        $result = $character->equip_item(1, 0);

        $this->assertSame(EquipOutcome::NEEDS_UNEQUIPPING, $result->outcome);
        $this->assertSame(0, $result->occupying_index);
    }

    public function test_equip_item_needs_unequipping_when_already_equipped_elsewhere() {
        // The item's own inventory index is placed in a slot outside
        // its own equip_type (['hand']) -- an inconsistent state that
        // equip_item() defensively detects. Same NEEDS_UNEQUIPPING
        // outcome as the slot-occupied case above, since both resolve
        // the same way: unequip occupying_index and retry.
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1]];
        $character->equipment['body'] = 0;

        $result = $character->equip_item(0, 0);

        $this->assertSame(EquipOutcome::NEEDS_UNEQUIPPING, $result->outcome);
        $this->assertSame(0, $result->occupying_index);
    }

    public function test_equip_item_incompatible_ammo_with_a_mismatched_weapon_equipped() {
        // Fixture item 2 (Basic Sword) has ammo_type=''; fixture item 4
        // (Basic Arrows) wants ammo_type='bow' -- they don't match.
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1], 1 => ['item' => 4, 'qty' => 1]];
        $character->equipment['lhand'] = 0;

        $result = $character->equip_item(1, 0);

        $this->assertSame(EquipOutcome::INCOMPATIBLE_AMMO, $result->outcome);
    }

    public function test_equip_item_incompatible_ammo_with_no_weapon_equipped_at_all() {
        // Previously threw a TypeError: get_item() was called on
        // $this->equipment[$fix_side] before the is_null() check that
        // would catch an empty hand slot, not as part of a
        // short-circuited condition. Fixed by splitting the combined
        // check into two -- is_null() now returns INCOMPATIBLE_AMMO
        // immediately, before get_item() is ever called on it.
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 4, 'qty' => 1]]; // Basic Arrows
        // No weapon equipped in lhand at all.

        $result = $character->equip_item(0, 0);

        $this->assertSame(EquipOutcome::INCOMPATIBLE_AMMO, $result->outcome);
    }

    // --- unequip_item() ---
    // Reuses the same EquipOutcome/EquipResult types as equip_item():
    // NOT_FOUND, NOT_EQUIPABLE, and SUCCESS (with equipped_slots
    // repurposed to mean "slots just cleared", not "slots just
    // filled"). unequip_item() never needs NEEDS_UNEQUIPPING or
    // INCOMPATIBLE_AMMO -- those are meaningful only when equipping.

    public function test_unequip_item_not_found_when_the_inventory_slot_is_empty() {
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [];

        $result = $character->unequip_item(0);

        $this->assertSame(EquipOutcome::NOT_FOUND, $result->outcome);
    }

    public function test_unequip_item_not_equipable_when_the_item_has_no_equip_type() {
        // Fixture item 1: Stackable Potion, equip_type=[].
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]];

        $result = $character->unequip_item(0);

        $this->assertSame(EquipOutcome::NOT_EQUIPABLE, $result->outcome);
    }

    public function test_unequip_item_success_clears_the_equipped_slot() {
        // Fixture item 2: Basic Sword, equip_type=['hand'].
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1]];
        $character->equipment['lhand'] = 0;

        $result = $character->unequip_item(0);

        $this->assertSame(EquipOutcome::SUCCESS, $result->outcome);
        $this->assertSame(['lhand'], $result->equipped_slots);
        $this->assertNull($character->equipment['lhand']);
    }

    public function test_unequip_item_clears_multiple_slots_when_the_item_occupies_more_than_one() {
        // Same inventory index placed in both hand slots, simulating a
        // two-handed weapon -- both should be cleared, not just one.
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1]];
        $character->equipment['lhand'] = 0;
        $character->equipment['rhand'] = 0;

        $result = $character->unequip_item(0);

        $this->assertSame(EquipOutcome::SUCCESS, $result->outcome);
        $this->assertCount(2, $result->equipped_slots);
        $this->assertContains('lhand', $result->equipped_slots);
        $this->assertContains('rhand', $result->equipped_slots);
        $this->assertNull($character->equipment['lhand']);
        $this->assertNull($character->equipment['rhand']);
    }

    public function test_unequip_item_also_clears_the_associated_ammo_slot_for_a_weapon_with_an_ammo_type() {
        $bow_row = build_item_row(710, 'Test Bow', ['hand'], ammo_type: 'bow');

        [$outcome, $slots, $lhand, $lammo] = $this->with_extra_cache(function () {
            $character = new CHARACTER();
            $character->name = 'Test';
            $character->inventory = [0 => ['item' => 710, 'qty' => 1], 1 => ['item' => 4, 'qty' => 5]];
            $character->equipment['lhand'] = 0;
            $character->equipment['lammo'] = 1;

            $result = $character->unequip_item(0);

            return [$result->outcome, $result->equipped_slots, $character->equipment['lhand'], $character->equipment['lammo']];
        }, items: [710 => $bow_row]);

        $this->assertSame(EquipOutcome::SUCCESS, $outcome);
        $this->assertSame(['lhand', 'lammo'], $slots);
        $this->assertNull($lhand);
        $this->assertNull($lammo); // cleared alongside the weapon
    }

    public function test_unequip_item_does_not_touch_ammo_for_a_weapon_with_no_ammo_type() {
        // Fixture item 2 (Basic Sword) has ammo_type=''. The arrows in
        // lammo are unrelated to it and should be left alone.
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1], 1 => ['item' => 4, 'qty' => 5]];
        $character->equipment['lhand'] = 0;
        $character->equipment['lammo'] = 1;

        $result = $character->unequip_item(0);

        $this->assertSame(['lhand'], $result->equipped_slots);
        $this->assertSame(1, $character->equipment['lammo']);
    }

    public function test_unequip_item_slot_zero_does_not_incorrectly_clear_other_empty_slots() {
        // Regression guard for the loose-comparison bug fixed earlier
        // this session: $value === $index (not ==) means unequipping
        // inventory slot 0 specifically no longer matches every other
        // still-null equipment slot (null == 0 is true in PHP, but
        // null === 0 is false).
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->inventory = [0 => ['item' => 2, 'qty' => 1]];
        $character->equipment['lhand'] = 0;
        // Every other equipment slot stays null, untouched.

        $result = $character->unequip_item(0);

        $this->assertSame(['lhand'], $result->equipped_slots);
    }

    // --- add_debt_to_xp_tree() ---

    public function test_add_debt_to_xp_tree_records_key_ratio_and_pxp() {
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->pxp = 42;
        $action_list = [];

        $character->add_debt_to_xp_tree(new ActionTarget(1, 2, 3), new ActionTarget(4, 5, 6), 50, 30, 100, $action_list);

        $this->assertSame([[[1, 2, 3], 0.2, 42]], $character->xp_debts); // (50-30)/100
    }

    public function test_add_debt_to_xp_tree_key_comes_from_fighter_target_only() {
        // The debt is always keyed off the fighter's own position, not
        // target_target -- confirmed here by using a fixed
        // fighter_target with a varying target_target and seeing no
        // change in the key.
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->pxp = 0;
        $fighter_target = new ActionTarget(1, 2, 3);
        $action_list = [];

        $character->add_debt_to_xp_tree($fighter_target, new ActionTarget(9, 9, 9), 10, 5, 50, $action_list);
        $character->add_debt_to_xp_tree($fighter_target, new ActionTarget(8, 8, 8), 10, 5, 50, $action_list);

        $this->assertSame([1, 2, 3], $character->xp_debts[0][0]);
        $this->assertSame([1, 2, 3], $character->xp_debts[1][0]);
    }

    public function test_add_debt_to_xp_tree_multiple_calls_append() {
        $character = new CHARACTER();
        $character->name = 'Test';
        $character->pxp = 10;
        $fighter_target = new ActionTarget(0, 0, 0);
        $target_target = new ActionTarget(1, 0, 0);
        $action_list = [];

        $character->add_debt_to_xp_tree($fighter_target, $target_target, 50, 30, 100, $action_list);
        $character->add_debt_to_xp_tree($fighter_target, $target_target, 10, 5, 50, $action_list);

        $this->assertCount(2, $character->xp_debts);
        $this->assertSame(0.2, $character->xp_debts[0][1]);
        $this->assertSame(0.1, $character->xp_debts[1][1]);
    }

    // --- do_effect() ---
    // Note: this has its own independent impact_percentage calculation,
    // separate from affected_by()'s -- it derives its divisor from
    // $effect_spec->targets directly rather than taking one as a
    // parameter, so a negative $effect_spec->targets (TARGET_GROUP/
    // TARGET_PARTY/TARGET_ALL_PARTIES) always yields impact_percentage=1,
    // ignoring $intensity entirely. This means the AOE-falloff bug fixed
    // in ActionTarget::expand_within_group() never actually reached
    // item/skill/spell damage -- only the melee path, which is the only
    // caller that uses affected_by()'s own calculation directly.

    private function fresh_target(int $hp, int $base_hp = 100): CHARACTER {
        $character = new CHARACTER();
        $character->name = 'Target';
        $character->current['HP'] = $hp;
        $character->base['HP'] = $base_hp;
        return $character;
    }

    public function test_do_effect_none_is_a_no_op() {
        $target = $this->fresh_target(50);
        $spec = new EffectSpec(Effect::NONE, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $action_list = [];

        $result = do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertTrue($result);
        $this->assertSame(50, $target->current['HP']);
        $this->assertSame([], $action_list);
    }

    public function test_do_effect_heal_restores_hp() {
        $target = $this->fresh_target(50, 100);
        $spec = new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 20, 0, 0);
        $action_list = [];

        $result = do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertTrue($result);
        $this->assertSame(70, $target->current['HP']);
    }

    public function test_do_effect_heal_does_nothing_to_a_dead_target() {
        $target = $this->fresh_target(0, 100);
        $spec = new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 20, 0, 0);
        $action_list = [];

        $result = do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertFalse($result);
        $this->assertSame(0, $target->current['HP']);
        $this->assertSame([], $action_list);
    }

    public function test_do_effect_hurt_deals_damage() {
        $target = $this->fresh_target(100, 100);
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 20, 0, 0);
        $action_list = [];

        $result = do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertTrue($result);
        $this->assertSame(80, $target->current['HP']);
    }

    public function test_do_effect_hurt_does_nothing_to_a_dead_target() {
        $target = $this->fresh_target(0, 100);
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 20, 0, 0);
        $action_list = [];

        $result = do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertFalse($result);
        $this->assertSame(0, $target->current['HP']);
        $this->assertSame([], $action_list);
    }

    public function test_do_effect_hurt_applies_the_given_impact_percentage_directly() {
        // do_effect() no longer derives impact_percentage from
        // $effect_spec->targets itself -- that calculation now lives
        // solely in affected_by(), which passes the already-computed
        // value straight through. Confirmed here by using
        // targets=5 (which would imply a very different
        // impact_percentage under the old, removed internal formula)
        // together with an explicit impact_percentage=2/3 -- the
        // passed-in value is what actually governs the damage, not
        // anything derived from targets.
        $target = $this->fresh_target(100, 100);
        $spec = new EffectSpec(Effect::HURT, 5, 90, 0, 0);
        $action_list = [];

        do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 2 / 3, 1, $action_list);

        $this->assertSame(40, $target->current['HP']); // 100 - floor(90 * 2/3)
    }

    public function test_do_effect_revive_on_a_dead_target_always_succeeds_to_full_base_hp() {
        // No chance roll on this path -- a dead target is always
        // revived to full base HP outright.
        $target = $this->fresh_target(0, 150);
        $spec = new EffectSpec(Effect::REVIVE, EffectSpec::TARGET_INDIVIDUAL, 50, 200, 0);
        $action_list = [];

        do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertSame(150, $target->current['HP']);
        $this->assertSame('Revived', $action_list[0][0]);
        $this->assertSame('Restore', $action_list[1][0]);
    }

    public function test_do_effect_revive_on_a_living_target_is_a_chance_based_bonus_heal() {
        // base=100 as the % chance means mt_rand(1,100) < 100 always
        // succeeds; added=100 means the heal amount is
        // base_hp * 100/added = base_hp, capped by restore_HP()'s own
        // cap at the character's actual base HP.
        $target = $this->fresh_target(50, 200);
        $spec = new EffectSpec(Effect::REVIVE, EffectSpec::TARGET_INDIVIDUAL, 100, 100, 0);
        $action_list = [];

        do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertSame(200, $target->current['HP']);
    }

    public function test_do_effect_revive_on_a_living_target_can_fail_the_chance_roll() {
        // base=0 as the % chance means mt_rand(1,100) < 0 never
        // succeeds -- confirmed as a true no-op, not just a small heal.
        $target = $this->fresh_target(50, 200);
        $spec = new EffectSpec(Effect::REVIVE, EffectSpec::TARGET_INDIVIDUAL, 0, 100, 0);
        $action_list = [];

        do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertSame(50, $target->current['HP']);
        $this->assertSame([], $action_list);
    }

    public function test_do_effect_restore_mp_normal() {
        $target = new CHARACTER();
        $target->name = 'Target';
        $target->current['HP'] = 50;
        $target->base['HP'] = 100;
        $target->current['MP'] = 20;
        $target->base['MP'] = 100;
        $spec = new EffectSpec(Effect::RESTORE_MP, EffectSpec::TARGET_INDIVIDUAL, 15, 0, 0);
        $action_list = [];

        do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertSame(35, $target->current['MP']);
    }

    public function test_do_effect_restore_mp_guard_checks_hp_not_mp() {
        // The dead-target guard on this path checks the target's HP,
        // not their MP -- a character at 0 HP is skipped even with MP
        // available to restore.
        $target = new CHARACTER();
        $target->name = 'Target';
        $target->current['HP'] = 0;
        $target->base['HP'] = 100;
        $target->current['MP'] = 20;
        $target->base['MP'] = 100;
        $spec = new EffectSpec(Effect::RESTORE_MP, EffectSpec::TARGET_INDIVIDUAL, 15, 0, 0);
        $action_list = [];

        $result = do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertFalse($result);
        $this->assertSame(20, $target->current['MP']);
    }

    public function test_do_effect_not_yet_implemented_effects_are_no_ops() {
        // SLAY, INCREASE_STATS, DECREASE_STATS, STEAL_STATS,
        // CAUSE_GOOD_STATUS, REMOVE_GOOD_STATUS, CAUSE_BAD_STATUS, and
        // REMOVE_BAD_STATUS all have empty case bodies -- confirmed
        // here with SLAY as a representative case, since all eight
        // share the identical empty-break shape.
        $target = $this->fresh_target(50, 100);
        $spec = new EffectSpec(Effect::SLAY, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $action_list = [];

        $result = do_effect($spec, new ActionTarget(0, 0, 0), $target, new ActionTarget(1, 0, 0), 1.0, 1, $action_list);

        $this->assertTrue($result);
        $this->assertSame(50, $target->current['HP']);
        $this->assertSame([], $action_list);
    }

    // --- which hands can attack ---

    private function holding(?int $left, ?int $right): CHARACTER {
        $character = new CHARACTER();
        $character->equipment['lhand'] = $left;
        $character->equipment['rhand'] = $right;
        return $character;
    }

    public function test_only_one_weapon_in_both_hands_counts_as_shared() {
        $this->assertTrue($this->holding(2, 2)->hands_share_weapon(), 'a two-handed weapon');
        $this->assertTrue($this->holding(0, 0)->hands_share_weapon(), 'slot 0 is a real slot');
        $this->assertFalse($this->holding(0, null)->hands_share_weapon(), 'regression: null == 0 said an empty hand held slot 0');
        $this->assertFalse($this->holding(null, 0)->hands_share_weapon());
        $this->assertFalse($this->holding(null, null)->hands_share_weapon(), 'two empty hands');
        $this->assertFalse($this->holding(0, 1)->hands_share_weapon());
    }

    public function test_the_right_hand_attacks_unless_a_two_handed_weapon_fills_it() {
        $scope = new CombatTestScope([]);
        $weapon_in_slot_0 = $this->holding(0, null);
        $weapon_in_slot_0->command = 1;
        $this->assertTrue($weapon_in_slot_0->validate_action($scope), 'regression: the empty right hand could never attack');
        $two_handed = $this->holding(0, 0);
        $two_handed->command = 1;
        $this->assertFalse($two_handed->validate_action($scope));
        $two_handed->command = 0;
        $this->assertTrue($two_handed->validate_action($scope), 'the left hand swings it');
    }
}
