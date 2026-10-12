<?php

/**
 * Covers FightSemaphore: the guarded-UPDATE concurrency mechanism this
 * class exists to provide (claim()/extend()/release(), including both
 * ways a claim attempt can legitimately fail or take over an
 * abandoned lock), plus create()/load() and tighten_timeout()'s
 * only-ever-shrink semantics.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait. See FightSemaphorePortabilitySqliteTest/
 * FightSemaphorePortabilityMysqlTest/FightSemaphorePortabilityPostgresTest.
 */
trait FightSemaphorePortabilityTests {

    public function test_create_sets_waiting_for_input_zero_sequence_and_a_future_timeout() {
        $this->reset_tables(FightSemaphore::class);

        $semaphore = FightSemaphore::create($this->db, 1, 600);

        $this->assertSame(FightStatus::WaitingForInput, $semaphore->state);
        $this->assertSame(0, $semaphore->sequence);
        $this->assertGreaterThan(time(), $semaphore->timeout);
        $this->assertNull($semaphore->lock_token);
    }

    public function test_load_round_trips_what_create_stored() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);

        $reloaded = FightSemaphore::load($this->db, 1);

        $this->assertSame(1, $reloaded->fightid);
        $this->assertSame(FightStatus::WaitingForInput, $reloaded->state);
        $this->assertSame(0, $reloaded->sequence);
    }

    public function test_load_returns_null_for_an_unknown_fightid() {
        $this->reset_tables(FightSemaphore::class);

        $this->assertNull(FightSemaphore::load($this->db, 999));
    }

    public function test_claim_succeeds_when_waiting_for_input() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);

        $claimed = FightSemaphore::claim($this->db, 1, 42, 30);

        $this->assertNotNull($claimed);
        $this->assertSame(FightStatus::Processing, $claimed->state);
        $this->assertSame(42, $claimed->locked_by_playerid);
        $this->assertNotNull($claimed->lock_token);
        $this->assertGreaterThan(time(), $claimed->lock_expires_at);
    }

    public function test_claim_fails_while_another_live_claim_is_held() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $first = FightSemaphore::claim($this->db, 1, 42, 30);

        $second = FightSemaphore::claim($this->db, 1, 99, 30);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_claim_succeeds_as_a_takeover_once_the_prior_lock_has_expired() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $first = FightSemaphore::claim($this->db, 1, 42, 30);
        // Simulate the first claim's lock having already expired --
        // directly, rather than sleeping in a test -- by backdating
        // lock_expires_at past now().
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET lock_expires_at = ? WHERE fightid = 1");
        $stmt->execute([time() - 1]);

        $second = FightSemaphore::claim($this->db, 1, 99, 30);

        $this->assertNotNull($second);
        $this->assertSame(99, $second->locked_by_playerid);
        $this->assertNotSame($first->lock_token, $second->lock_token);
    }

    public function test_extend_succeeds_with_the_correct_token_and_pushes_expiry_forward() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $claimed = FightSemaphore::claim($this->db, 1, 42, 30);
        $original_expiry = $claimed->lock_expires_at;

        $result = $claimed->extend($this->db, 300);

        $this->assertTrue($result);
        $this->assertGreaterThan($original_expiry, $claimed->lock_expires_at);
    }

    public function test_extend_fails_once_the_lock_was_taken_over_by_someone_else() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $first = FightSemaphore::claim($this->db, 1, 42, 30);
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET lock_expires_at = ? WHERE fightid = 1");
        $stmt->execute([time() - 1]);
        FightSemaphore::claim($this->db, 1, 99, 30); // takes over

        $result = $first->extend($this->db, 300);

        $this->assertFalse($result);
    }

    public function test_release_succeeds_with_the_correct_token_and_clears_the_lock() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $claimed = FightSemaphore::claim($this->db, 1, 42, 30);

        $result = $claimed->release($this->db, FightStatus::WaitingForInput, 1, 600);

        $this->assertTrue($result);
        $this->assertSame(FightStatus::WaitingForInput, $claimed->state);
        $this->assertSame(1, $claimed->sequence);
        $this->assertNull($claimed->lock_token);
        $this->assertNull($claimed->locked_by_playerid);
        $this->assertNull($claimed->lock_expires_at);

        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame(FightStatus::WaitingForInput, $reloaded->state);
        $this->assertSame(1, $reloaded->sequence);
        $this->assertNull($reloaded->lock_token);
    }

    public function test_release_can_transition_to_resolved() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $claimed = FightSemaphore::claim($this->db, 1, 42, 30);

        $claimed->release($this->db, FightStatus::Resolved, 1, 600);

        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame(FightStatus::Resolved, $reloaded->state);
    }

    public function test_release_fails_once_the_lock_was_taken_over_by_someone_else() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $first = FightSemaphore::claim($this->db, 1, 42, 30);
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET lock_expires_at = ? WHERE fightid = 1");
        $stmt->execute([time() - 1]);
        FightSemaphore::claim($this->db, 1, 99, 30); // takes over

        $result = $first->release($this->db, FightStatus::WaitingForInput, 1, 600);

        $this->assertFalse($result);
        // The takeover's own claim must survive untouched.
        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame(FightStatus::Processing, $reloaded->state);
        $this->assertSame(99, $reloaded->locked_by_playerid);
    }

    public function test_tighten_timeout_shrinks_a_later_value() {
        $this->reset_tables(FightSemaphore::class);
        $far_future = time() + 10000;
        $semaphore = FightSemaphore::create($this->db, 1, 600);
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET timeout = ? WHERE fightid = 1");
        $stmt->execute([$far_future]);

        $sooner = time() + 100;
        FightSemaphore::tighten_timeout($this->db, 1, $sooner);

        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame($sooner, $reloaded->timeout);
    }

    public function test_tighten_timeout_never_extends_an_earlier_value() {
        $this->reset_tables(FightSemaphore::class);
        $sooner = time() + 100;
        FightSemaphore::create($this->db, 1, 600);
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET timeout = ? WHERE fightid = 1");
        $stmt->execute([$sooner]);

        $later = time() + 10000;
        FightSemaphore::tighten_timeout($this->db, 1, $later);

        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame($sooner, $reloaded->timeout);
    }

    public function test_is_timed_out_reflects_whether_timeout_has_passed() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $not_yet = FightSemaphore::load($this->db, 1);
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET timeout = ? WHERE fightid = 1");
        $stmt->execute([time() - 1]);
        $already = FightSemaphore::load($this->db, 1);

        $this->assertFalse($not_yet->is_timed_out());
        $this->assertTrue($already->is_timed_out());
    }

    public function test_purge_resolved_removes_only_resolved_rows() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        $claimed = FightSemaphore::claim($this->db, 1, 42, 30);
        $claimed->release($this->db, FightStatus::Resolved, 1, 600);
        FightSemaphore::create($this->db, 2, 600); // still active

        $removed = FightSemaphore::purge_resolved($this->db);

        $this->assertSame(1, $removed);
        $this->assertNull(FightSemaphore::load($this->db, 1));
        $this->assertNotNull(FightSemaphore::load($this->db, 2));
    }

    public function test_load_all_from_database_returns_every_row_keyed_by_fightid() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);
        FightSemaphore::create($this->db, 2, 900);

        $all = FightSemaphore::load_all_from_database($this->db);

        $this->assertCount(2, $all);
        $this->assertArrayHasKey(1, $all);
        $this->assertArrayHasKey(2, $all);
        $this->assertSame(1, $all[1]->fightid);
        $this->assertSame(2, $all[2]->fightid);
    }

    // --- save() ---
    // Deliberately not exercised via create()/claim()/release() here:
    // this is the plain upsert built specifically for restoring an
    // already-complete, previously-exported row during a database
    // import, so these tests construct one directly the way an
    // unserialize()'d import row would arrive.

    public function test_save_inserts_a_new_row_with_every_field() {
        $this->reset_tables(FightSemaphore::class);
        $semaphore = new FightSemaphore(1, FightStatus::Resolved, 4, 1234567890, null, null, null);

        $semaphore->save($this->db);

        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame(FightStatus::Resolved, $reloaded->state);
        $this->assertSame(4, $reloaded->sequence);
        $this->assertSame(1234567890, $reloaded->timeout);
        $this->assertNull($reloaded->lock_token);
    }

    public function test_save_updates_an_existing_row_in_place_not_a_duplicate() {
        $this->reset_tables(FightSemaphore::class);
        FightSemaphore::create($this->db, 1, 600);

        (new FightSemaphore(1, FightStatus::Resolved, 9, 999))->save($this->db);

        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame(FightStatus::Resolved, $reloaded->state);
        $this->assertSame(9, $reloaded->sequence);
        $all = FightSemaphore::load_all_from_database($this->db);
        $this->assertCount(1, $all);
    }

    public function test_save_round_trips_lock_fields_too() {
        $this->reset_tables(FightSemaphore::class);
        $semaphore = new FightSemaphore(1, FightStatus::Processing, 2, 555, 'sometoken1234567890', 42, 999999);

        $semaphore->save($this->db);

        $reloaded = FightSemaphore::load($this->db, 1);
        $this->assertSame('sometoken1234567890', $reloaded->lock_token);
        $this->assertSame(42, $reloaded->locked_by_playerid);
        $this->assertSame(999999, $reloaded->lock_expires_at);
    }
}
