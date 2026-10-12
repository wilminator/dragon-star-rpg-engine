<?php

use PHPUnit\Framework\TestCase;

/**
 * Relies entirely on tests/fixtures/cache/ (see its README.md) for
 * ITEM/JOB/ABILITY data -- CACHE_DIR is pointed there by
 * tests/bootstrap.php before any class file loads, so ITEM::get(),
 * JOB::get()/load_all_from_cache(), and ABILITY::get()/filter_active()
 * all work through the real, unmodified file-cache code path. No
 * reflection-based mocking needed: the fixture set is constant and
 * shared across the whole suite, not built fresh per test.
 */
class CharacterTest extends TestCase {
    use AugmentsFixtureCache;

    private function make_character_with_abilities(array $ability_ids): CHARACTER {
        $character = new CHARACTER();
        $character->abilities = $ability_ids;
        return $character;
    }

    // --- scrub_inactive_objects() ---
    // Fixture abilities: 1=active, 2=inactive, 3=active.

    public function test_removes_inactive_abilities_and_keeps_active_ones() {
        $character = $this->make_character_with_abilities([1, 2, 3]);
        $changed = $character->scrub_inactive_objects();

        $this->assertTrue($changed);
        $this->assertSame([1, 3], $character->abilities);
    }

    public function test_returns_false_and_leaves_abilities_untouched_when_all_active() {
        $character = $this->make_character_with_abilities([1, 3]);
        $changed = $character->scrub_inactive_objects();

        $this->assertFalse($changed);
        $this->assertSame([1, 3], $character->abilities);
    }

    public function test_handles_an_empty_abilities_array() {
        $character = $this->make_character_with_abilities([]);
        $changed = $character->scrub_inactive_objects();

        $this->assertFalse($changed);
        $this->assertSame([], $character->abilities);
    }

    public function test_re_indexes_remaining_abilities_from_zero() {
        $character = $this->make_character_with_abilities([2, 1, 2, 3]);
        $character->scrub_inactive_objects();

        // array_values() re-indexes -- the survivors should sit at
        // positions 0 and 1, not retain gaps at their original indices.
        $this->assertSame([0, 1], array_keys($character->abilities));
        $this->assertSame([1, 3], array_values($character->abilities));
    }

    public function test_does_not_touch_inventory() {
        // scrub_inactive_objects() only ever scrubs abilities -- items
        // and inventory are a separate concern it doesn't address at
        // all, despite the name suggesting broader scope. This confirms
        // that boundary explicitly, rather than leaving it as an
        // untested assumption.
        $character = $this->make_character_with_abilities([2]);
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]];

        $character->scrub_inactive_objects();

        $this->assertSame([0 => ['item' => 1, 'qty' => 1]], $character->inventory);
    }

    // --- to_js() ---

    public function test_to_js_returns_valid_json() {
        $character = new CHARACTER();
        $character->name = "Alice";

        $json = $character->to_js();

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertSame("Alice", $decoded["name"]);
    }

    // --- calculate_pxp() ---

    public function test_calculate_pxp_on_a_blank_character_does_not_crash() {
        // The full formula is dense, interdependent math across every
        // stat and both weapon slots -- this is a sanity check that it
        // runs and stores a consistent int, not a verification of the
        // formula's specific values.
        $character = new CHARACTER();
        $pxp = $character->calculate_pxp();

        $this->assertIsInt($pxp);
        $this->assertSame($pxp, $character->pxp);
    }

    // --- $need initialization ---
    // Regression coverage: $need has no default value in its property
    // declaration (public ?int $need;), and unlike charid/teamid/
    // personalityid/exp, the constructor didn't null-initialize it --
    // so a character that never goes through JOB::don() or make_hero()
    // (both of which do set it) is left with $need genuinely
    // uninitialized, not just null. Reading an uninitialized typed
    // property throws a fatal Error rather than behaving like an unset
    // variable, and dehydrate() reads every DEHYDRATE_PROPERTIES entry
    // directly -- so this was silently breaking dehydrate() (and
    // anything downstream of it) for any character whose job lookup
    // ever failed, real-world monsters included.

    public function test_a_freshly_constructed_character_has_need_initialized() {
        // Directly accessing $need (not isset(), which would mask an
        // uninitialized-property error the same way it masks a merely
        // unset one) is the point of this assertion.
        $character = new CHARACTER();

        $this->assertNull($character->need);
    }

    // --- add_item() ---
    // Fixture items: 1=stackable potion (equip_type=[]), 2=sword (hand),
    // 3=armor (body).

    public function test_add_item_stacks_onto_an_existing_matching_slot() {
        $character = new CHARACTER();
        $character->inventory = [0 => ['item' => 1, 'qty' => 10]];
        $result = $character->add_item(1, 20);

        $this->assertSame(0, $result);
        $this->assertSame([0 => ['item' => 1, 'qty' => 30]], $character->inventory);
    }

    public function test_add_item_creates_a_new_slot_when_nothing_matches() {
        $character = new CHARACTER();
        $result = $character->add_item(1, 10);

        $this->assertSame(0, $result);
        $this->assertSame([0 => ['item' => 1, 'qty' => 10]], $character->inventory);
    }

    public function test_add_item_overflow_fills_existing_slot_and_carries_remainder_to_a_new_one() {
        $character = new CHARACTER();
        $character->inventory = [0 => ['item' => 1, 'qty' => 240]];
        $result = $character->add_item(1, 30); // 240+30 exceeds the 250 max

        $this->assertSame(0, $result);
        $this->assertSame(
            [0 => ['item' => 1, 'qty' => 250], 1 => ['item' => 1, 'qty' => 20]],
            $character->inventory
        );
    }

    public function test_add_item_non_stackable_equipment_does_not_merge_into_an_existing_slot() {
        // equip_type non-empty and not ammo -- the stacking condition
        // explicitly excludes this, so a second copy should get its own
        // slot rather than merge, even with a matching itemid.
        $character = new CHARACTER();
        $character->inventory = [0 => ['item' => 2, 'qty' => 1]];
        $result = $character->add_item(2, 1);

        $this->assertSame(0, $result);
        $this->assertSame(
            [0 => ['item' => 2, 'qty' => 1], 1 => ['item' => 2, 'qty' => 1]],
            $character->inventory
        );
    }

    public function test_add_item_returns_leftover_when_inventory_is_full() {
        $character = new CHARACTER();
        for ($i = 0; $i < CHARACTER_MAX_ITEMS; $i++) {
            $character->inventory[$i] = ['item' => 3, 'qty' => 1];
        }
        $result = $character->add_item(2, 3);

        $this->assertSame(3, $result);
        $this->assertCount(CHARACTER_MAX_ITEMS, $character->inventory);
    }

    // --- remove_item() ---

    public function test_remove_item_partial_removal_decrements_quantity() {
        $character = new CHARACTER();
        $character->inventory = [0 => ['item' => 1, 'qty' => 10]];
        $result = $character->remove_item(0, 4);

        $this->assertSame(4, $result);
        $this->assertSame([0 => ['item' => 1, 'qty' => 6]], $character->inventory);
    }

    public function test_remove_item_full_removal_clears_the_slot() {
        $character = new CHARACTER();
        $character->inventory = [0 => ['item' => 1, 'qty' => 10]];
        // Requesting more than present removes the whole stack and
        // returns the actual quantity that was there, not the request.
        $result = $character->remove_item(0, 999);

        $this->assertSame(10, $result);
        $this->assertSame([], $character->inventory);
    }

    public function test_remove_item_out_of_bounds_index_returns_zero() {
        $character = new CHARACTER();
        $result = $character->remove_item(5, 1);

        $this->assertSame(0, $result);
    }

    // --- move_item() ---

    public function test_move_item_is_a_no_op_when_slots_match() {
        $character = new CHARACTER();
        $character->inventory = [0 => ['item' => 1, 'qty' => 1]];
        $character->move_item(0, 0);

        $this->assertSame([0 => ['item' => 1, 'qty' => 1]], $character->inventory);
    }

    public function test_move_item_throws_when_source_slot_is_empty() {
        $character = new CHARACTER();

        $this->expectException(Exception::class);
        $character->move_item(0, 1);
    }

    public function test_move_item_forward_slides_intervening_slots_down() {
        $character = new CHARACTER();
        $character->inventory = [
            0 => ['item' => 1, 'qty' => 1],
            1 => ['item' => 2, 'qty' => 1],
            2 => ['item' => 3, 'qty' => 1],
        ];
        $character->move_item(0, 2);

        $this->assertSame([
            2 => ['item' => 1, 'qty' => 1],
            0 => ['item' => 2, 'qty' => 1],
            1 => ['item' => 3, 'qty' => 1],
        ], $character->inventory);
    }

    public function test_move_item_backward_slides_intervening_slots_up() {
        $character = new CHARACTER();
        $character->inventory = [
            0 => ['item' => 1, 'qty' => 1],
            1 => ['item' => 2, 'qty' => 1],
            2 => ['item' => 3, 'qty' => 1],
        ];
        $character->move_item(2, 0);

        $this->assertSame([
            1 => ['item' => 1, 'qty' => 1],
            2 => ['item' => 2, 'qty' => 1],
            0 => ['item' => 3, 'qty' => 1],
        ], $character->inventory);
    }

    public function test_move_item_remaps_equipment_slots_pointing_at_moved_items() {
        $character = new CHARACTER();
        $character->inventory = [
            0 => ['item' => 1, 'qty' => 1],
            1 => ['item' => 1, 'qty' => 1],
            2 => ['item' => 1, 'qty' => 1],
        ];
        $character->equipment['lhand'] = 2;
        $character->move_item(0, 2);

        // The item that was equipped in slot 2 is now at slot 1 after
        // the forward slide -- the equipment reference must follow it.
        $this->assertSame(1, $character->equipment['lhand']);
    }

    // --- get_current() ---
    // Regression: get_current() computed the equipment-adjusted value
    // but returned the unadjusted $current instead -- every non-HP/MP
    // equipment bonus was silently discarded. That showed up as "+0"
    // for everything in setup_equip, and (more seriously) meant gear
    // never affected combat math, since accuracy/dodge/strength/etc.
    // all go through get_current().

    private function with_armor_bonus(array $flat, array $percent, callable $body): void {
        // ITEM::get() returns a shared cached instance, and the fixture
        // armor carries no bonuses -- give it some for this test only,
        // then restore so no other test sees the change.
        $armor = ITEM::get(3);
        $saved = [$armor->stat_increase, $armor->stat_percent_increase];
        $armor->stat_increase = array_merge($armor->stat_increase, $flat);
        $armor->stat_percent_increase = array_merge($armor->stat_percent_increase, $percent);
        try {
            $body();
        } finally {
            [$armor->stat_increase, $armor->stat_percent_increase] = $saved;
        }
    }

    public function test_get_current_includes_flat_equipment_bonus() {
        $this->with_armor_bonus(['Dodge' => 5], [], function () {
            $character = new CHARACTER();
            $character->current['Dodge'] = 20;
            $character->inventory = [0 => ['item' => 3, 'qty' => 1]];
            $character->equipment['body'] = 0;

            $this->assertSame(25, $character->get_current('Dodge'));
        });
    }

    public function test_get_current_includes_percent_equipment_bonus() {
        $this->with_armor_bonus([], ['Block' => 50], function () {
            $character = new CHARACTER();
            $character->current['Block'] = 20;
            $character->inventory = [0 => ['item' => 3, 'qty' => 1]];
            $character->equipment['body'] = 0;

            $this->assertSame(30, $character->get_current('Block'));
        });
    }

    public function test_get_current_without_equipment_is_unchanged() {
        $character = new CHARACTER();
        $character->current['Dodge'] = 20;

        $this->assertSame(20, $character->get_current('Dodge'));
    }

    public function test_get_current_hp_and_mp_ignore_equipment() {
        // HP/MP bonuses raise the cap (get_base), not the live value.
        $this->with_armor_bonus(['HP' => 50, 'MP' => 10], [], function () {
            $character = new CHARACTER();
            $character->current['HP'] = 40;
            $character->current['MP'] = 5;
            $character->inventory = [0 => ['item' => 3, 'qty' => 1]];
            $character->equipment['body'] = 0;

            $this->assertSame(40, $character->get_current('HP'));
            $this->assertSame(5, $character->get_current('MP'));
        });
    }

    // --- is_dead() ---

    public function test_is_dead_boundary_at_zero_and_one_hp() {
        $character = new CHARACTER();

        $character->current['HP'] = 0;
        $this->assertTrue($character->is_dead());

        $character->current['HP'] = 1;
        $this->assertFalse($character->is_dead());
    }

    // --- reset_stats() ---

    public function test_reset_stats_resets_non_hp_mp_stats_to_base_by_default() {
        $character = new CHARACTER();
        $character->base['Strength'] = 10;
        $character->current['Strength'] = 3;
        $character->base['HP'] = 50;
        $character->current['HP'] = 20;

        $character->reset_stats();

        $this->assertSame(10, $character->current['Strength']);
        // HP is excluded unless $all is true.
        $this->assertSame(20, $character->current['HP']);
    }

    public function test_reset_stats_with_all_true_includes_hp_and_mp() {
        $character = new CHARACTER();
        $character->base['HP'] = 50;
        $character->current['HP'] = 20;

        $character->reset_stats(true);

        $this->assertSame(50, $character->current['HP']);
    }

    // --- normalize_stats() ---

    public function test_normalize_stats_moves_current_toward_base_when_above_it() {
        $character = new CHARACTER();
        $character->base['Strength'] = 100;
        $character->current['Strength'] = 110; // 10 above base

        $character->normalize_stats();

        $this->assertSame(109, $character->current['Strength']);
    }

    public function test_normalize_stats_moves_current_toward_base_when_below_it() {
        $character = new CHARACTER();
        $character->base['Strength'] = 100;
        $character->current['Strength'] = 90; // 10 below base

        $character->normalize_stats();

        $this->assertSame(91, $character->current['Strength']);
    }

    public function test_normalize_stats_excludes_hp_and_mp_by_default() {
        $character = new CHARACTER();
        $character->base['HP'] = 50;
        $character->current['HP'] = 60;

        $character->normalize_stats();

        $this->assertSame(60, $character->current['HP']);
    }

    // --- make_leveled_hero() ---
    // Fixture jobs: 1=zero-growth (need=100), 2=deterministic-55,
    // 3=ability-granter (grants ability 1 at level 2).

    public function test_make_leveled_hero_sets_identity_and_levels_to_the_target() {
        $character = new CHARACTER();
        $character->make_leveled_hero("Alice", 1, 3, 7, 42);

        $this->assertSame(42, $character->charid);
        $this->assertSame("Alice", $character->name);
        $this->assertSame(1, $character->jobid);
        $this->assertSame(7, $character->personalityid);
        $this->assertSame(3, $character->level);
    }

    public function test_make_leveled_hero_sets_current_equal_to_base() {
        $character = new CHARACTER();
        $character->make_leveled_hero("Alice", 1, 2, null);

        $this->assertSame($character->base, $character->current);
    }

    public function test_make_leveled_hero_with_an_unknown_job_leaves_level_at_zero() {
        // jobid 999 doesn't exist in the fixture set -- the lookup
        // fails, and make_leveled_hero() logs an error and stops
        // rather than crashing. $level is set to 0 unconditionally
        // before the job check runs, so it stays 0 rather than being
        // left uninitialized.
        $character = new CHARACTER();
        $character->make_leveled_hero("Ghost", 999, 5, null);

        $this->assertSame(0, $character->level);
    }

    public function test_make_leveled_hero_with_an_unknown_job_still_dehydrates() {
        // Same unknown-job path as above, but exercised through
        // dehydrate() specifically: this is the exact real-world
        // scenario (a monster/character whose jobid lookup fails) that
        // used to throw a fatal Error the first time anything tried to
        // dehydrate a fight containing this character, rather than at
        // creation time -- so the failure showed up far from its cause.
        $character = new CHARACTER();
        $character->make_leveled_hero("Ghost", 999, 5, null);

        $data = $character->dehydrate();

        $this->assertNull($data['need']);
    }

    public function test_make_leveled_hero_grants_abilities_reached_by_leveling() {
        // Job 3 grants ability 1 at level 2.
        $character = new CHARACTER();
        $character->make_leveled_hero("Dave", 3, 2, null);

        $this->assertSame([1], $character->abilities);
    }

    public function test_make_leveled_hero_grants_multiple_abilities_at_different_levels() {
        // A one-off job granting two abilities at two different
        // levels -- not worth adding to the shared base fixture set
        // (see tests/fixtures/cache/README.md) since no other test
        // needs it. Demonstrates with_extra_cache() from
        // AugmentsFixtureCache: the base set (jobs 1-3, items 0-4,
        // abilities 1-3) stays untouched for every other test.
        $abilities = $this->with_extra_cache(function () {
            $character = new CHARACTER();
            $character->make_leveled_hero("Multi", 100, 4, null);
            return $character->abilities;
        }, jobs: [100 => build_job_row(
            100,
            'Multi-Grant Job',
            50,
            array_fill_keys(['HP', 'MP', 'Speed', 'Accuracy', 'Strength', 'Dodge', 'Block', 'Power', 'Resistance', 'Focus'], 0),
            [['ability' => 1, 'level' => 2], ['ability' => 3, 'level' => 4]]
        )]);

        $this->assertSame([1, 3], $abilities);
    }

    // --- level() ---

    public function test_level_does_not_level_when_exp_is_below_need() {
        $character = new CHARACTER();
        $character->make_leveled_hero("Alice", 1, 1, null);
        $starting_level = $character->level;
        $character->exp = $character->need - 1;

        $result = $character->level();

        $this->assertSame($starting_level, $character->level);
        $this->assertFalse($result['leveled']);
        $this->assertSame([], $result['data']);
    }

    public function test_level_levels_once_when_exp_meets_need() {
        $character = new CHARACTER();
        $character->make_leveled_hero("Alice", 1, 1, null);
        $starting_level = $character->level;
        $character->exp = $character->need;

        $result = $character->level();

        $this->assertSame($starting_level + 1, $character->level);
        $this->assertTrue($result['leveled']);
    }

    public function test_level_caps_exp_to_need_when_already_at_level_100() {
        // The cap only applies on a call that STARTS already at level
        // 100 -- confirmed empirically, not on the call that reaches it.
        $character = new CHARACTER();
        $character->jobid = 1;
        $character->name = "Grinder";
        $character->level = 100;
        $character->need = 1;
        $character->exp = 999999;

        $character->level();

        $this->assertSame($character->need, $character->exp);
    }

    // --- regenerate_stats() ---

    public function test_regenerate_stats_preserves_exp_and_re_derives_the_same_level() {
        $character = new CHARACTER();
        $character->make_leveled_hero("Alice", 1, 3, null);
        $exp_before = $character->exp;

        $character->regenerate_stats();

        $this->assertSame($exp_before, $character->exp);
        $this->assertSame(3, $character->level);
    }

    public function test_regenerate_stats_maxes_all_stats() {
        $character = new CHARACTER();
        $character->make_leveled_hero("Alice", 1, 3, null);

        $character->regenerate_stats();

        // reset_stats(true) at the end -- current should equal base
        // across every stat, HP/MP included.
        $this->assertSame($character->base, $character->current);
    }

    public function test_regenerate_stats_returns_the_leveling_result() {
        $character = new CHARACTER();
        $character->make_leveled_hero("Alice", 1, 3, null);

        $result = $character->regenerate_stats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('leveled', $result);
        $this->assertArrayHasKey('data', $result);
    }

    // --- dehydrate() / hydrate() ---
    // Meant to fully round-trip everything needed to reconstitute a
    // character mid-fight (not just persistent identity/progression
    // data) -- for database storage.

    private function richly_populated_character(): CHARACTER {
        $character = new CHARACTER();
        $character->charid = 42;
        $character->teamid = 7;
        $character->name = 'Hero';
        $character->jobid = 1;
        $character->level = 5;
        $character->exp = 1200;
        $character->need = 300;
        $character->current['HP'] = 80;
        $character->base['HP'] = 100;
        $character->current['MP'] = 20;
        $character->base['MP'] = 30;
        $character->inventory = [0 => ['item' => 1, 'qty' => 2]];
        $character->pxp = 55;
        $character->personalityid = 3;
        $character->xp_debts = [[[0, 0, 0], 0.5, 100]];
        $character->old_exp = 1100;
        $character->gold = 250;
        $character->ai_action = 1;
        $character->ai_goal = 2;
        $character->command = 4;
        $character->using = 1;
        $character->target = [1, 0, 2];
        return $character;
    }

    public function test_dehydrate_includes_a_version_tag() {
        $character = $this->richly_populated_character();

        $data = $character->dehydrate();

        $this->assertSame(CHARACTER::DEHYDRATE_VERSION, $data['version']);
    }

    public function test_hydrate_reverses_dehydrate_exactly() {
        // command/using/target deliberately excluded here: as of
        // DEHYDRATE_VERSION 2, they're no longer part of what
        // dehydrate()/hydrate() round-trip at all (see the version-hook
        // tests below).
        $character = $this->richly_populated_character();

        $rehydrated = CHARACTER::hydrate($character->dehydrate());

        $this->assertSame($character->charid, $rehydrated->charid);
        $this->assertSame($character->name, $rehydrated->name);
        $this->assertSame($character->current, $rehydrated->current);
        $this->assertSame($character->base, $rehydrated->base);
        $this->assertSame($character->inventory, $rehydrated->inventory);
        $this->assertSame($character->xp_debts, $rehydrated->xp_debts);
        $this->assertSame($character->pxp, $rehydrated->pxp);
    }

    public function test_dehydrate_survives_a_json_round_trip() {
        // Simulates actual DB storage: dehydrate() -> json_encode() ->
        // (stored, then later fetched) -> json_decode() -> hydrate().
        // Confirms nested arrays and array-shaped tuples like
        // xp_debts entries survive the trip, not just scalar fields.
        $character = $this->richly_populated_character();

        $json = json_encode($character->dehydrate());
        $decoded = json_decode($json, true);
        $rehydrated = CHARACTER::hydrate($decoded);

        $this->assertSame($character->xp_debts, $rehydrated->xp_debts);
    }

    // --- hydrate() and command/using/target ---
    // hydrate_common() -- the trait's own generic hydrate(), aliased
    // in -- bypasses the constructor entirely, so anything it doesn't
    // set from DEHYDRATE_PROPERTIES is left genuinely uninitialized,
    // not merely unset. command/using/target aren't in
    // DEHYDRATE_PROPERTIES (tracked in fight_pending_actions instead),
    // so CHARACTER's own hydrate() override exists specifically to
    // default them, matching the same failure mode $need hit before
    // its own fix. (This override briefly branched on $data['version']
    // to prove DehydrateTrait's version hook actually worked, reading
    // old inline values for data dehydrated before this change --
    // confirmed, and with nothing but test data around to preserve,
    // simplified back to unconditional once that was proven.)

    public function test_hydrate_defaults_command_using_and_target() {
        $data = $this->richly_populated_character()->dehydrate();

        $rehydrated = CHARACTER::hydrate($data);

        $this->assertSame(0, $rehydrated->command);
        $this->assertSame(0, $rehydrated->using);
        $this->assertSame([1, 0, 0], $rehydrated->target);
    }


    // --- to_client() ---

    public function test_to_client_own_team_includes_identity_and_queued_action() {
        // Command memory means the player's own currently-queued
        // command/using/target is real server state the UI needs to
        // reflect -- deliberately visible here, unlike the non-allied
        // case below.
        $character = $this->richly_populated_character();

        $data = $character->to_client(is_own_team: true);

        $this->assertSame(42, $data['charid']);
        $this->assertSame('Hero', $data['name']);
        $this->assertSame(3, $data['personalityid']);
        $this->assertSame(1, $data['jobid']);
        $this->assertSame(4, $data['command']);
        $this->assertSame(1, $data['using']);
        $this->assertSame([1, 0, 2], $data['target']);
    }

    public function test_to_client_own_team_includes_abilities_equipment_and_inventory() {
        // The UI needs these to actually populate the action menu's
        // real options (which ability, which item, what's equipped),
        // not just display current state.
        $character = $this->richly_populated_character();
        $character->abilities = [0 => 12, 1 => 7];
        $character->equipment['rhand'] = 5;

        $data = $character->to_client(is_own_team: true);

        $this->assertSame([0 => 12, 1 => 7], $data['abilities']);
        $this->assertSame(5, $data['equipment']['rhand']);
        $this->assertSame([0 => ['item' => 1, 'qty' => 2]], $data['inventory']);
    }

    public function test_to_client_own_team_exposes_hp_and_mp_only_not_full_stats() {
        // Deliberate design choice: even for your own roster, only
        // HP/MP are sent -- not full base/current stats -- to preserve
        // player intuition as part of the strategy.
        $character = $this->richly_populated_character();
        $character->current['Strength'] = 99;
        $character->base['Strength'] = 99;

        $data = $character->to_client(is_own_team: true);

        $this->assertSame(['HP' => 80, 'MP' => 20], $data['current']);
        $this->assertSame(['HP' => 100, 'MP' => 30], $data['base']);
        $this->assertArrayNotHasKey('Strength', $data['current']);
    }

    public function test_to_client_non_allied_omits_identity_and_queued_action_entirely() {
        // No charid/teamid (must never reach a non-owning client), and
        // no command/using/target at all -- never tiered like HP,
        // simply never present, since foreknowledge of an enemy's
        // queued action is a direct tactical advantage.
        $character = $this->richly_populated_character();

        $data = $character->to_client(is_own_team: false);

        $this->assertArrayNotHasKey('charid', $data);
        $this->assertArrayNotHasKey('teamid', $data);
        $this->assertArrayNotHasKey('command', $data);
        $this->assertArrayNotHasKey('using', $data);
        $this->assertArrayNotHasKey('target', $data);
    }

    public function test_to_client_non_allied_includes_numeric_hp_and_mp_as_a_stopgap() {
        // Stopgap as of this writing (see to_client()'s own
        // docblock): the client's hide/show and numeric-vs-bar display
        // logic exists but was never actually wired in, so it always
        // expects real numeric HP/MP for every combatant regardless of
        // team. Not the intended final behavior -- hp_display's
        // tiering above is -- but withholding real numbers broke the
        // client outright rather than degrading gracefully.
        $character = $this->richly_populated_character();
        $character->current['Strength'] = 99;
        $character->base['Strength'] = 99;

        $data = $character->to_client(is_own_team: false);

        $this->assertSame(['HP' => 80, 'MP' => 20], $data['current']);
        $this->assertSame(['HP' => 100, 'MP' => 30], $data['base']);
        $this->assertArrayNotHasKey('Strength', $data['current']);
    }

    public function test_to_client_non_allied_includes_name_and_an_hp_display_stub() {
        // determine_hp_display() is a stub -- the real PvE/PvP tiering
        // logic isn't designed yet -- always the full fraction for now.
        $character = $this->richly_populated_character();

        $data = $character->to_client(is_own_team: false);

        $this->assertSame('Hero', $data['name']);
        $this->assertSame(3, $data['personalityid']);
        $this->assertSame(0.8, $data['hp_display']); // 80 current / 100 base
    }

    // --- to_client() and is_own_party ---
    // Regression: a party-mate on a different team within the same
    // multi-team party was displaying as class "Monster" during
    // playback -- jobid was omitted entirely for anyone whose team
    // didn't match the viewer's own, even allies sharing the same
    // party. Class/job isn't team-private the way command/using/
    // target/abilities/equipment/inventory are: seeing an ally's class
    // doesn't reveal their queued strategy.

    public function test_to_client_non_own_team_but_own_party_includes_jobid() {
        $character = $this->richly_populated_character();

        $data = $character->to_client(is_own_team: false, is_own_party: true);

        $this->assertSame(1, $data['jobid']);
        // Still not team-private data, even for a party-mate.
        $this->assertArrayNotHasKey('command', $data);
        $this->assertArrayNotHasKey('using', $data);
        $this->assertArrayNotHasKey('target', $data);
        $this->assertArrayNotHasKey('abilities', $data);
        $this->assertArrayNotHasKey('equipment', $data);
        $this->assertArrayNotHasKey('inventory', $data);
    }

    public function test_to_client_non_own_party_still_omits_jobid() {
        // A true outsider -- not on this party at all -- still can't
        // see class, matching the pre-existing, unchanged behavior.
        $character = $this->richly_populated_character();

        $data = $character->to_client(is_own_team: false, is_own_party: false);

        $this->assertArrayNotHasKey('jobid', $data);
    }

    public function test_to_client_is_own_party_defaults_to_false() {
        $character = $this->richly_populated_character();

        $data = $character->to_client(is_own_team: false);

        $this->assertArrayNotHasKey('jobid', $data);
    }
}
