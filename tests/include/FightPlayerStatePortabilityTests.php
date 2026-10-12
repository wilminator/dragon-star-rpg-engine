<?php

/**
 * Covers FightPlayerState: save()/load() round-trips every field
 * (seen's bool<->'yes'/'no' conversion included), load_all_by_fightid()
 * returns every player regardless of order, and
 * find_active_fightid_for_player()'s join against FightSemaphore finds
 * the right fight and correctly excludes Resolved ones.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait. See FightPlayerStatePortabilitySqliteTest/
 * FightPlayerStatePortabilityMysqlTest/FightPlayerStatePortabilityPostgresTest.
 */
trait FightPlayerStatePortabilityTests {

    public function test_save_then_load_round_trips_every_field() {
        $this->reset_tables(FightPlayerState::class);

        $state = new FightPlayerState(1, 42, 7, 0, true, 3);
        $state->save($this->db);

        $reloaded = FightPlayerState::load($this->db, 1, 42);
        $this->assertSame(1, $reloaded->fightid);
        $this->assertSame(42, $reloaded->playerid);
        $this->assertSame(7, $reloaded->teamid);
        $this->assertSame(0, $reloaded->player_party);
        $this->assertTrue($reloaded->seen);
        $this->assertSame(3, $reloaded->sequence);
    }

    public function test_seen_false_round_trips_correctly() {
        $this->reset_tables(FightPlayerState::class);

        (new FightPlayerState(1, 42, 7, 0, false, 0))->save($this->db);

        $reloaded = FightPlayerState::load($this->db, 1, 42);
        $this->assertFalse($reloaded->seen);
    }

    public function test_load_returns_null_for_an_unknown_player() {
        $this->reset_tables(FightPlayerState::class);

        $this->assertNull(FightPlayerState::load($this->db, 1, 999));
    }

    public function test_saving_over_an_existing_row_updates_in_place() {
        $this->reset_tables(FightPlayerState::class);
        (new FightPlayerState(1, 42, 7, 0, false, 0))->save($this->db);

        (new FightPlayerState(1, 42, 7, 0, true, 1))->save($this->db);

        $reloaded = FightPlayerState::load($this->db, 1, 42);
        $this->assertTrue($reloaded->seen);
        $this->assertSame(1, $reloaded->sequence);
        $all = FightPlayerState::load_all_by_fightid($this->db, 1);
        $this->assertCount(1, $all);
    }

    public function test_load_all_by_fightid_returns_every_player() {
        $this->reset_tables(FightPlayerState::class);
        (new FightPlayerState(1, 42, 7, 0, true, 1))->save($this->db);
        (new FightPlayerState(1, 43, 8, 1, false, 0))->save($this->db);
        (new FightPlayerState(2, 44, 9, 0, true, 1))->save($this->db); // different fight

        $all = FightPlayerState::load_all_by_fightid($this->db, 1);

        $this->assertCount(2, $all);
        $this->assertEqualsCanonicalizing([42, 43], array_map(fn($s) => $s->playerid, $all));
    }

    public function test_find_active_fightid_for_player_finds_a_non_resolved_fight() {
        $this->reset_tables(FightPlayerState::class, FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        (new FightPlayerState(1, 42, 7, 0, true, 0))->save($this->db);

        $fightid = FightPlayerState::find_active_fightid_for_player($this->db, 42);

        $this->assertSame(1, $fightid);
    }

    public function test_find_active_fightid_for_player_excludes_resolved_fights() {
        $this->reset_tables(FightPlayerState::class, FightSemaphore::class);
        $semaphore = FightSemaphore::create($this->db, 1, 600);
        $claimed = FightSemaphore::claim($this->db, 1, 42, 30);
        $claimed->release($this->db, FightStatus::Resolved, 1, 600);
        (new FightPlayerState(1, 42, 7, 0, true, 0))->save($this->db);

        $fightid = FightPlayerState::find_active_fightid_for_player($this->db, 42);

        $this->assertNull($fightid);
    }

    public function test_find_active_fightid_for_player_returns_null_when_player_has_no_fight() {
        $this->reset_tables(FightPlayerState::class, FightSemaphore::class);

        $fightid = FightPlayerState::find_active_fightid_for_player($this->db, 999);

        $this->assertNull($fightid);
    }
}
