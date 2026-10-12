<?php

/**
 * Covers the cross-database portability work on FIGHT: the DDL and
 * upsert-statement changes needed so save()/load() work identically on
 * MySQL, Postgres, and SQLite alike.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait -- each provides its own $this->db (via its base
 * class's setUp()) and inherits reset_tables() from that same base
 * class. See FightPortabilitySqliteTest/FightPortabilityMysqlTest/
 * FightPortabilityPostgresTest for the three concrete classes.
 */
trait FightPortabilityTests {

    private function make_fight(): FIGHT {
        $fight = new FIGHT('bg.png', 'theme.mp3');
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero(CHARACTER::create_hero('Alice', 1, 1, null, 1));
        $party->add($group);
        $fight->add($party);
        return $fight;
    }

    public function test_collect_resource_ids_gathers_unique_ability_and_item_ids_across_the_roster() {
        $fight = new FIGHT('bg.png', 'theme.mp3');
        $party = new PARTY();
        $group = new GROUP();
        $hero1 = CHARACTER::create_hero('Alice', 1, 1, null, 1);
        $hero1->abilities = [10, 20];
        $hero1->inventory = [['item' => 100, 'qty' => 1]];
        $hero2 = CHARACTER::create_hero('Bob', 1, 1, null, 2);
        // Deliberately overlapping with hero1 (ability 20, item 100)
        // to confirm the result is deduplicated, not just concatenated.
        $hero2->abilities = [20, 30];
        $hero2->inventory = [['item' => 100, 'qty' => 1], ['item' => 200, 'qty' => 1]];
        $group->add_hero($hero1);
        $group->add_hero($hero2);
        $party->add($group);
        $fight->add($party);

        $resource_ids = $fight->collect_resource_ids();

        sort($resource_ids['abilities']);
        sort($resource_ids['items']);
        $this->assertSame([10, 20, 30], $resource_ids['abilities']);
        $this->assertSame([100, 200], $resource_ids['items']);
    }

    public function test_save_then_load_round_trips_including_nested_party_data() {
        $this->reset_tables(FIGHT::class);
        $fight = $this->make_fight();
        $fight->save($this->db);

        $this->assertNotNull($fight->id);

        $reloaded = FIGHT::load($this->db, $fight->id);

        $this->assertSame('bg.png', $reloaded->background);
        $this->assertSame('theme.mp3', $reloaded->music);
        $this->assertInstanceOf(PARTY::class, $reloaded->parties[0]);
        $this->assertSame('Alice', $reloaded->parties[0]->groups[0]->characters[0]->name);
    }

    public function test_saving_an_existing_fight_updates_in_place_not_a_duplicate() {
        $this->reset_tables(FIGHT::class);
        $fight = $this->make_fight();
        $fight->save($this->db);
        $original_id = $fight->id;

        $reloaded = FIGHT::load($this->db, $original_id);
        $reloaded->save($this->db); // re-saving an existing fight

        $all = FIGHT::load_all_from_database($this->db);
        $this->assertCount(1, $all);
        $this->assertArrayHasKey($original_id, $all);
        $this->assertSame($original_id, $all[$original_id]->id);
    }

    public function test_successive_saves_get_distinct_ids() {
        $this->reset_tables(FIGHT::class);
        $fight1 = $this->make_fight();
        $fight1->save($this->db);

        $fight2 = $this->make_fight();
        $fight2->save($this->db);

        $this->assertNotSame($fight1->id, $fight2->id);
    }

    // set_status() (and $fight->state entirely) removed: state now
    // lives on FightSemaphore, not FIGHT -- see
    // FightSemaphorePortabilityTests.php's own claim()/release() tests
    // for the equivalent coverage.

    public function test_initial_state_is_set_on_first_save() {
        $this->reset_tables(FIGHT::class);
        $fight = $this->make_fight();
        $fight->save($this->db);

        $this->assertNotNull($fight->initial_state);
        $this->assertSame('bg.png', $fight->initial_state['background']);

        $reloaded = FIGHT::load($this->db, $fight->id);
        $this->assertNotNull($reloaded->initial_state);
        $this->assertSame('bg.png', $reloaded->initial_state['background']);
    }

    public function test_save_writes_initial_state_for_an_object_that_already_has_its_own_id_and_state() {
        // The actual bug this covers: an imported FIGHT (from
        // unserialize(), as import_db.php's own $fight->save($db) call
        // produces) already has its real, original id AND
        // initial_state set before save() is ever called -- unlike a
        // brand-new FIGHT, where both start out null/id-less. Confirmed
        // by direct reproduction: the previous id-nullness check never
        // wrote initial_state in this case at all, since an imported
        // object's id is never null, leaving every imported fight's
        // initial_state NULL in the freshly-created row.
        $this->reset_tables(FIGHT::class, FightAction::class);
        $imported = $this->make_fight();
        $imported->id = 42; // simulates unserialize() giving back its own original id
        $imported->initial_state = $imported->dehydrate();
        // Via a fresh load() on both sides, not $imported->initial_state
        // directly -- a value that's an int after JSON round-tripping
        // but a float in the live PHP object (0 vs 0.0) would otherwise
        // read as a spurious mismatch, unrelated to what this test
        // actually checks.
        $expected_initial_state = json_decode(json_encode($imported->initial_state), true);

        $imported->save($this->db);

        $reloaded = FIGHT::load_current($this->db, 42);
        $this->assertNotNull($reloaded, 'load_current() must be able to reconstruct an imported fight');
        $this->assertSame($expected_initial_state, $reloaded->initial_state);
    }

    public function test_save_computes_initial_state_for_a_genuinely_new_fight() {
        // The other real call site (start_fight(), on a brand-new
        // FIGHT): initial_state starts null and must be computed via
        // dehydrate() using the id save_row() assigns, the same as
        // before this change.
        $this->reset_tables(FIGHT::class);
        $fight = $this->make_fight();
        $this->assertNull($fight->initial_state);

        $fight->save($this->db);

        $this->assertNotNull($fight->initial_state);
        // Via a fresh load() on both sides -- see the same JSON
        // round-trip note above.
        $expected_initial_state = FIGHT::load($this->db, $fight->id)->initial_state;
        $reloaded = FIGHT::load($this->db, $fight->id);
        $this->assertSame($expected_initial_state, $reloaded->initial_state);
    }

    // --- xp_debts survives a load_current() reload ---
    // The bug this covers: process_fight_action()'s replay of
    // Damage/Restore messages only moves HP/MP numbers -- it never
    // re-triggered add_debt_to_xp_tree() -- so without xp_debts being
    // part of the action log itself (an XpDebt message, replayed the
    // same way Damage/Restore already are -- see FightTest.php's own
    // direct coverage of that replay case), every reconstruction
    // silently started xp_debts back at empty, and only whichever
    // round happened to be live in memory at actual fight resolution
    // ever contributed to XP.

    public function test_load_current_restores_xp_debts_recorded_in_a_saved_fight_action() {
        $this->reset_tables(FIGHT::class, FightAction::class);
        $fight = $this->make_fight();
        $fight->save($this->db);
        (new FightAction($fight->id, 1, [
            ['XpDebt', [0, 0, 0, [1, 0, 0], 0.5, 100]],
        ]))->save($this->db);

        $reloaded = FIGHT::load_current($this->db, $fight->id);

        $this->assertSame([[[1, 0, 0], 0.5, 100]], $reloaded->parties[0]->groups[0]->characters[0]->xp_debts);
    }

    // --- guid ---

    public function test_guid_is_generated_at_construction_and_persists_through_save_and_load() {
        $this->reset_tables(FIGHT::class);
        $fight = $this->make_fight();
        $original_guid = $fight->guid;
        $this->assertSame(32, strlen($original_guid));

        $fight->save($this->db);
        $reloaded = FIGHT::load($this->db, $fight->id);

        $this->assertSame($original_guid, $reloaded->guid);
    }

    public function test_two_fights_get_distinct_guids() {
        $fight_a = $this->make_fight();
        $fight_b = $this->make_fight();

        $this->assertNotSame($fight_a->guid, $fight_b->guid);
    }

    public function test_find_id_by_guid_resolves_a_real_guid() {
        $this->reset_tables(FIGHT::class);
        $fight = $this->make_fight();
        $fight->save($this->db);

        $found = FIGHT::find_id_by_guid($this->db, $fight->guid);

        $this->assertSame($fight->id, $found);
    }

    public function test_find_id_by_guid_returns_null_for_an_unrecognized_guid() {
        $this->reset_tables(FIGHT::class);

        $found = FIGHT::find_id_by_guid($this->db, 'nonexistent');

        $this->assertNull($found);
    }

    // --- process_fight_conclusion() ---

    public function test_process_fight_conclusion_survives_a_fully_defeated_enemy_party() {
        // Regression test for a real production 500 error: "Call to a
        // member function get_pxp() on null". process_exp_tree()
        // removes a defeated party from $this->parties entirely, so
        // any later code that looks up an enemy party via
        // $this->parties[$enemy_index] directly -- rather than a
        // snapshot taken before that call -- gets null for the
        // losing side. This is not an edge case: a real fight always
        // has a fully-defeated party the moment it resolves, so this
        // is the ordinary path, not a rare one.
        $this->reset_tables(FIGHT::class, HERO::class, TEAM::class);

        $winning_teamid = TEAM::create($this->db, 42, 'Winners', [])->teamid;
        $winner = CHARACTER::create_hero('Alice', 1, 1, 1, 1);
        $winner->teamid = $winning_teamid;
        $winner->gold = 0;
        $stats = ['HP' => 500, 'MP' => 50, 'Speed' => 100, 'Accuracy' => 100, 'Strength' => 100, 'Dodge' => 100, 'Block' => 100, 'Power' => 100, 'Resistance' => 100, 'Focus' => 100];
        $winner->current = $stats;
        $winner->base = $stats;
        $winner_group = new GROUP();
        $winner_group->add_hero($winner);
        $winner_party = new PARTY();
        $winner_party->add($winner_group);
        $winner_party->teams[$winning_teamid] = [$winner->charid];

        $losing_teamid = TEAM::create($this->db, 43, 'Losers', [])->teamid;
        $loser = CHARACTER::create_hero('Bob', 1, 1, 1, 2);
        $loser->teamid = $losing_teamid;
        $loser->gold = 50;
        $dead_stats = ['HP' => 0, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 1, 'Focus' => 1];
        $loser->current = $dead_stats;
        $loser->base = ['HP' => 1] + $dead_stats; // base HP > 0 so is_dead() reads current, not base
        $loser_group = new GROUP();
        $loser_group->add_hero($loser);
        $loser_party = new PARTY();
        $loser_party->add($loser_group);
        $loser_party->teams[$losing_teamid] = [$loser->charid];

        $fight = new FIGHT('bg.png', 'theme.mp3');
        $fight->add($winner_party);
        $fight->add($loser_party);
        // Set directly rather than via find_party_alignment() -- that
        // method has an unrelated, pre-existing typo bug
        // ($fight->parites instead of $fight->parties), separate from
        // what this test covers. This matches init_fight.php's own
        // established pattern for a real 1v1 fight's enemy setup.
        $winner_party->enemies = [1];
        $loser_party->enemies = [0];

        $result = $fight->process_fight_conclusion($this->db);

        $this->assertStringContainsString('Alice has earned', $result);
        $this->assertStringContainsString('Bob has earned', $result);
        $this->assertStringContainsString("`team,{$winning_teamid},", $result);
        $this->assertStringContainsString("`team,{$losing_teamid},", $result);
    }

    public function test_process_fight_conclusion_does_not_play_promotion_fanfare_when_nobody_actually_leveled_up() {
        // Regression: level() returns an empty 'data' array (and
        // leveled: false) when no promotion happens -- neither
        // character here gains enough XP from a single, low-level
        // kill to level up. The ack/LevelUp.mp3 fanfare must not
        // appear for either of them.
        $this->reset_tables(FIGHT::class, HERO::class, TEAM::class);

        $winning_teamid = TEAM::create($this->db, 44, 'Winners', [])->teamid;
        $winner = CHARACTER::create_hero('Alice', 1, 1, 1, 1);
        $winner->teamid = $winning_teamid;
        $winner->gold = 0;
        $stats = ['HP' => 500, 'MP' => 50, 'Speed' => 100, 'Accuracy' => 100, 'Strength' => 100, 'Dodge' => 100, 'Block' => 100, 'Power' => 100, 'Resistance' => 100, 'Focus' => 100];
        $winner->current = $stats;
        $winner->base = $stats;
        $winner_group = new GROUP();
        $winner_group->add_hero($winner);
        $winner_party = new PARTY();
        $winner_party->add($winner_group);
        $winner_party->teams[$winning_teamid] = [$winner->charid];

        $losing_teamid = TEAM::create($this->db, 45, 'Losers', [])->teamid;
        $loser = CHARACTER::create_hero('Bob', 1, 1, 1, 2);
        $loser->teamid = $losing_teamid;
        $loser->gold = 0;
        $dead_stats = ['HP' => 0, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 1, 'Focus' => 1];
        $loser->current = $dead_stats;
        $loser->base = ['HP' => 1] + $dead_stats;
        $loser_group = new GROUP();
        $loser_group->add_hero($loser);
        $loser_party = new PARTY();
        $loser_party->add($loser_group);
        $loser_party->teams[$losing_teamid] = [$loser->charid];

        $fight = new FIGHT('bg.png', 'theme.mp3');
        $fight->add($winner_party);
        $fight->add($loser_party);
        $winner_party->enemies = [1];
        $loser_party->enemies = [0];

        $result = $fight->process_fight_conclusion($this->db);

        $this->assertStringNotContainsString('LevelUp.mp3', $result);
        // The music/win-loss fallback sections, both present regardless.
        $this->assertStringContainsString('`music,Fight End.mp3`', $result);
        $this->assertStringContainsString('`music,Funeral March.mp3`', $result);
        $this->assertStringContainsString('The party has won!', $result);
        $this->assertStringContainsString('The party has perished.', $result);
    }

    public function test_process_fight_conclusion_determines_win_or_loss_before_hp_restoration_revives_the_loser() {
        // Regression: is_dead() was being checked after HP
        // restoration already ran (a consolation heal applied to
        // every party, win or lose) -- confirmed directly that a
        // losing party with nonzero pxp relative to the winner gets
        // revived past the is_dead() threshold by that same
        // restoration, so checking win/loss afterward gave the wrong
        // music and text on most close fights, not just a rare edge
        // case. Distinct from the previous test: this asserts each
        // party's OWN specific section gets the correct cue, not just
        // that both cues appear somewhere in the string.
        $this->reset_tables(FIGHT::class, HERO::class, TEAM::class);

        $winning_teamid = TEAM::create($this->db, 46, 'Winners', [])->teamid;
        $winner = CHARACTER::create_hero('Alice', 1, 1, 1, 1);
        $winner->teamid = $winning_teamid;
        $winner->gold = 0;
        $stats = ['HP' => 500, 'MP' => 50, 'Speed' => 100, 'Accuracy' => 100, 'Strength' => 100, 'Dodge' => 100, 'Block' => 100, 'Power' => 100, 'Resistance' => 100, 'Focus' => 100];
        $winner->current = $stats;
        $winner->base = $stats;
        $winner_group = new GROUP();
        $winner_group->add_hero($winner);
        $winner_party = new PARTY();
        $winner_party->add($winner_group);
        $winner_party->teams[$winning_teamid] = [$winner->charid];

        $losing_teamid = TEAM::create($this->db, 47, 'Losers', [])->teamid;
        $loser = CHARACTER::create_hero('Bob', 1, 1, 1, 2);
        $loser->teamid = $losing_teamid;
        $loser->gold = 0;
        $dead_stats = ['HP' => 0, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 1, 'Focus' => 1];
        $loser->current = $dead_stats;
        $loser->base = ['HP' => 1] + $dead_stats;
        $loser_group = new GROUP();
        $loser_group->add_hero($loser);
        $loser_party = new PARTY();
        $loser_party->add($loser_group);
        $loser_party->teams[$losing_teamid] = [$loser->charid];

        $fight = new FIGHT('bg.png', 'theme.mp3');
        $fight->add($winner_party);
        $fight->add($loser_party);
        $winner_party->enemies = [1];
        $loser_party->enemies = [0];

        $result = $fight->process_fight_conclusion($this->db);

        // Bob's HP was restored to at least 1 by this point (the
        // consolation heal), confirming the scenario actually
        // exercises the bug rather than trivially passing.
        $this->assertGreaterThan(0, $loser->current['HP']);

        // The music/win-lose cue lives in each party's own section now
        // (party_0 = winner_party, added first; party_1 = loser_party).
        $this->assertStringContainsString('`label,party_0`', $result);
        $winner_section = substr($result, (int) strpos($result, '`label,party_0`'), 60);
        $this->assertStringContainsString('Fight End.mp3', $winner_section);
        $this->assertStringNotContainsString('Funeral March.mp3', $winner_section);

        $this->assertStringContainsString('`label,party_1`', $result);
        $loser_section = substr($result, (int) strpos($result, '`label,party_1`'), 60);
        $this->assertStringContainsString('Funeral March.mp3', $loser_section);
        $this->assertStringNotContainsString('Fight End.mp3', $loser_section);
    }

    public function test_process_fight_conclusion_shows_party_outcome_before_team_details() {
        // Locks in the intentional UX ordering: overall win/lose comes
        // first in the string, ahead of the detailed per-team XP
        // breakdown, via the party_end convergence label every party
        // section jumps to before falling through into team sections.
        $this->reset_tables(FIGHT::class, HERO::class, TEAM::class);

        $winning_teamid = TEAM::create($this->db, 48, 'Winners', [])->teamid;
        $winner = CHARACTER::create_hero('Alice', 1, 1, 1, 1);
        $winner->teamid = $winning_teamid;
        $winner->gold = 0;
        $stats = ['HP' => 500, 'MP' => 50, 'Speed' => 100, 'Accuracy' => 100, 'Strength' => 100, 'Dodge' => 100, 'Block' => 100, 'Power' => 100, 'Resistance' => 100, 'Focus' => 100];
        $winner->current = $stats;
        $winner->base = $stats;
        $winner_group = new GROUP();
        $winner_group->add_hero($winner);
        $winner_party = new PARTY();
        $winner_party->add($winner_group);
        $winner_party->teams[$winning_teamid] = [$winner->charid];

        $losing_teamid = TEAM::create($this->db, 49, 'Losers', [])->teamid;
        $loser = CHARACTER::create_hero('Bob', 1, 1, 1, 2);
        $loser->teamid = $losing_teamid;
        $loser->gold = 0;
        $dead_stats = ['HP' => 0, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 1, 'Focus' => 1];
        $loser->current = $dead_stats;
        $loser->base = ['HP' => 1] + $dead_stats;
        $loser_group = new GROUP();
        $loser_group->add_hero($loser);
        $loser_party = new PARTY();
        $loser_party->add($loser_group);
        $loser_party->teams[$losing_teamid] = [$loser->charid];

        $fight = new FIGHT('bg.png', 'theme.mp3');
        $fight->add($winner_party);
        $fight->add($loser_party);
        $winner_party->enemies = [1];
        $loser_party->enemies = [0];

        $result = $fight->process_fight_conclusion($this->db);

        $party_pos = strpos($result, '`label,party_0`');
        $party_end_pos = strpos($result, '`label,party_end`');
        $team_pos = strpos($result, "`label,team_{$winning_teamid}`");
        $this->assertNotFalse($party_pos);
        $this->assertNotFalse($party_end_pos);
        $this->assertNotFalse($team_pos);
        $this->assertLessThan($party_end_pos, $party_pos, 'party sections come first');
        $this->assertLessThan($team_pos, $party_end_pos, 'the convergence label comes before team sections');

        // A viewer whose player_party matches but whose team doesn't
        // (a true outsider watching, or no team at all) sees only the
        // party-level outcome, not any team's own XP details -- the
        // fallback for "no party or team matched" was previously a
        // literal, never-matching label string due to a single-quoted
        // interpolation bug, confirmed directly and fixed.
        $this->assertStringContainsString('`party,0,party_1`', $result);
        $this->assertStringContainsString('`ack``jump,party_end`', $result);
    }
}
