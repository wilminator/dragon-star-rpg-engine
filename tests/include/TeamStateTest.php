<?php

require_once __DIR__ . '/../DatabaseTestCase.php';

class TeamStateTest extends DatabaseTestCase {

    public function test_deactivating_an_active_team_stamps_deactivated_at() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 2001, 'Team A', []);
        $this->assertNull($team->deactivated_at);

        $before = new DateTimeImmutable();
        $team->change_object_state($this->db, ObjectState::INACTIVE);
        $after = new DateTimeImmutable();

        $this->assertNotNull($team->deactivated_at);
        $this->assertGreaterThanOrEqual($before, $team->deactivated_at);
        $this->assertLessThanOrEqual($after, $team->deactivated_at);

        // Confirm this actually persisted, not just held correctly in
        // memory -- a fresh load should see the same stamp.
        $reloaded = TEAM::load($this->db, $team->teamid);
        $this->assertEquals($team->deactivated_at->format('Y-m-d H:i:s'), $reloaded->deactivated_at->format('Y-m-d H:i:s'));
    }

    public function test_redeactivating_an_already_inactive_team_does_not_restamp() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 2002, 'Team A', []);
        $team->change_object_state($this->db, ObjectState::INACTIVE);

        // Overwrite to a known, distinctly-old value -- if the guard
        // (state !== INACTIVE) weren't there, calling INACTIVE again
        // below would stamp a fresh "now" over this and this assertion
        // would fail.
        $original = new DateTimeImmutable('-30 days');
        $team->deactivated_at = $original;
        $team->save($this->db);

        $team->change_object_state($this->db, ObjectState::INACTIVE);

        $this->assertEquals($original, $team->deactivated_at);

        $reloaded = TEAM::load($this->db, $team->teamid);
        $this->assertEquals($original->format('Y-m-d H:i:s'), $reloaded->deactivated_at->format('Y-m-d H:i:s'));
    }

    public function test_reactivating_does_not_touch_deactivated_at() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 2003, 'Team A', []);
        $team->change_object_state($this->db, ObjectState::INACTIVE);

        $original = new DateTimeImmutable('-30 days');
        $team->deactivated_at = $original;
        $team->save($this->db);

        // Reactivating is not a transition into INACTIVE, so the
        // stamping logic should never fire here at all -- the old value
        // should simply be left in place, not cleared or updated.
        $team->change_object_state($this->db, ObjectState::ACTIVE);

        $this->assertEquals($original, $team->deactivated_at);

        $reloaded = TEAM::load($this->db, $team->teamid);
        $this->assertEquals($original->format('Y-m-d H:i:s'), $reloaded->deactivated_at->format('Y-m-d H:i:s'));
        $this->assertSame(ObjectState::ACTIVE, $reloaded->state);
    }

    public function test_redeactivating_after_reactivation_stamps_the_newer_time() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 2004, 'Team A', []);
        $team->change_object_state($this->db, ObjectState::INACTIVE);

        $original = new DateTimeImmutable('-30 days');
        $team->deactivated_at = $original;
        $team->save($this->db);

        // Reactivate, then deactivate again -- this *is* a genuine new
        // transition into INACTIVE (the team was ACTIVE immediately
        // before this call), so it should get a fresh, newer timestamp,
        // not silently keep the stale one from 30 days ago.
        $team->change_object_state($this->db, ObjectState::ACTIVE);
        $team->change_object_state($this->db, ObjectState::INACTIVE);

        $this->assertNotEquals($original, $team->deactivated_at);
        $this->assertGreaterThan($original, $team->deactivated_at);

        $reloaded = TEAM::load($this->db, $team->teamid);
        $this->assertEquals($team->deactivated_at->format('Y-m-d H:i:s'), $reloaded->deactivated_at->format('Y-m-d H:i:s'));
    }
}
