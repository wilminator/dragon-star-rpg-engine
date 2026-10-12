<?php

/**
 * Covers FightSave: save()/load() round-trips every field,
 * multiple distinct tags on the same fight coexist correctly (the
 * point of the re-aligned primary key), load_all_by_type()'s
 * power_ratio range filtering, compute_power_ratio() itself, and the
 * two things save() itself enforces: a fight must be Resolved before
 * it can carry any tag at all.
 *
 * Every test that saves a tag first calls make_resolved_fight() to
 * give that fightid a Resolved FightSemaphore row -- save() now
 * queries that table directly and refuses otherwise, confirmed by its
 * own dedicated tests further down.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait. See FightSavePortabilitySqliteTest/
 * FightSavePortabilityMysqlTest/FightSavePortabilityPostgresTest.
 */
trait FightSavePortabilityTests {

    private function make_resolved_fight(int $fightid): void {
        (new FightSemaphore($fightid, FightStatus::Resolved, 1, time() + 600))->save($this->db);
    }

    public function test_save_then_load_round_trips_every_field() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);

        (new FightSave(1, 0, 'user', 2.5))->save($this->db);

        $reloaded = FightSave::load($this->db, 1, 0, 'user');
        $this->assertSame(1, $reloaded->fightid);
        $this->assertSame(0, $reloaded->teamid);
        $this->assertSame('user', $reloaded->type);
        $this->assertSame(2.5, $reloaded->power_ratio);
    }

    public function test_power_ratio_can_be_null() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);

        (new FightSave(1, 0, 'retain'))->save($this->db);

        $reloaded = FightSave::load($this->db, 1, 0, 'retain');
        $this->assertNull($reloaded->power_ratio);
    }

    public function test_load_returns_null_for_an_untagged_fight() {
        $this->reset_tables(FightSave::class);

        $this->assertNull(FightSave::load($this->db, 1, 0, 'user'));
    }

    public function test_a_single_fight_can_carry_multiple_distinct_tags() {
        // The re-alignment's entire point: the original (fightid,
        // playerid) primary key could only ever hold one tag per
        // fight per player. A system-generated fight tagged both
        // 'user' (it's generated content) and 'underdog' (a
        // classification of its outcome) needs both to coexist.
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        (new FightSave(1, 0, 'user', 0.4))->save($this->db);
        (new FightSave(1, 0, 'underdog', 0.4))->save($this->db);

        $all = FightSave::load_all_by_fightid($this->db, 1);

        $this->assertCount(2, $all);
        $this->assertEqualsCanonicalizing(['user', 'underdog'], array_map(fn($s) => $s->type, $all));
    }

    public function test_load_all_from_database_returns_tags_across_every_fight_and_team() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        $this->make_resolved_fight(2);
        (new FightSave(1, 0, 'user', 1.0))->save($this->db);
        (new FightSave(1, 0, 'underdog', 5.0))->save($this->db);
        (new FightSave(2, 42, 'retain'))->save($this->db);

        $all = FightSave::load_all_from_database($this->db);

        $this->assertCount(3, $all);
        $this->assertEqualsCanonicalizing(
            [[1, 0, 'user'], [1, 0, 'underdog'], [2, 42, 'retain']],
            array_map(fn($s) => [$s->fightid, $s->teamid, $s->type], $all)
        );
    }

    public function test_saving_over_an_existing_tag_updates_in_place_not_a_duplicate() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        (new FightSave(1, 0, 'user', 1.0))->save($this->db);

        (new FightSave(1, 0, 'user', 2.0))->save($this->db);

        $reloaded = FightSave::load($this->db, 1, 0, 'user');
        $this->assertSame(2.0, $reloaded->power_ratio);
        $this->assertCount(1, FightSave::load_all_by_fightid($this->db, 1));
    }

    public function test_load_all_by_type_filters_by_teamid_and_type() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        $this->make_resolved_fight(2);
        $this->make_resolved_fight(3);
        $this->make_resolved_fight(4);
        (new FightSave(1, 0, 'user', 1.0))->save($this->db);
        (new FightSave(2, 0, 'user', 1.0))->save($this->db);
        (new FightSave(3, 0, 'retain'))->save($this->db); // different type
        (new FightSave(4, 99, 'user', 1.0))->save($this->db); // different teamid

        $all = FightSave::load_all_by_type($this->db, 0, 'user');

        $this->assertCount(2, $all);
        $this->assertEqualsCanonicalizing([1, 2], array_map(fn($s) => $s->fightid, $all));
    }

    public function test_load_all_by_type_filters_by_power_ratio_range() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        $this->make_resolved_fight(2);
        $this->make_resolved_fight(3);
        (new FightSave(1, 0, 'user', 0.2))->save($this->db); // underdog-ish
        (new FightSave(2, 0, 'user', 1.0))->save($this->db); // even
        (new FightSave(3, 0, 'user', 8.0))->save($this->db); // overkill-ish

        $underdogs = FightSave::load_all_by_type($this->db, 0, 'user', max_power_ratio: 0.5);
        $overkills = FightSave::load_all_by_type($this->db, 0, 'user', min_power_ratio: 5.0);

        $this->assertCount(1, $underdogs);
        $this->assertSame(1, $underdogs[0]->fightid);
        $this->assertCount(1, $overkills);
        $this->assertSame(3, $overkills[0]->fightid);
    }

    // --- save() must be Resolved ---

    public function test_save_refuses_a_fight_with_no_semaphore_row_and_no_fight_at_all() {
        $this->reset_tables(FightSave::class, FightSemaphore::class, FIGHT::class);

        $this->expectException(\Exception::class);
        (new FightSave(1, 0, 'user'))->save($this->db);
    }

    public function test_save_refuses_a_fight_that_is_not_yet_resolved() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 10); // WaitingForInput

        $this->expectException(\Exception::class);
        (new FightSave(1, 0, 'user'))->save($this->db);
    }

    public function test_save_succeeds_once_the_fight_is_resolved() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);

        (new FightSave(1, 0, 'user'))->save($this->db);

        $this->assertNotNull(FightSave::load($this->db, 1, 0, 'user'));
    }

    // --- compute_power_ratio() ---

    private function party_with_pxp(int $pxp): PARTY {
        $character = CHARACTER::create_hero('X', 999, 1, null);
        $character->pxp = $pxp;
        $group = new GROUP();
        $group->add_hero($character);
        $party = new PARTY();
        $party->add($group);
        return $party;
    }

    public function test_compute_power_ratio_two_parties() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_pxp(100)); // winner
        $fight->add($this->party_with_pxp(50));

        $ratio = FightSave::compute_power_ratio($fight, 0);

        $this->assertSame(2.0, $ratio);
    }

    public function test_compute_power_ratio_multi_party_sums_every_other_party() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_pxp(90)); // winner
        $fight->add($this->party_with_pxp(30));
        $fight->add($this->party_with_pxp(30));

        $ratio = FightSave::compute_power_ratio($fight, 0);

        $this->assertSame(1.5, $ratio); // 90 / (30+30)
    }

    public function test_compute_power_ratio_returns_null_when_no_other_party_had_any_pxp() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_with_pxp(100));
        $fight->add($this->party_with_pxp(0));

        $ratio = FightSave::compute_power_ratio($fight, 0);

        $this->assertNull($ratio);
    }

    // --- record_user_history() ---

    public function test_record_user_history_tags_the_fight_as_user() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);

        FightSave::record_user_history($this->db, 1, 42);

        $this->assertNotNull(FightSave::load($this->db, 1, 42, 'user'));
    }

    public function test_record_user_history_keeps_only_the_most_recent_25() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        for ($fightid = 1; $fightid <= 26; $fightid++) {
            $this->make_resolved_fight($fightid);
        }
        for ($fightid = 1; $fightid <= 25; $fightid++) {
            FightSave::record_user_history($this->db, $fightid, 42);
        }

        FightSave::record_user_history($this->db, 26, 42); // the 26th

        $all = FightSave::load_all_by_type($this->db, 42, 'user');
        $this->assertCount(25, $all);
        // The oldest (fightid 1) was evicted; the newest (26) is present.
        $this->assertNull(FightSave::load($this->db, 1, 42, 'user'));
        $this->assertNotNull(FightSave::load($this->db, 26, 42, 'user'));
    }

    public function test_record_user_history_is_scoped_per_team() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        for ($fightid = 1; $fightid <= 25; $fightid++) {
            $this->make_resolved_fight($fightid);
        }
        $this->make_resolved_fight(100);
        for ($fightid = 1; $fightid <= 25; $fightid++) {
            FightSave::record_user_history($this->db, $fightid, 42);
        }

        FightSave::record_user_history($this->db, 100, 43); // a different team

        $this->assertCount(25, FightSave::load_all_by_type($this->db, 42, 'user'));
        $this->assertNotNull(FightSave::load($this->db, 1, 42, 'user')); // untouched
    }

    // --- retain() / unretain() ---

    public function test_retain_tags_the_fight() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);

        $result = FightSave::retain($this->db, 1, 42);

        $this->assertTrue($result);
        $this->assertNotNull(FightSave::load($this->db, 1, 42, 'retain'));
    }

    public function test_retain_refuses_an_eleventh_fight() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        for ($fightid = 1; $fightid <= 11; $fightid++) {
            $this->make_resolved_fight($fightid);
        }
        for ($fightid = 1; $fightid <= 10; $fightid++) {
            $this->assertTrue(FightSave::retain($this->db, $fightid, 42));
        }

        $result = FightSave::retain($this->db, 11, 42);

        $this->assertFalse($result);
        $this->assertNull(FightSave::load($this->db, 11, 42, 'retain'));
        $this->assertCount(10, FightSave::load_all_by_type($this->db, 42, 'retain'));
    }

    public function test_retain_allows_re_tagging_an_already_retained_fight_even_at_the_cap() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        for ($fightid = 1; $fightid <= 10; $fightid++) {
            $this->make_resolved_fight($fightid);
            FightSave::retain($this->db, $fightid, 42);
        }

        $result = FightSave::retain($this->db, 5, 42); // already retained

        $this->assertTrue($result);
    }

    public function test_unretain_removes_the_tag() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        FightSave::retain($this->db, 1, 42);

        FightSave::unretain($this->db, 1, 42);

        $this->assertNull(FightSave::load($this->db, 1, 42, 'retain'));
    }

    // --- consider_for_underdog() / consider_for_overkill() ---
    // Both share the same replace-if-bigger singleton logic, so
    // covering one thoroughly and the other lightly avoids duplicating
    // the same set of cases twice.

    public function test_consider_for_underdog_creates_the_first_record() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);

        FightSave::consider_for_underdog($this->db, 1, 42, npc_pxp: 100, player_party_pxp: 20);

        $saved = FightSave::load($this->db, 1, 42, 'underdog');
        $this->assertNotNull($saved);
        $this->assertSame(5.0, $saved->power_ratio); // 100/20
    }

    public function test_consider_for_underdog_replaces_a_smaller_gap() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        $this->make_resolved_fight(2);
        FightSave::consider_for_underdog($this->db, 1, 42, npc_pxp: 100, player_party_pxp: 20); // ratio 5

        FightSave::consider_for_underdog($this->db, 2, 42, npc_pxp: 100, player_party_pxp: 10); // ratio 10, bigger

        $this->assertNull(FightSave::load($this->db, 1, 42, 'underdog'));
        $saved = FightSave::load($this->db, 2, 42, 'underdog');
        $this->assertSame(10.0, $saved->power_ratio);
    }

    public function test_consider_for_underdog_ignores_a_smaller_or_equal_gap() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        $this->make_resolved_fight(2);
        FightSave::consider_for_underdog($this->db, 1, 42, npc_pxp: 100, player_party_pxp: 10); // ratio 10

        FightSave::consider_for_underdog($this->db, 2, 42, npc_pxp: 100, player_party_pxp: 20); // ratio 5, smaller

        $this->assertNotNull(FightSave::load($this->db, 1, 42, 'underdog'));
        $this->assertNull(FightSave::load($this->db, 2, 42, 'underdog'));
    }

    public function test_consider_for_underdog_scoped_per_team() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        $this->make_resolved_fight(2);
        FightSave::consider_for_underdog($this->db, 1, 42, npc_pxp: 100, player_party_pxp: 10);

        FightSave::consider_for_underdog($this->db, 2, 43, npc_pxp: 50, player_party_pxp: 5);

        $this->assertNotNull(FightSave::load($this->db, 1, 42, 'underdog'));
        $this->assertNotNull(FightSave::load($this->db, 2, 43, 'underdog'));
    }

    public function test_consider_for_overkill_creates_and_replaces_like_underdog() {
        $this->reset_tables(FightSave::class, FightSemaphore::class);
        $this->make_resolved_fight(1);
        $this->make_resolved_fight(2);
        FightSave::consider_for_overkill($this->db, 1, 42, npc_party_overkill: 40, player_party_pxp: 20); // ratio 2

        FightSave::consider_for_overkill($this->db, 2, 42, npc_party_overkill: 90, player_party_pxp: 10); // ratio 9, bigger

        $this->assertNull(FightSave::load($this->db, 1, 42, 'overkill'));
        $saved = FightSave::load($this->db, 2, 42, 'overkill');
        $this->assertSame(9.0, $saved->power_ratio);
    }
}
