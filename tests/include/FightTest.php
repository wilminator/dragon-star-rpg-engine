<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'party.php'; // not in bootstrap.php's require chain
require_once INCLUDE_GAME . 'fight.php'; // not in bootstrap.php's require chain

/**
 * Characterization tests for FIGHT -- combat orchestration: turn
 * order, running a fight to completion, and post-fight XP
 * distribution. FIGHT never touches the database directly, so these
 * are pure unit tests throughout, same as GroupTest.php.
 *
 * Return-value convention across this codebase's add/remove/move
 * methods (add_hero(), remove_fighter(), add_team(), remove_team(),
 * move_character(), and FIGHT::add() here): true means an error
 * occurred, false means success -- the opposite of what's
 * conventional in most codebases, but deliberate and applied
 * consistently throughout, confirmed with the author. Characterized
 * as-is, not treated as a bug.
 */
class FightTest extends TestCase {
    use AugmentsFixtureCache;

    private function fresh_character(string $name, int $hp = 100): CHARACTER {
        $character = new CHARACTER();
        $character->name = $name;
        $character->current['HP'] = $hp;
        $character->base['HP'] = $hp;
        return $character;
    }

    private function party_of(string $name): PARTY {
        $group = new GROUP();
        $group->add_hero($this->fresh_character($name));
        $party = new PARTY();
        $party->add($group);
        return $party;
    }

    // --- __construct() ---

    public function test_construct_initializes_empty_state() {
        $fight = new FIGHT('bg.png', 'theme.mp3');

        $this->assertSame([], $fight->parties);
        $this->assertSame(0, $fight->counter);
        $this->assertNull($fight->id); // null = never saved, per DbStoreTrait's convention
        $this->assertSame('bg.png', $fight->background);
        $this->assertSame('theme.mp3', $fight->music);
    }

    // --- add() ---

    public function test_add_a_party_succeeds() {
        $fight = new FIGHT('bg', 'music');
        $party = $this->party_of('A');

        $result = $fight->add($party);

        $this->assertFalse($result); // false = success, per this codebase's convention
        $this->assertSame([$party], $fight->parties);
    }

    public function test_add_rejects_a_non_party_argument() {
        // add() now type-hints PARTY $party directly, so an invalid
        // argument is a TypeError at the call boundary, not the old
        // graceful `return true` -- a real change in failure mode,
        // not just added typing. Neither of the two real callers
        // (init_fight.php) is affected, since both always pass actual
        // PARTY objects.
        $fight = new FIGHT('bg', 'music');

        $this->expectException(\TypeError::class);
        $fight->add('not a party');
    }

    public function test_add_names_an_unnamed_party_after_its_first_character() {
        $fight = new FIGHT('bg', 'music');
        $party = $this->party_of('Leader');
        $this->assertSame('', $party->name);

        $fight->add($party);

        $this->assertSame("Leader's party", $party->name);
    }

    public function test_add_does_not_rename_an_already_named_party() {
        $fight = new FIGHT('bg', 'music');
        $party = $this->party_of('Leader');
        $party->name = 'The Vanguard';

        $fight->add($party);

        $this->assertSame('The Vanguard', $party->name);
    }

    public function test_add_clears_each_characters_xp_debts_and_snapshots_old_exp() {
        $fight = new FIGHT('bg', 'music');
        $party = $this->party_of('A');
        $character = $party->groups[0]->characters[0];
        $character->xp_debts = [[[1, 0, 0], 0.5, 10]]; // pre-existing debt from a prior fight
        $character->exp = 250;

        $fight->add($party);

        $this->assertSame([], $character->xp_debts);
        $this->assertSame(250, $character->old_exp);
    }

    public function test_add_appends_using_the_next_key_even_after_a_gap() {
        // Regression guard: add() used to compute the next key via
        // count($this->parties), which collides with an existing key
        // once a party has been removed via unset() (as
        // process_exp_tree() does), silently overwriting it instead
        // of actually adding the new party. Switched to the array
        // append operator, which always uses one past the highest
        // existing key.
        $fight = new FIGHT('bg', 'music');
        $partyA = $this->party_of('A');
        $partyB = $this->party_of('B');
        $partyC = $this->party_of('C');
        $fight->add($partyA);
        $fight->add($partyB);
        $fight->add($partyC);
        unset($fight->parties[1]); // simulate process_exp_tree()'s removal, leaving a gap

        $partyD = $this->party_of('D');
        $fight->add($partyD);

        $this->assertSame($partyA, $fight->parties[0]);
        $this->assertSame($partyC, $fight->parties[2]); // untouched by the new add
        $this->assertSame($partyD, $fight->parties[3]); // new key, not an overwrite
    }

    // --- set_npc_action() ---
    // Consolidated from two previously-duplicated inline copies (in
    // init_fight.php's admin-only "play as the enemy party" mode, and
    // functions.php's process_fight(), which drives every normal
    // turn's NPC actions) -- both now call this instead of repeating
    // the "ask ai(), unpack the result, apply it" mechanics inline.
    // Which characters get selected for it is still each caller's own
    // decision; this method only covers applying the result to one.
    //
    // The AI decision engine itself (ai.php/ai_goal.php/ai_action.php)
    // is a large, separate subsystem -- these tests verify
    // set_npc_action()'s own mechanical wiring (character state
    // actually changes), not which specific decision the AI makes.

    private function fresh_npc_character(string $name, int $hp = 100, int $ai_action = 0): CHARACTER {
        $character = $this->fresh_character($name, $hp);
        $character->current['Accuracy'] = 50;
        $character->current['Strength'] = 20;
        $character->ai_action = $ai_action;
        return $character;
    }

    public function test_set_npc_action_updates_the_characters_command_using_and_target() {
        $fight = new FIGHT('bg', 'music');
        $npc_group = new GROUP();
        $npc_group->add_hero($this->fresh_npc_character('NPC'));
        $npc_party = new PARTY();
        $npc_party->add($npc_group);
        $fight->add($npc_party);

        $enemy_group = new GROUP();
        $enemy_group->add_hero($this->fresh_npc_character('Enemy'));
        $enemy_party = new PARTY();
        $enemy_party->add($enemy_group);
        $fight->add($enemy_party);
        $npc_party->enemies[] = 1; // properly aligned this time

        $npc = $fight->parties[0]->groups[0]->characters[0];

        $fight->set_npc_action(0, 0, 0);

        $this->assertIsInt($npc->command);
        $this->assertIsInt($npc->using);
        $this->assertIsArray($npc->target);
        $this->assertCount(3, $npc->target);
    }

    public function test_set_npc_action_does_not_throw_when_the_target_party_is_unaligned() {
        // Regression guard: ai_get_alignment() legitimately returns
        // null when a party is registered as neither ally nor enemy
        // of the acting party -- all three states (true/false/null)
        // are valid. ai_goal() and its five ai_goal_*() sub-functions
        // previously type-hinted this as a non-nullable bool, so
        // evaluating any unaligned target threw a TypeError. Fixed by
        // widening all six signatures to ?bool. This scenario
        // deliberately leaves both parties' allies/enemies empty.
        $fight = new FIGHT('bg', 'music');
        $npc_group = new GROUP();
        $npc_group->add_hero($this->fresh_npc_character('NPC'));
        $npc_party = new PARTY();
        $npc_party->add($npc_group);
        $fight->add($npc_party);

        $enemy_group = new GROUP();
        $enemy_group->add_hero($this->fresh_npc_character('Enemy'));
        $enemy_party = new PARTY();
        $enemy_party->add($enemy_group);
        $fight->add($enemy_party);
        // Neither party's allies/enemies populated -- deliberately unaligned.

        $npc = $fight->parties[0]->groups[0]->characters[0];

        $fight->set_npc_action(0, 0, 0);

        // No exception reaching this line is the point of the test.
        $this->assertIsInt($npc->command);
    }

    // --- count_live_parties() ---

    private function party_with_one_character(int $hp): PARTY {
        $group = new GROUP();
        $group->add_hero($this->fresh_character('X', $hp));
        $party = new PARTY();
        $party->add($group);
        return $party;
    }

    public function test_count_live_parties_with_no_parties() {
        $fight = new FIGHT('bg', 'music');

        $this->assertSame(0, $fight->count_live_parties());
    }

    public function test_count_live_parties_all_alive() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(50));
        $fight->add($this->party_with_one_character(50));
        $fight->add($this->party_with_one_character(50));

        $this->assertSame(3, $fight->count_live_parties());
    }

    public function test_count_live_parties_excludes_dead_ones() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(0)); // dead
        $fight->add($this->party_with_one_character(50));
        $fight->add($this->party_with_one_character(50));

        $this->assertSame(2, $fight->count_live_parties());
    }

    public function test_count_live_parties_all_dead() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(0));
        $fight->add($this->party_with_one_character(0));

        $this->assertSame(0, $fight->count_live_parties());
    }

    // --- generate_participant_list() ---

    private function speed_character(string $name, int $speed, int $hp = 100, int $command = 0): CHARACTER {
        $character = $this->fresh_character($name, $hp);
        $character->current['Speed'] = $speed;
        $character->command = $command;
        return $character;
    }

    public function test_generate_participant_list_returns_action_targets() {
        $fight = new FIGHT('bg', 'music');
        $group = new GROUP();
        $group->add_hero($this->speed_character('A', 50));
        $party = new PARTY();
        $party->add($group);
        $fight->add($party);

        $participants = $fight->generate_participant_list();

        $this->assertCount(1, $participants);
        $this->assertInstanceOf(ActionTarget::class, $participants[0]);
        $this->assertSame(0, $participants[0]->party_index);
        $this->assertSame(0, $participants[0]->group_index);
        $this->assertSame(0, $participants[0]->char_index);
    }

    public function test_generate_participant_list_orders_faster_characters_first() {
        // Speeds far enough apart that randomize()'s +/-15% deviation
        // can never flip the order.
        $fight = new FIGHT('bg', 'music');
        $group = new GROUP();
        $group->add_hero($this->speed_character('Slow', 10));
        $group->add_hero($this->speed_character('Fast', 100));
        $party = new PARTY();
        $party->add($group);
        $fight->add($party);

        $participants = $fight->generate_participant_list();
        $names = array_map(
            fn($p) => $fight->parties[$p->party_index]->groups[$p->group_index]->characters[$p->char_index]->name,
            $participants
        );

        $this->assertSame(['Fast', 'Slow'], $names);
    }

    public function test_generate_participant_list_excludes_dead_characters() {
        $fight = new FIGHT('bg', 'music');
        $group = new GROUP();
        $group->add_hero($this->speed_character('Dead', 50, hp: 0));
        $group->add_hero($this->speed_character('Alive', 50));
        $party = new PARTY();
        $party->add($group);
        $fight->add($party);

        $participants = $fight->generate_participant_list();

        $this->assertCount(1, $participants);
    }

    public function test_generate_participant_list_excludes_defending_characters() {
        $fight = new FIGHT('bg', 'music');
        $group = new GROUP();
        $group->add_hero($this->speed_character('Defending', 50, command: 6));
        $group->add_hero($this->speed_character('Attacking', 50, command: 0));
        $party = new PARTY();
        $party->add($group);
        $fight->add($party);

        $participants = $fight->generate_participant_list();

        $this->assertCount(1, $participants);
    }

    public function test_generate_participant_list_repeats_a_character_per_attack_count() {
        $bow_row = build_item_row(710, 'Rapid Bow', ['hand'], attack_count: 3);

        $count = $this->with_extra_cache(function () {
            $fight = new FIGHT('bg', 'music');
            $group = new GROUP();
            $character = $this->speed_character('MultiAttacker', 50);
            $character->inventory = [0 => ['item' => 710, 'qty' => 1]];
            $character->equipment['lhand'] = 0;
            $group->add_hero($character);
            $party = new PARTY();
            $party->add($group);
            $fight->add($party);

            return count($fight->generate_participant_list());
        }, items: [710 => $bow_row]);

        $this->assertSame(3, $count);
    }

    public function test_generate_participant_list_casting_penalty_can_push_a_caster_behind_an_equal_speed_attacker() {
        // Current formula: penalty fraction = 1/(sqrt(focus)/(mp_used+1) + 2).
        // focus=0 gives exactly 50% (the max) regardless of mp_used,
        // since sqrt(0)=0 -- chosen here so the penalty reliably beats
        // randomize()'s own +/-15% noise on an equal base speed;
        // confirmed empirically across repeated runs before writing
        // this as a fixed assertion, since a smaller, more "realistic"
        // focus value wouldn't reliably survive the randomization.
        $spell_row = build_ability_row(900, 'Costly Spell', 0, type: 0, mp_used: 100);

        $first = $this->with_extra_cache(function () {
            $fight = new FIGHT('bg', 'music');
            $group = new GROUP();

            $caster = $this->speed_character('Caster', 100, command: 4);
            $caster->using = 0;
            $caster->abilities = [900];
            $caster->current['Focus'] = 0;
            $group->add_hero($caster);

            $melee = $this->speed_character('Melee', 100, command: 0);
            $group->add_hero($melee);

            $party = new PARTY();
            $party->add($group);
            $fight->add($party);

            $participants = $fight->generate_participant_list();
            return $fight->parties[$participants[0]->party_index]->groups[$participants[0]->group_index]->characters[$participants[0]->char_index]->name;
        }, abilities: [900 => $spell_row]);

        $this->assertSame('Melee', $first);
    }

    public function test_generate_participant_list_zero_mp_abilities_still_carry_a_real_penalty() {
        // The +1 on mp_used exists specifically so mp_used=0 doesn't
        // become instant-cast: with focus=0 (sqrt(0)=0), the penalty
        // is still the full 50% regardless of how cheap the ability
        // is. This is an ordering assertion, not just a no-crash
        // check -- confirmed empirically across repeated runs before
        // writing this as a fixed assertion.
        $free_row = build_ability_row(901, 'Free Spell', 0, type: 0, mp_used: 0);

        $first = $this->with_extra_cache(function () {
            $fight = new FIGHT('bg', 'music');
            $group = new GROUP();

            $caster = $this->speed_character('Caster', 100, command: 4);
            $caster->using = 0;
            $caster->abilities = [901];
            $caster->current['Focus'] = 0;
            $group->add_hero($caster);

            $melee = $this->speed_character('Melee', 100, command: 0);
            $group->add_hero($melee);

            $party = new PARTY();
            $party->add($group);
            $fight->add($party);

            $participants = $fight->generate_participant_list();
            return $fight->parties[$participants[0]->party_index]->groups[$participants[0]->group_index]->characters[$participants[0]->char_index]->name;
        }, abilities: [901 => $free_row]);

        $this->assertSame('Melee', $first);
    }

    public function test_generate_participant_list_a_cheap_spell_gets_more_benefit_from_focus_than_an_expensive_one() {
        // Design intent: spell costs don't scale linearly, but Focus
        // gain per level does -- so the same high focus should ease a
        // cheap spell's casting time far more than an expensive one's,
        // giving an experienced caster a real (if mild, bounded by the
        // 0%-50% range) edge specifically when using low-MP abilities.
        // Confirmed empirically across repeated runs.
        $cheap_row = build_ability_row(902, 'Cheap Spell', 0, type: 0, mp_used: 1);
        $costly_row = build_ability_row(903, 'Costly Spell', 0, type: 0, mp_used: 1000);

        $first = $this->with_extra_cache(function () {
            $fight = new FIGHT('bg', 'music');
            $group = new GROUP();

            $cheap_caster = $this->speed_character('CheapCaster', 100, command: 4);
            $cheap_caster->using = 0;
            $cheap_caster->abilities = [902];
            $cheap_caster->current['Focus'] = 1000; // same high focus for both

            $costly_caster = $this->speed_character('CostlyCaster', 100, command: 4);
            $costly_caster->using = 0;
            $costly_caster->abilities = [903];
            $costly_caster->current['Focus'] = 1000;

            $group->add_hero($cheap_caster);
            $group->add_hero($costly_caster);

            $party = new PARTY();
            $party->add($group);
            $fight->add($party);

            $participants = $fight->generate_participant_list();
            return $fight->parties[$participants[0]->party_index]->groups[$participants[0]->group_index]->characters[$participants[0]->char_index]->name;
        }, abilities: [902 => $cheap_row, 903 => $costly_row]);

        $this->assertSame('CheapCaster', $first);
    }

    // --- do_combat() ---
    // All of its direct dependencies (generate_participant_list(),
    // count_live_parties(), ActionTarget::from_key()/get_character(),
    // CHARACTER::perform_action()) are independently covered
    // elsewhere, so these focus on do_combat()'s own orchestration
    // logic: the loop's two stopping conditions, and that a
    // participant who dies mid-combat, before their queued turn comes
    // up, is silently skipped rather than acted on or crashing.

    private function attacker_character(string $name, array $target, int $speed = 50): CHARACTER {
        $character = $this->fresh_character($name, 100);
        $character->current['Accuracy'] = 1000;
        $character->current['Strength'] = 50;
        $character->current['Speed'] = $speed;
        $character->current['Dodge'] = 1;
        $character->current['Block'] = 1;
        $character->command = 0;
        $character->target = $target;
        return $character;
    }

    public function test_do_combat_runs_a_basic_two_party_fight() {
        $fight = new FIGHT('bg', 'music');
        $a = $this->attacker_character('A', [1, 0, 0]);
        $group_a = new GROUP();
        $group_a->add_hero($a);
        $party_a = new PARTY();
        $party_a->add($group_a);
        $fight->add($party_a);

        $b = $this->attacker_character('B', [0, 0, 0]);
        $group_b = new GROUP();
        $group_b->add_hero($b);
        $party_b = new PARTY();
        $party_b->add($group_b);
        $fight->add($party_b);

        $action_list = $fight->do_combat();

        $this->assertGreaterThan(0, count($action_list));
        $this->assertLessThan(100, $a->current['HP']);
        $this->assertLessThan(100, $b->current['HP']);
    }

    public function test_do_combat_does_nothing_with_only_one_party() {
        $fight = new FIGHT('bg', 'music');
        $a = $this->attacker_character('A', [0, 0, 0]);
        $group = new GROUP();
        $group->add_hero($a);
        $party = new PARTY();
        $party->add($group);
        $fight->add($party);

        $action_list = $fight->do_combat();

        $this->assertSame([], $action_list);
    }

    public function test_do_combat_stops_as_soon_as_only_one_party_remains_alive() {
        // A one-HP attacker facing an overwhelmingly durable opponent:
        // the weak side should be wiped out almost immediately, and
        // combat should stop right there rather than continuing to
        // grind through an enormous HP pool.
        $fight = new FIGHT('bg', 'music');
        $weak = $this->attacker_character('Weak', [1, 0, 0], speed: 10);
        $weak->current['HP'] = 1;
        $weak->base['HP'] = 1;
        $group_weak = new GROUP();
        $group_weak->add_hero($weak);
        $party_weak = new PARTY();
        $party_weak->add($group_weak);
        $fight->add($party_weak);

        $strong = $this->attacker_character('Strong', [0, 0, 0], speed: 10000);
        $strong->current['HP'] = 1000000;
        $strong->base['HP'] = 1000000;
        $group_strong = new GROUP();
        $group_strong->add_hero($strong);
        $party_strong = new PARTY();
        $party_strong->add($group_strong);
        $fight->add($party_strong);

        $fight->do_combat();

        $this->assertSame(1, $fight->count_live_parties());
    }

    public function test_do_combat_skips_a_participant_who_died_before_their_queued_turn() {
        // The attacker (much higher speed, guaranteed to go first)
        // kills Defender1 outright. Defender1's own already-queued
        // turn should then be silently skipped -- no action_list
        // entries from it -- rather than acting on a dead character or
        // crashing. Defender2 survives and still gets to act.
        $fight = new FIGHT('bg', 'music');

        $defender1 = $this->fresh_character('Defender1', 1);
        $defender1->command = 6; // defend
        $defender2 = $this->fresh_character('Defender2', 1);
        $defender2->command = 6;
        $defender_group = new GROUP();
        $defender_group->add_hero($defender1);
        $defender_group->add_hero($defender2);
        $defender_party = new PARTY();
        $defender_party->add($defender_group);
        $fight->add($defender_party);

        $attacker = $this->attacker_character('Attacker', [0, 0, 0], speed: 10000);
        $attacker->current['HP'] = 1000;
        $attacker->base['HP'] = 1000;
        $attacker_group = new GROUP();
        $attacker_group->add_hero($attacker);
        $attacker_party = new PARTY();
        $attacker_party->add($attacker_group);
        $fight->add($attacker_party);

        $action_list = $fight->do_combat();

        $this->assertSame(0, $defender1->current['HP']);
        // Attacker's melee turn (Turn/Target/Damage/XpDebt, 4) +
        // Defender2's Defend turn (Turn/Defend, 2) = 6. Defender1's own
        // queued turn contributes nothing, since it's skipped before
        // perform_action() is ever called.
        $this->assertCount(6, $action_list);
    }

    // --- test_own_party_dead() ---

    public function test_own_party_dead_returns_true_for_a_dead_party() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(0));
        $fight->add($this->party_with_one_character(50));

        $this->assertTrue($fight->test_own_party_dead(0));
    }

    public function test_own_party_dead_returns_false_for_a_live_party() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(0));
        $fight->add($this->party_with_one_character(50));

        $this->assertFalse($fight->test_own_party_dead(1));
    }

    public function test_own_party_dead_throws_on_an_out_of_range_index() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(50));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('There was no party with index 99.');
        $fight->test_own_party_dead(99);
    }

    // --- test_other_parties_dead() ---

    public function test_other_parties_dead_true_when_every_other_party_is_dead() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(50)); // index 0
        $fight->add($this->party_with_one_character(0));  // index 1, dead
        $fight->add($this->party_with_one_character(0));  // index 2, dead

        $this->assertTrue($fight->test_other_parties_dead(0));
    }

    public function test_other_parties_dead_false_when_one_other_party_is_still_alive() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(50)); // index 0
        $fight->add($this->party_with_one_character(0));  // index 1, dead
        $fight->add($this->party_with_one_character(50)); // index 2, alive

        $this->assertFalse($fight->test_other_parties_dead(0));
    }

    public function test_other_parties_dead_ignores_the_given_partys_own_status() {
        // The given party is alive here, but that's irrelevant --
        // only the *other* parties' status is checked.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(50)); // index 0, alive, but excluded from the check
        $fight->add($this->party_with_one_character(0));  // index 1, dead

        $this->assertTrue($fight->test_other_parties_dead(0));
    }

    public function test_other_parties_dead_true_when_it_is_the_only_party() {
        // Vacuously true -- the loop never finds another party to
        // disagree with.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(50));

        $this->assertTrue($fight->test_other_parties_dead(0));
    }

    public function test_other_parties_dead_throws_on_an_out_of_range_index() {
        // Now consistent with test_own_party_dead() -- previously
        // this never indexed $this->parties[$party] directly (only
        // compared indices in a loop), so an out-of-range $party
        // silently treated every real party as "other" instead of
        // throwing. An explicit array_key_exists() check now makes
        // both methods fail the same way.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(50));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('There was no party with index 99.');
        $fight->test_other_parties_dead(99);
    }

    // --- distribute_party_xp() ---
    // Lower-level building block: converts one exiting party's
    // characters' recorded xp_debts into $exp_credits entries for
    // whoever earned them. process_exp_tree() calls this once per
    // exiting party; these tests call it directly to isolate its own
    // logic from the surrounding orchestration.

    private function party_of_one(CHARACTER $character): PARTY {
        $group = new GROUP();
        $group->add_hero($character);
        $party = new PARTY();
        $party->add($group);
        return $party;
    }

    /** @return array{0: array, 1: array} [$exp_debts, $exp_credits], built the same way process_exp_tree()'s own step one does. */
    private function fresh_debts_and_credits(FIGHT $fight): array {
        $exp_debts = [];
        foreach (array_keys($fight->parties) as $party)
            foreach (array_keys($fight->parties[$party]->groups) as $group)
                foreach (array_keys($fight->parties[$party]->groups[$group]->characters) as $character) {
                    $exp_debts[$party][$group][$character] = [0, 1];
                    foreach ($fight->parties[$party]->groups[$group]->characters[$character]->xp_debts as $debt)
                        $exp_debts[$party][$group][$character][0] += abs($debt[1]);
                    if ($exp_debts[$party][$group][$character][0] > 1)
                        $exp_debts[$party][$group][$character][1] = 1.0 / $exp_debts[$party][$group][$character][0];
                }
        $exp_credits = [];
        foreach (array_keys($fight->parties) as $party) {
            $exp_credits[$party]['total'] = 0;
            $exp_credits[$party]['count'] = 0;
            foreach (array_keys($fight->parties[$party]->groups) as $group)
                foreach (array_keys($fight->parties[$party]->groups[$group]->characters) as $character) {
                    $exp_credits[$party][$group][$character] = 0;
                    $exp_credits[$party]['count']++;
                }
        }
        return [$exp_debts, $exp_credits];
    }

    public function test_distribute_party_xp_credits_the_attacker_for_damage() {
        // xp_debts live on the VICTIM, keyed by the ATTACKER's
        // position (add_debt_to_xp_tree() records $this->xp_debts[]
        // on the recipient of the effect). Damage is a positive
        // debt[1].
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_of_one($this->fresh_character('Attacker', 100)));
        $fight->add($this->party_of_one($this->fresh_character('Victim', 100)));
        $fight->parties[1]->groups[0]->characters[0]->xp_debts[] = [[0, 0, 0], 0.5, 100];

        [$exp_debts, $exp_credits] = $this->fresh_debts_and_credits($fight);
        $fight->distribute_party_xp(1, [], $exp_debts, $exp_credits);

        $this->assertSame(50.0, $exp_credits[0]['total']);
        $this->assertSame(50.0, $exp_credits[0][0][0]);
    }

    public function test_distribute_party_xp_credits_the_healer_for_healing() {
        // Healing is a negative debt[1]; abs() handles the sign, and
        // the polarity check requires the target be a non-enemy
        // rather than a non-ally.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_of_one($this->fresh_character('Healer', 100)));
        $fight->add($this->party_of_one($this->fresh_character('Patient', 100)));
        $fight->parties[1]->groups[0]->characters[0]->xp_debts[] = [[0, 0, 0], -0.3, 100];

        [$exp_debts, $exp_credits] = $this->fresh_debts_and_credits($fight);
        $fight->distribute_party_xp(1, [], $exp_debts, $exp_credits);

        $this->assertSame(30.0, $exp_credits[0]['total']);
    }

    public function test_distribute_party_xp_ignores_debts_owed_to_an_already_exited_party() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_of_one($this->fresh_character('A', 100)));
        $fight->add($this->party_of_one($this->fresh_character('B', 100)));
        $fight->parties[1]->groups[0]->characters[0]->xp_debts[] = [[0, 0, 0], 0.5, 100];

        [$exp_debts, $exp_credits] = $this->fresh_debts_and_credits($fight);
        $fight->distribute_party_xp(1, [0], $exp_debts, $exp_credits); // party 0 already exited

        $this->assertSame(0, $exp_credits[0]['total']);
    }

    public function test_distribute_party_xp_scales_down_credit_when_total_debt_exceeds_100_percent() {
        // Two debts from the same attacker totaling 150% of the
        // victim's max HP (e.g. overkill healing across multiple
        // actions) -- the awarded credit should still total no more
        // than the victim's recorded PXP (100), not 150.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_of_one($this->fresh_character('Attacker', 100)));
        $fight->add($this->party_of_one($this->fresh_character('Victim', 100)));
        $fight->parties[1]->groups[0]->characters[0]->xp_debts[] = [[0, 0, 0], 0.8, 100];
        $fight->parties[1]->groups[0]->characters[0]->xp_debts[] = [[0, 0, 0], 0.7, 100];

        [$exp_debts, $exp_credits] = $this->fresh_debts_and_credits($fight);
        $fight->distribute_party_xp(1, [], $exp_debts, $exp_credits);

        $this->assertSame(100.0, $exp_credits[0]['total']);
    }

    // --- process_exp_tree() ---
    // Full orchestration: builds the debt/credit trees, settles XP
    // for dead (and, if all survivors are mutually allied, all
    // remaining) parties, awards it via the two-pool split, and
    // discharges dead parties from the fight.

    private function team_character(string $name, int $charid, int $hp = 100): CHARACTER {
        $character = $this->fresh_character($name, $hp);
        $character->charid = $charid;
        return $character;
    }

    public function test_process_exp_tree_awards_xp_to_a_team_member_when_the_enemy_party_dies() {
        $attacker = $this->team_character('Attacker', 1);
        $group = new GROUP();
        $group->add_hero($attacker);
        $attacker_party = new PARTY();
        $attacker_party->add($group);
        $attacker_party->teams = [10 => [1]];

        $victim = $this->fresh_character('Victim', 1);
        $victim_party = $this->party_of_one($victim);

        $fight = new FIGHT('bg', 'music');
        $fight->add($attacker_party);
        $fight->add($victim_party);

        $attacker_ref = $fight->parties[0]->groups[0]->characters[0];
        $victim_ref = $fight->parties[1]->groups[0]->characters[0];
        $victim_ref->xp_debts[] = [[0, 0, 0], 1.0, 100];
        $victim_ref->current['HP'] = 0;

        $fight->process_exp_tree();

        // Sole team member, sole party member: gets the full 100% back
        // (50% own + 20% party pool of 1 + 30% team pool of 1).
        $this->assertSame(100, $attacker_ref->exp);
    }

    public function test_process_exp_tree_gives_npcs_zero_xp_but_still_dilutes_the_party_pool() {
        $teammate = $this->team_character('Teammate', 1);
        $npc = $this->fresh_character('NPC', 100); // charid default, not on the team below
        $npc->charid = 99;
        $group = new GROUP();
        $group->add_hero($teammate);
        $group->add_hero($npc);
        $party = new PARTY();
        $party->add($group);
        $party->teams = [10 => [1]]; // only charid 1 (Teammate) is on a team

        $victim = $this->fresh_character('Victim', 1);
        $victim_party = $this->party_of_one($victim);

        $fight = new FIGHT('bg', 'music');
        $fight->add($party);
        $fight->add($victim_party);

        $teammate_ref = $fight->parties[0]->groups[0]->characters[0];
        $npc_ref = $fight->parties[0]->groups[0]->characters[1];
        $victim_ref = $fight->parties[1]->groups[0]->characters[0];
        $victim_ref->xp_debts[] = [[0, 0, 0], 1.0, 100]; // all credit to Teammate
        $victim_ref->current['HP'] = 0;

        $fight->process_exp_tree();

        // Party pool divisor includes the NPC (2 members), so it's
        // smaller than the sole-member case above: 20% of 100 / 2 = 10,
        // not 20. Own share 50 + party pool 10 + team pool (sole
        // member of their own team) 30 = 90, not the 100 a lone
        // teammate would have earned.
        $this->assertSame(90, $teammate_ref->exp);
        $this->assertSame(0, $npc_ref->exp);
    }

    public function test_process_exp_tree_gives_each_team_its_own_independent_pool() {
        // A stronger team's per-member pool share should reflect only
        // that team's own credit, not the party's combined total --
        // confirmed numerically before writing this as a fixed
        // assertion (hand-computed for these 3 party members: party
        // pool 20% of 100 / 3 = 7 each; team A (90 combined credit,
        // 2 members) pool 30% of 90 / 2 = 14 each; team B (10 credit,
        // 1 member) pool 30% of 10 / 1 = 3).
        $a1 = $this->team_character('A1', 1);
        $a2 = $this->team_character('A2', 2);
        $b1 = $this->team_character('B1', 3);
        $group = new GROUP();
        $group->add_hero($a1);
        $group->add_hero($a2);
        $group->add_hero($b1);
        $party = new PARTY();
        $party->add($group);
        $party->teams = [10 => [1, 2], 20 => [3]];

        $victim = $this->fresh_character('Victim', 1);
        $victim_party = $this->party_of_one($victim);

        $fight = new FIGHT('bg', 'music');
        $fight->add($party);
        $fight->add($victim_party);

        $a1_ref = $fight->parties[0]->groups[0]->characters[0];
        $a2_ref = $fight->parties[0]->groups[0]->characters[1];
        $b1_ref = $fight->parties[0]->groups[0]->characters[2];
        $victim_ref = $fight->parties[1]->groups[0]->characters[0];
        $victim_ref->xp_debts[] = [[0, 0, 0], 0.60, 100];
        $victim_ref->xp_debts[] = [[0, 0, 1], 0.30, 100];
        $victim_ref->xp_debts[] = [[0, 0, 2], 0.10, 100];
        $victim_ref->current['HP'] = 0;

        $fight->process_exp_tree();

        $this->assertSame(51, $a1_ref->exp);
        $this->assertSame(36, $a2_ref->exp);
        $this->assertSame(15, $b1_ref->exp);
    }

    public function test_process_exp_tree_discharges_the_dead_party() {
        $attacker_party = $this->party_with_one_character(100);
        $victim_party = $this->party_with_one_character(0); // already dead

        $fight = new FIGHT('bg', 'music');
        $fight->add($attacker_party);
        $fight->add($victim_party);

        $this->assertCount(2, $fight->parties);

        $fight->process_exp_tree();

        $this->assertCount(1, $fight->parties);
    }

    public function test_process_exp_tree_returns_true_when_the_fight_should_end() {
        $attacker_party = $this->party_with_one_character(100);
        $victim_party = $this->party_with_one_character(0); // dead -- only one party left alive

        $fight = new FIGHT('bg', 'music');
        $fight->add($attacker_party);
        $fight->add($victim_party);

        $this->assertTrue($fight->process_exp_tree());
    }

    public function test_process_exp_tree_returns_false_when_multiple_unallied_parties_remain() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(100));
        $fight->add($this->party_with_one_character(100));

        $this->assertFalse($fight->process_exp_tree());
    }

    // --- get_character() ---
    // Central to the AI subsystem -- called from dozens of sites
    // across ai.php/ai_action.php to resolve a fighter from an index
    // key.

    public function test_get_character_finds_the_right_character() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(100));
        $second_party = $this->party_with_one_character(100);
        $second_party->groups[0]->characters[0]->name = 'Target';
        $fight->add($second_party);

        $character = $fight->get_character(make_index_key(1, 0, 0));

        $this->assertSame('Target', $character->name);
    }

    public function test_get_character_throws_on_too_few_key_parts() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(100));

        $this->expectException(\Exception::class);
        $fight->get_character(make_index_key(0, 0));
    }

    public function test_get_character_throws_on_too_many_key_parts() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(100));

        $this->expectException(\Exception::class);
        $fight->get_character(make_index_key(0, 0, 0, 5));
    }

    public function test_get_character_throws_on_an_out_of_range_party_index() {
        // Unguarded array access, same characterization as
        // test_own_party_dead()'s out-of-range case.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(100));

        $this->expectException(\Error::class);
        $fight->get_character(make_index_key(99, 0, 0));
    }

    public function test_get_character_throws_on_an_out_of_range_character_index() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_one_character(100));

        $this->expectException(\Exception::class);
        $fight->get_character(make_index_key(0, 0, 99));
    }

    // --- set_PC_action() ---
    // Live production code -- called from
    // app/php_js/change_character_command.php, the real endpoint that
    // applies a player's chosen action. Note the return-value polarity
    // here is the OPPOSITE of the true=error convention used
    // elsewhere in this class (add(), etc.) -- true=success here.
    // Characterized as-is; not changed as part of this pass.
    //
    // Both of the method's own former TODOs are now implemented:
    // party/group/character index bounds-checking (this section's
    // final three tests), and command validity via validate_action()/
    // validate_target() (covered further below).

    private function team_member_party(string $name, int $teamid, bool $monster = false): PARTY {
        $character = $this->fresh_character($name, 100);
        $character->teamid = $teamid;
        $group = new GROUP();
        $group->add_hero($character);
        $party = new PARTY();
        $party->add($group);
        $party->monster = $monster;
        return $party;
    }

    public function test_set_pc_action_succeeds_for_a_matching_team_member() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', teamid: 5));
        $fight->add($this->party_with_one_character(100)); // a real target for [1, 0, 0]

        $result = $fight->set_PC_action(0, 0, 0, 5, [0, [1, 0, 0], 0]);

        $this->assertTrue($result);
        $fighter = $fight->parties[0]->groups[0]->characters[0];
        $this->assertSame(0, $fighter->command);
        $this->assertSame([1, 0, 0], $fighter->target);
        $this->assertSame(0, $fighter->using);
    }

    public function test_set_pc_action_fails_for_a_mismatched_team_and_does_not_mutate_the_character() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', teamid: 5));
        $fighter = $fight->parties[0]->groups[0]->characters[0];
        $fighter->command = 7; // distinctive value, to confirm it's untouched on failure
        $fighter->using = 3;

        $result = $fight->set_PC_action(0, 0, 0, 99, [0, [1, 0, 0], 0]); // player_team=99, fighter's teamid=5

        $this->assertFalse($result);
        $this->assertSame(7, $fighter->command);
        $this->assertSame(3, $fighter->using);
    }

    // Monsters carry CHARACTER::MONSTER_TEAMID, the same team ID a
    // monster-controlling player has -- so one ownership rule covers
    // heroes and monsters. (Replaces the old model, where team 0 meant
    // "monster controller" and the party's monster flag decided it.)

    public function test_set_pc_action_lets_a_monster_controller_command_a_monster() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('Monster', teamid: CHARACTER::MONSTER_TEAMID, monster: true));

        $result = $fight->set_PC_action(0, 0, 0, CHARACTER::MONSTER_TEAMID, [1, [0, 0, 0], 0]);

        $this->assertTrue($result);
    }

    public function test_set_pc_action_does_not_let_a_monster_controller_command_a_hero() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('Hero', teamid: 5));

        $result = $fight->set_PC_action(0, 0, 0, CHARACTER::MONSTER_TEAMID, [1, [0, 0, 0], 0]);

        $this->assertFalse($result);
    }

    public function test_set_pc_action_does_not_let_a_hero_player_command_a_monster() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('Monster', teamid: CHARACTER::MONSTER_TEAMID, monster: true));

        $result = $fight->set_PC_action(0, 0, 0, 5, [1, [0, 0, 0], 0]);

        $this->assertFalse($result);
    }

    public function test_set_pc_action_fails_when_validate_action_rejects_and_does_not_mutate_the_character() {
        // command=2 (use item) with nothing in that inventory slot --
        // validate_action() rejects this regardless of what
        // team/monster checks passed.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', teamid: 5));
        $fighter = $fight->parties[0]->groups[0]->characters[0];
        $fighter->command = 7; // distinctive prior state
        $fighter->using = 3;

        $result = $fight->set_PC_action(0, 0, 0, 5, [2, [0, 0, 0], 99]); // no item in slot 99

        $this->assertFalse($result);
        $this->assertSame(7, $fighter->command);
        $this->assertSame(3, $fighter->using);
    }

    public function test_set_pc_action_fails_when_validate_target_rejects_and_does_not_mutate_the_character() {
        // command=0 (melee) targeting a party index that doesn't
        // exist in this fight -- validate_action() alone can't catch
        // this (it only looks at the character's own state), but
        // validate_target() does.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', teamid: 5));
        $fighter = $fight->parties[0]->groups[0]->characters[0];
        $fighter->command = 7;
        $fighter->using = 3;

        $result = $fight->set_PC_action(0, 0, 0, 5, [0, [99, 0, 0], 0]); // party 99 doesn't exist

        $this->assertFalse($result);
        $this->assertSame(7, $fighter->command);
        $this->assertSame(3, $fighter->using);
    }

    public function test_set_pc_action_fails_for_an_out_of_range_party_index() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', teamid: 5));

        $result = $fight->set_PC_action(99, 0, 0, 5, [6, [0, 0, 0], 0]);

        $this->assertFalse($result);
    }

    public function test_set_pc_action_fails_for_an_out_of_range_group_index() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', teamid: 5));

        $result = $fight->set_PC_action(0, 99, 0, 5, [6, [0, 0, 0], 0]);

        $this->assertFalse($result);
    }

    public function test_set_pc_action_fails_for_an_out_of_range_character_index() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', teamid: 5));

        $result = $fight->set_PC_action(0, 0, 99, 5, [6, [0, 0, 0], 0]);

        $this->assertFalse($result);
    }

    // --- dehydrate() / hydrate() ---

    private function fight_party_with_one_character(string $name, int $charid): PARTY {
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero(CHARACTER::create_hero($name, 1, 1, null, $charid));
        $party->add($group);
        return $party;
    }

    // --- process_fight_action(): XpDebt replay ---
    // xp_debts moved from a separately-maintained fights.xpdebt_json
    // snapshot into the action log itself -- an XpDebt message,
    // emitted by CHARACTER::add_debt_to_xp_tree() alongside
    // Damage/Restore/etc (see CharacterCombatTest.php's own coverage
    // of that), replayed here the same way Damage replays HP loss.

    public function test_process_fight_action_replays_xpdebt_message_onto_the_correct_character() {
        $fight = new FIGHT('bg', 'music');
        $fight->id = 1;
        $fight->add($this->fight_party_with_one_character('Alice', 1));
        $fight->add($this->fight_party_with_one_character('Bob', 2));
        $action = new FightAction(1, 1, [
            ['XpDebt', [1, 0, 0, [0, 0, 0], 0.2, 150]],
        ]);

        $fight->process_fight_action($action);

        $this->assertSame([[[0, 0, 0], 0.2, 150]], $fight->parties[1]->groups[0]->characters[0]->xp_debts);
        // Untouched -- the message targeted party 1, not party 0.
        $this->assertSame([], $fight->parties[0]->groups[0]->characters[0]->xp_debts);
    }

    public function test_process_fight_action_replays_multiple_xpdebt_messages_in_order() {
        // Confirms replay appends rather than overwrites -- matching
        // CHARACTER::add_debt_to_xp_tree()'s own $this->xp_debts[] =
        // ... append, not a replace.
        $fight = new FIGHT('bg', 'music');
        $fight->id = 1;
        $fight->add($this->fight_party_with_one_character('Alice', 1));
        $action = new FightAction(1, 1, [
            ['XpDebt', [0, 0, 0, [1, 0, 0], 0.2, 150]],
            ['XpDebt', [0, 0, 0, [1, 0, 1], 0.1, 150]],
        ]);

        $fight->process_fight_action($action);

        $this->assertSame([
            [[1, 0, 0], 0.2, 150],
            [[1, 0, 1], 0.1, 150],
        ], $fight->parties[0]->groups[0]->characters[0]->xp_debts);
    }

    public function test_dehydrate_projects_direct_properties_and_embeds_each_partys_own_dehydrate() {
        $fight = new FIGHT('bg.png', 'theme.mp3');
        $fight->add($this->fight_party_with_one_character('Alice', 1));
        $fight->id = 42;
        $fight->location = ['map' => 'forest', 'x' => 5, 'y' => 10];

        $data = $fight->dehydrate();

        $this->assertSame(42, $data['id']);
        $this->assertSame(['map' => 'forest', 'x' => 5, 'y' => 10], $data['location']);
        $this->assertArrayNotHasKey('counter', $data);
        $this->assertSame('bg.png', $data['background']);
        $this->assertSame('theme.mp3', $data['music']);
        $this->assertSame(FIGHT::DEHYDRATE_VERSION, $data['version']);
        $this->assertSame('Alice', $data['parties'][0]['groups'][0]['characters'][0]['name']);
    }

    public function test_hydrate_reconstructs_real_nested_party_group_and_character_instances() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->fight_party_with_one_character('Alice', 1));

        $rehydrated = FIGHT::hydrate($fight->dehydrate());

        $this->assertInstanceOf(PARTY::class, $rehydrated->parties[0]);
        $this->assertInstanceOf(GROUP::class, $rehydrated->parties[0]->groups[0]);
        $this->assertInstanceOf(CHARACTER::class, $rehydrated->parties[0]->groups[0]->characters[0]);
        $this->assertSame('Alice', $rehydrated->parties[0]->groups[0]->characters[0]->name);
    }

    public function test_hydrate_always_resets_counter_to_zero() {
        // counter isn't in DEHYDRATE_PROPERTIES (it's an ephemeral,
        // never-persisted replay-order check, not FIGHT's own state --
        // see the property's own comment), so hydrate_common() alone
        // would leave it genuinely uninitialized; this override exists
        // specifically to avoid that, unconditionally, regardless of
        // what the counter was before dehydrate() ran.
        $fight = new FIGHT('bg', 'music');
        $fight->counter = 12;

        $rehydrated = FIGHT::hydrate($fight->dehydrate());

        $this->assertSame(0, $rehydrated->counter);
    }

    public function test_dehydrate_preserves_gaps_in_parties_after_a_party_is_discharged() {
        // Unlike GROUP::remove()/PARTY::remove() (array_splice() +
        // array_merge(), always re-indexed), process_exp_tree()'s step
        // five discharges dead parties via unset(), which can leave
        // gaps in $this->parties. Confirmed separately that PHP casts
        // numeric JSON object keys back to integers on decode, so this
        // should survive intact -- asserted here as a fixed guarantee.
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->fight_party_with_one_character('Alice', 1));
        $fight->add($this->fight_party_with_one_character('Bob', 2));
        $fight->add($this->fight_party_with_one_character('Carol', 3));
        unset($fight->parties[1]);

        $rehydrated = FIGHT::hydrate(json_decode(json_encode($fight->dehydrate()), true));

        $this->assertSame([0, 2], array_keys($rehydrated->parties));
        $this->assertSame('Alice', $rehydrated->parties[0]->groups[0]->characters[0]->name);
        $this->assertSame('Carol', $rehydrated->parties[2]->groups[0]->characters[0]->name);
    }

    public function test_dehydrate_survives_a_json_round_trip_through_three_levels_of_nesting() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->fight_party_with_one_character('Alice', 1));

        $json = json_encode($fight->dehydrate());
        $rehydrated = FIGHT::hydrate(json_decode($json, true));

        $this->assertInstanceOf(PARTY::class, $rehydrated->parties[0]);
        $this->assertInstanceOf(GROUP::class, $rehydrated->parties[0]->groups[0]);
        $this->assertInstanceOf(CHARACTER::class, $rehydrated->parties[0]->groups[0]->characters[0]);
        $this->assertSame(1, $rehydrated->parties[0]->groups[0]->characters[0]->charid);
    }

    // --- to_client() ---
    // The actual wiring CHARACTER::to_client() itself was built for
    // but was never connected to anything -- confirmed directly
    // (nothing anywhere called it) before this was added. A raw,
    // freshly-hydrated character only has command/using/target set at
    // all for whoever has a PendingAction row for the current round;
    // every other character's target is genuinely uninitialized at
    // that point (hydrate() bypasses the constructor entirely) --
    // exactly what crashed a real client reading person.target[0] on
    // a property that was never there. These confirm to_client()
    // itself, not that underlying crash (CHARACTER::to_client()'s own
    // tests already cover its per-character behavior in detail).

    public function test_to_client_gives_the_requesting_teams_own_characters_a_real_target() {
        $fight = new FIGHT('bg', 'music');
        $own = $this->fresh_character('Own');
        $own->teamid = 5;
        $enemy = $this->fresh_character('Enemy');
        $enemy->teamid = 999;
        $own_group = new GROUP(); $own_group->add_hero($own);
        $own_party = new PARTY(); $own_party->add($own_group);
        $enemy_group = new GROUP(); $enemy_group->add_hero($enemy);
        $enemy_party = new PARTY(); $enemy_party->add($enemy_group);
        $fight->add($own_party);
        $fight->add($enemy_party);

        $view = $fight->to_client(5);

        $own_client = $view['parties'][0]['groups'][0]['characters'][0];
        $enemy_client = $view['parties'][1]['groups'][0]['characters'][0];
        $this->assertArrayHasKey('target', $own_client);
        $this->assertArrayHasKey('command', $own_client);
        $this->assertArrayNotHasKey('target', $enemy_client);
        $this->assertArrayNotHasKey('command', $enemy_client);
    }

    public function test_to_client_preserves_fight_level_fields() {
        $fight = new FIGHT('forest.png', 'battle.mp3');
        $fight->id = 42;
        $fight->add($this->fight_party_with_one_character('Alice', 1));

        $view = $fight->to_client(0);

        $this->assertSame(42, $view['id']);
        $this->assertSame('forest.png', $view['background']);
        $this->assertSame('battle.mp3', $view['music']);
        $this->assertSame($fight->location, $view['location']);
    }

    public function test_to_client_covers_every_party_group_and_character() {
        $fight = new FIGHT('bg', 'music');
        $group = new GROUP();
        $group->add_hero($this->fresh_character('A'));
        $group->add_hero($this->fresh_character('B'));
        $party = new PARTY();
        $party->add($group);
        $fight->add($party);

        $view = $fight->to_client(0);

        $this->assertCount(1, $view['parties']);
        $this->assertCount(1, $view['parties'][0]['groups']);
        $this->assertCount(2, $view['parties'][0]['groups'][0]['characters']);
    }

    public function test_to_client_includes_party_and_group_names() {
        // Used to identify the target of a group-targeted ability --
        // confirmed missing entirely from an earlier version of this
        // projection.
        $fight = new FIGHT('bg', 'music');
        $group = new GROUP();
        $group->add_hero($this->fresh_character('A'));
        $group->name = 'Front Line'; // add_hero() auto-sets a default name; override after
        $party = new PARTY();
        $party->add($group);
        $party->name = 'The Heroes';
        $fight->add($party);

        $view = $fight->to_client(0);

        $this->assertSame('The Heroes', $view['parties'][0]['name']);
        $this->assertSame('Front Line', $view['parties'][0]['groups'][0]['name']);
    }

    public function test_to_client_gives_the_requesting_teams_own_party_its_real_teams_map() {
        // The client's own object_create_stats() checks
        // party.teams.length to distinguish a real player-controlled
        // hero party from a player-controlled monster party -- missing
        // entirely from an earlier version of this projection, and
        // confirmed to crash a real client as a result.
        $fight = new FIGHT('bg', 'music');
        $own_party = $this->party_of('Own');
        $own_party->teams = [5 => [1, 2]]; // teamid 5 => charids [1, 2]
        $enemy_party = $this->party_of('Enemy');
        $enemy_party->teams = [999 => [3, 4]];
        $monster_party = $this->party_of('Monster'); // teams never populated for monsters at all
        $fight->add($own_party);
        $fight->add($enemy_party);
        $fight->add($monster_party);

        $view = $fight->to_client(5);

        $this->assertSame([5 => [1, 2]], $view['parties'][0]['teams']);
        // Never the enemy party's real teamid/charid content -- same
        // never-leak-identity-for-non-own rule CHARACTER::to_client()
        // itself already follows.
        $this->assertSame([], $view['parties'][1]['teams']);
        $this->assertSame([], $view['parties'][2]['teams']);
    }
}
