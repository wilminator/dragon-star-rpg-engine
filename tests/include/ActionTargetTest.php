<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'party.php'; // not in bootstrap.php's require chain

/**
 * Tests for ActionTarget::expand()'s intensity/divisor convention.
 *
 * Focused on the AOE-impact bug: expand_within_group() (reached by
 * both TARGET_PARTY and TARGET_GROUP) previously hardcoded
 * yield [..., 1, 1], which affected_by()'s falloff formula
 * ($divisor + 1 - $intensity) / ($divisor + 1.0) actually computes as
 * 0.5 -- half impact -- not the "unscaled hit" the (now corrected)
 * docstring describes. TARGET_INDIVIDUAL, via expand_ranged() with
 * range=0, already correctly yields (0, 0), which the same formula
 * computes as 1.0 (full impact). The fix makes expand_within_group()
 * yield (0, 0) too, so every AOE effect (party- or group-wide) now
 * has the same per-recipient impact as a single-target hit with
 * identical stats, instead of being silently halved.
 *
 * Relies on tests/fixtures/cache/ via tests/bootstrap.php, same as
 * CharacterTest.php.
 */
class ActionTargetTest extends TestCase {

    private function scope(array $parties, ScopeType $type = ScopeType::COMBAT): ActionScope {
        return new class($parties, $type) implements ActionScope {
            public function __construct(private array $parties, private ScopeType $type) {}
            public function get_parties(): array { return $this->parties; }
            public function get_scope_type(): ScopeType { return $this->type; }
        };
    }

    private function fresh_character(string $name, int $hp = 100): CHARACTER {
        $character = new CHARACTER();
        $character->name = $name;
        $character->current['HP'] = $hp;
        $character->base['HP'] = $hp;
        $character->current['Dodge'] = 1;
        $character->current['Block'] = 1;
        return $character;
    }

    // --- for_character() ---

    public function test_for_character_finds_the_characters_position() {
        $other = $this->fresh_character('Other');
        $target_char = $this->fresh_character('Findme');
        $party = new PARTY();
        $group0 = new GROUP();
        $group0->add_hero($other);
        $party->add($group0);
        $group1 = new GROUP();
        $group1->add_hero($target_char);
        $party->add($group1);
        $scope = $this->scope([0 => $party]);

        $result = ActionTarget::for_character($scope, $target_char);

        $this->assertSame(0, $result->party_index);
        $this->assertSame(1, $result->group_index);
        $this->assertSame(0, $result->char_index);
    }

    public function test_for_character_returns_negative_ones_when_not_found() {
        $stranger = $this->fresh_character('Stranger');
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($this->fresh_character('Other'));
        $party->add($group);
        $scope = $this->scope([0 => $party]);

        $result = ActionTarget::for_character($scope, $stranger);

        $this->assertSame(-1, $result->party_index);
        $this->assertSame(-1, $result->group_index);
        $this->assertSame(-1, $result->char_index);
    }

    // --- expand(): TARGET_ALL_PARTIES ---

    public function test_expand_target_all_parties_reaches_every_party() {
        $partyA = new PARTY();
        $groupA = new GROUP();
        $groupA->add_hero($this->fresh_character('A'));
        $partyA->add($groupA);
        $partyB = new PARTY();
        $groupB = new GROUP();
        $groupB->add_hero($this->fresh_character('B'));
        $partyB->add($groupB);
        $scope = $this->scope([0 => $partyA, 1 => $partyB]);

        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_ALL_PARTIES, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0); // center is irrelevant for ALL_PARTIES
        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertCount(2, $results);
        $names = array_map(fn($r) => $r[1]->name, $results);
        $this->assertSame(['A', 'B'], $names);
    }

    public function test_expand_target_all_parties_works_even_with_an_out_of_range_center_party() {
        // TARGET_ALL_PARTIES is the one case that doesn't require
        // $this->party_index to exist in the scope at all -- expand()
        // explicitly carves out this exception before the switch.
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($this->fresh_character('A'));
        $party->add($group);
        $scope = $this->scope([0 => $party]);

        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_ALL_PARTIES, 0, 0, 0);
        $target = new ActionTarget(99, 99, 99); // doesn't exist in scope
        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertCount(1, $results);
    }

    // --- expand(): missing-scope edge cases ---

    public function test_expand_target_party_yields_nothing_when_party_missing() {
        $scope = $this->scope([]);
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_PARTY, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0);

        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertSame([], $results);
    }

    public function test_expand_target_group_yields_nothing_when_group_missing() {
        $party = new PARTY();
        $scope = $this->scope([0 => $party]); // no groups at all
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0);

        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertSame([], $results);
    }

    public function test_expand_ranged_yields_nothing_when_group_missing() {
        $party = new PARTY();
        $scope = $this->scope([0 => $party]);
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0);

        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertSame([], $results);
    }

    public function test_expand_ranged_yields_nothing_when_char_index_missing() {
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero($this->fresh_character('A'));
        $party->add($group);
        $scope = $this->scope([0 => $party]);
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $target = new ActionTarget(0, 0, 5); // no character at index 5

        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertSame([], $results);
    }

    // --- expand(): should_skip() living-target filtering ---

    public function test_expand_excludes_dead_targets_for_effects_that_require_living_targets() {
        $group = new GROUP();
        $group->add_hero($this->fresh_character('Alive', 50));
        $group->add_hero($this->fresh_character('Dead', 0));
        $party = new PARTY();
        $party->add($group);
        $scope = $this->scope([0 => $party]);

        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0); // HURT requires living targets
        $target = new ActionTarget(0, 0, 0);
        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertCount(1, $results);
        $this->assertSame('Alive', $results[0][1]->name);
    }

    public function test_expand_includes_dead_targets_for_effects_that_do_not_require_living_targets() {
        $group = new GROUP();
        $group->add_hero($this->fresh_character('Alive', 50));
        $group->add_hero($this->fresh_character('Dead', 0));
        $party = new PARTY();
        $party->add($group);
        $scope = $this->scope([0 => $party]);

        $spec = new EffectSpec(Effect::REVIVE, EffectSpec::TARGET_GROUP, 0, 0, 0); // REVIVE does not require living targets
        $target = new ActionTarget(0, 0, 0);
        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertCount(2, $results);
    }

    public function test_target_group_yields_unscaled_intensity_and_divisor() {
        $character = $this->fresh_character('A');
        $group = new GROUP();
        $group->add_hero($character);
        $party = new PARTY();
        $party->add($group);
        $scope = $this->scope([0 => $party]);

        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0);
        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertCount(1, $results);
        [, , $intensity, $divisor] = $results[0];
        $this->assertSame(0, $intensity);
        $this->assertSame(0, $divisor);
    }

    public function test_target_party_yields_unscaled_intensity_and_divisor() {
        $character = $this->fresh_character('A');
        $group = new GROUP();
        $group->add_hero($character);
        $party = new PARTY();
        $party->add($group);
        $scope = $this->scope([0 => $party]);

        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_PARTY, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0);
        $results = iterator_to_array($target->expand($scope, $spec), false);

        $this->assertCount(1, $results);
        [, , $intensity, $divisor] = $results[0];
        $this->assertSame(0, $intensity);
        $this->assertSame(0, $divisor);
    }

    public function test_target_group_and_target_individual_yield_the_same_pair() {
        // TARGET_INDIVIDUAL (via expand_ranged() with range=0) is the
        // known-correct "unscaled hit" case -- confirming TARGET_GROUP
        // matches it exactly, rather than just checking (0, 0)
        // in isolation, ties the fix directly to the case it must agree with.
        $character = $this->fresh_character('A');
        $group = new GROUP();
        $group->add_hero($character);
        $party = new PARTY();
        $party->add($group);
        $scope = $this->scope([0 => $party]);

        $group_spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $individual_spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0);

        [, , $group_intensity, $group_divisor] = iterator_to_array($target->expand($scope, $group_spec), false)[0];
        [, , $individual_intensity, $individual_divisor] = iterator_to_array($target->expand($scope, $individual_spec), false)[0];

        $this->assertSame($individual_intensity, $group_intensity);
        $this->assertSame($individual_divisor, $group_divisor);
    }

    // --- End-to-end: damage parity through apply()/affected_by() ---

    public function test_group_wide_melee_effect_deals_the_same_damage_as_a_single_target_hit() {
        // The observable manifestation of the bug: before the fix, a
        // TARGET_GROUP effect against one character dealt roughly half
        // the damage of the identical effect used as TARGET_INDIVIDUAL,
        // purely from the intensity/divisor pair -- not from any stat
        // or roll difference. Averaged over many trials since melee
        // damage has a random roll component; a real ~2x gap would
        // separate cleanly even with that noise.
        $trials = 500;
        $individual_total = 0;
        $group_total = 0;

        foreach ([EffectSpec::TARGET_INDIVIDUAL, EffectSpec::TARGET_GROUP] as $mode) {
            $total = 0;
            for ($i = 0; $i < $trials; $i++) {
                $attacker = $this->fresh_character('Attacker');
                $attacker->current['Accuracy'] = 1000; // guaranteed hit
                $attacker->current['Strength'] = 50;
                $defender = $this->fresh_character('Defender');

                $attacker_party = new PARTY();
                $attacker_group = new GROUP();
                $attacker_group->add_hero($attacker);
                $attacker_party->add($attacker_group);
                $defender_party = new PARTY();
                $defender_group = new GROUP();
                $defender_group->add_hero($defender);
                $defender_party->add($defender_group);
                $scope = $this->scope([0 => $attacker_party, 1 => $defender_party]);

                $target = new ActionTarget(1, 0, 0);
                $spec = new EffectSpec(Effect::HURT, $mode, 0, 0, 0);
                $action_list = [];
                $target->apply($scope, $attacker, $spec, $action_list);
                $total += (100 - $defender->current['HP']);
            }
            if ($mode === EffectSpec::TARGET_INDIVIDUAL) $individual_total = $total;
            else $group_total = $total;
        }

        $individual_avg = $individual_total / $trials;
        $group_avg = $group_total / $trials;
        // Same stats and same roll distribution either way -- allow
        // generous statistical slack, but a pre-fix ~50% gap would
        // fail this comfortably.
        $this->assertGreaterThan($individual_avg * 0.8, $group_avg);
        $this->assertLessThan($individual_avg * 1.2, $group_avg);
    }

    // --- expand_ranged() boundary logic (via expand() with a positive range) ---

    private function group_of(int $count): array {
        // Returns [GROUP, PARTY, scope] with $count fresh, alive members.
        $group = new GROUP();
        for ($i = 0; $i < $count; $i++) $group->add_hero($this->fresh_character("M$i"));
        $party = new PARTY();
        $party->add($group);
        return [$group, $party, $this->scope([0 => $party])];
    }

    public function test_expand_ranged_center_only_when_range_is_zero() {
        [, , $scope] = $this->group_of(5);
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
        $target = new ActionTarget(0, 0, 2);

        $results = iterator_to_array($target->expand($scope, $spec), false);
        $indices = array_map(fn($r) => $r[0]->char_index, $results);

        $this->assertSame([2], $indices);
    }

    public function test_expand_ranged_covers_both_sides_within_bounds() {
        [, , $scope] = $this->group_of(5);
        $spec = new EffectSpec(Effect::HURT, 1, 0, 0, 0); // range=1, center=2 -> 1,2,3
        $target = new ActionTarget(0, 0, 2);

        $results = iterator_to_array($target->expand($scope, $spec), false);
        $indices = array_map(fn($r) => $r[0]->char_index, $results);
        sort($indices);

        $this->assertSame([1, 2, 3], $indices);
    }

    public function test_expand_ranged_clamps_at_the_left_edge() {
        // range=2, center=0 -- the left side has nowhere to go, so
        // only the center and the right side are covered: 0,1,2.
        [, , $scope] = $this->group_of(5);
        $spec = new EffectSpec(Effect::HURT, 2, 0, 0, 0);
        $target = new ActionTarget(0, 0, 0);

        $results = iterator_to_array($target->expand($scope, $spec), false);
        $indices = array_map(fn($r) => $r[0]->char_index, $results);
        sort($indices);

        $this->assertSame([0, 1, 2], $indices);
    }

    public function test_expand_ranged_clamps_at_the_right_edge() {
        // range=2, center=4 (last index in a 5-member group) -- the
        // right side has nowhere to go: only 2,3,4 are covered.
        [, , $scope] = $this->group_of(5);
        $spec = new EffectSpec(Effect::HURT, 2, 0, 0, 0);
        $target = new ActionTarget(0, 0, 4);

        $results = iterator_to_array($target->expand($scope, $spec), false);
        $indices = array_map(fn($r) => $r[0]->char_index, $results);
        sort($indices);

        $this->assertSame([2, 3, 4], $indices);
    }

    public function test_expand_ranged_can_cover_the_whole_group_when_range_is_large_enough() {
        [, , $scope] = $this->group_of(5);
        $spec = new EffectSpec(Effect::HURT, 10, 0, 0, 0); // range far exceeds group size
        $target = new ActionTarget(0, 0, 2);

        $results = iterator_to_array($target->expand($scope, $spec), false);
        $indices = array_map(fn($r) => $r[0]->char_index, $results);
        sort($indices);

        $this->assertSame([0, 1, 2, 3, 4], $indices);
    }

    public function test_expand_ranged_offset_and_range_reported_correctly_per_recipient() {
        // Confirms the actual (offset, range) pair yielded, not just
        // which characters get reached -- the center should report
        // offset=0, and each side should report its true distance.
        [, , $scope] = $this->group_of(5);
        $spec = new EffectSpec(Effect::HURT, 2, 0, 0, 0);
        $target = new ActionTarget(0, 0, 2);

        $results = iterator_to_array($target->expand($scope, $spec), false);
        $by_index = [];
        foreach ($results as [$rt, , $intensity, $divisor]) {
            $by_index[$rt->char_index] = [$intensity, $divisor];
        }
        ksort($by_index);

        $this->assertSame([
            0 => [2, 2],
            1 => [1, 2],
            2 => [0, 2],
            3 => [1, 2],
            4 => [2, 2],
        ], $by_index);
    }

    public function test_expand_ranged_skips_dead_targets_within_range() {
        [$group, , $scope] = $this->group_of(5);
        $group->characters[1]->current['HP'] = 0; // kill index 1

        $spec = new EffectSpec(Effect::HURT, 1, 0, 0, 0); // range=1, center=2 -> would cover 1,2,3
        $target = new ActionTarget(0, 0, 2);

        $results = iterator_to_array($target->expand($scope, $spec), false);
        $indices = array_map(fn($r) => $r[0]->char_index, $results);
        sort($indices);

        $this->assertSame([2, 3], $indices); // 1 excluded, dead
    }

    // --- to_key() / to_array() / from_key() ---

    public function test_to_key_includes_zero_values() {
        // The filter is $x !== null, not a truthy check -- zero party/
        // group/char indices (all common, valid values) must survive.
        $target = new ActionTarget(0, 0, 0);

        $this->assertSame('0;0;0', $target->to_key());
    }

    public function test_to_key_omits_trailing_null_parts() {
        $party_only = new ActionTarget(3);
        $party_and_group = new ActionTarget(3, 2);
        $full = new ActionTarget(3, 2, 1);

        $this->assertSame('3', $party_only->to_key());
        $this->assertSame('3;2', $party_and_group->to_key());
        $this->assertSame('3;2;1', $full->to_key());
    }

    public function test_to_array_returns_the_three_indices_in_order() {
        $target = new ActionTarget(1, 2, 3);

        $this->assertSame([1, 2, 3], $target->to_array());
    }

    public function test_from_key_round_trips_a_full_key() {
        $restored = ActionTarget::from_key('1;2;3');

        $this->assertSame(1, $restored->party_index);
        $this->assertSame(2, $restored->group_index);
        $this->assertSame(3, $restored->char_index);
    }

    public function test_from_key_pads_a_partial_key_with_null() {
        $restored = ActionTarget::from_key('1;2');

        $this->assertSame(1, $restored->party_index);
        $this->assertSame(2, $restored->group_index);
        $this->assertNull($restored->char_index);
    }

    // --- get_party() / get_group() / get_character() ---

    public function test_get_party_returns_the_party_at_the_index() {
        [, $party, $scope] = $this->group_of(1);
        $target = new ActionTarget(0);

        $this->assertSame($party, $target->get_party($scope));
    }

    public function test_get_party_throws_when_missing() {
        [, , $scope] = $this->group_of(1);
        $target = new ActionTarget(99);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('There was no party with index 99.');
        $target->get_party($scope);
    }

    public function test_get_group_returns_the_group_at_the_index() {
        [$group, , $scope] = $this->group_of(1);
        $target = new ActionTarget(0, 0);

        $this->assertSame($group, $target->get_group($scope));
    }

    public function test_get_group_throws_when_missing() {
        [, , $scope] = $this->group_of(1);
        $target = new ActionTarget(0, 99);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('There was no group with index 99 in party 0.');
        $target->get_group($scope);
    }

    public function test_get_character_returns_the_character_at_the_index() {
        [$group, , $scope] = $this->group_of(1);
        $target = new ActionTarget(0, 0, 0);

        $this->assertSame($group->characters[0], $target->get_character($scope));
    }

    public function test_get_character_throws_when_missing() {
        [, , $scope] = $this->group_of(1);
        $target = new ActionTarget(0, 0, 99);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('There was no character with index 99 in party 0, group 0.');
        $target->get_character($scope);
    }

    public function test_get_character_propagates_get_groups_exception_when_the_group_is_missing() {
        // Not a misleading "no character" message -- the error
        // correctly identifies which level is actually missing.
        [, , $scope] = $this->group_of(1);
        $target = new ActionTarget(0, 99, 0);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('There was no group with index 99 in party 0.');
        $target->get_character($scope);
    }

    // --- apply() ---

    public function test_apply_reaches_multiple_recipients() {
        // A group-wide effect against a two-member group should apply
        // affected_by() to both members, not just the first.
        $attacker = $this->fresh_character('Attacker');
        $attacker->current['Accuracy'] = 1000;
        $attacker->current['Strength'] = 50;
        $attacker_party = new PARTY();
        $attacker_group = new GROUP();
        $attacker_group->add_hero($attacker);
        $attacker_party->add($attacker_group);

        $defender1 = $this->fresh_character('Defender1');
        $defender2 = $this->fresh_character('Defender2');
        $defender_group = new GROUP();
        $defender_group->add_hero($defender1);
        $defender_group->add_hero($defender2);
        $defender_party = new PARTY();
        $defender_party->add($defender_group);

        $scope = $this->scope([0 => $attacker_party, 1 => $defender_party]);
        $target = new ActionTarget(1, 0, 0);
        $spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_GROUP, 0, 0, 0);
        $action_list = [];

        $target->apply($scope, $attacker, $spec, $action_list);

        $this->assertLessThan(100, $defender1->current['HP']);
        $this->assertLessThan(100, $defender2->current['HP']);
    }
}
