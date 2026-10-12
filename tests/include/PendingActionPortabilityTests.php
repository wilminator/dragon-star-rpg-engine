<?php

/**
 * Covers PendingAction: save()/load() round-trips every field, upsert
 * overwrites in place rather than duplicating (no sequence column, by
 * design -- the latest submission simply replaces the previous one),
 * and load_all_by_fightid() returns every character with a pending
 * action for a fight.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait. See PendingActionPortabilitySqliteTest/
 * PendingActionPortabilityMysqlTest/PendingActionPortabilityPostgresTest.
 */
trait PendingActionPortabilityTests {

    public function test_save_then_load_round_trips_every_field() {
        $this->reset_tables(PendingAction::class);

        $action = new PendingAction(1, 0, 1, 2, 555, 4, 1, [1, 0, 3]);
        $action->save($this->db);

        $reloaded = PendingAction::load($this->db, 1, 0, 1, 2);
        $this->assertSame(1, $reloaded->fightid);
        $this->assertSame(0, $reloaded->party_index);
        $this->assertSame(1, $reloaded->group_index);
        $this->assertSame(2, $reloaded->char_index);
        $this->assertSame(555, $reloaded->playerid);
        $this->assertSame(4, $reloaded->command);
        $this->assertSame(1, $reloaded->using);
        $this->assertSame([1, 0, 3], $reloaded->target);
    }

    public function test_load_returns_null_for_an_unknown_character() {
        $this->reset_tables(PendingAction::class);

        $this->assertNull(PendingAction::load($this->db, 1, 0, 0, 0));
    }

    public function test_saving_over_an_existing_row_updates_in_place_not_a_duplicate() {
        $this->reset_tables(PendingAction::class);
        (new PendingAction(1, 0, 0, 0, 555, 4, 1, [1, 0, 0]))->save($this->db);

        (new PendingAction(1, 0, 0, 0, 555, 9, 0, [0, 0, 1]))->save($this->db);

        $reloaded = PendingAction::load($this->db, 1, 0, 0, 0);
        $this->assertSame(9, $reloaded->command);
        $this->assertSame([0, 0, 1], $reloaded->target);
        $all = PendingAction::load_all_by_fightid($this->db, 1);
        $this->assertCount(1, $all);
    }

    public function test_load_all_by_fightid_returns_every_character() {
        $this->reset_tables(PendingAction::class);
        (new PendingAction(1, 0, 0, 0, 555, 4, 1, [1, 0, 0]))->save($this->db);
        (new PendingAction(1, 0, 0, 1, 556, 5, 0, [1, 0, 0]))->save($this->db);
        (new PendingAction(2, 0, 0, 0, 557, 4, 1, [1, 0, 0]))->save($this->db); // different fight

        $all = PendingAction::load_all_by_fightid($this->db, 1);

        $this->assertCount(2, $all);
        $this->assertEqualsCanonicalizing([0, 1], array_map(fn($a) => $a->char_index, $all));
    }

    public function test_purge_resolved_removes_only_rows_for_resolved_fights() {
        $this->reset_tables(PendingAction::class, FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $claimed = FightSemaphore::claim($this->db, 1, 42, 30);
        $claimed->release($this->db, FightStatus::Resolved, 1, 600); // fight 1: Resolved
        FightSemaphore::create($this->db, 2, 600); // fight 2: still active
        (new PendingAction(1, 0, 0, 0, 555, 4, 1, [1, 0, 0]))->save($this->db);
        (new PendingAction(2, 0, 0, 0, 556, 4, 1, [1, 0, 0]))->save($this->db);

        $removed = PendingAction::purge_resolved($this->db);

        $this->assertSame(1, $removed);
        $this->assertNull(PendingAction::load($this->db, 1, 0, 0, 0));
        $this->assertNotNull(PendingAction::load($this->db, 2, 0, 0, 0));
    }
}
