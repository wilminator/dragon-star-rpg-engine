<?php

require_once __DIR__ . '/../DatabaseTestCase.php';

class TeamEvictionTest extends DatabaseTestCase {

    /**
     * Creates a team, then deactivates it and overwrites deactivated_at
     * to a specific, known value -- giving deterministic control over
     * eviction ordering without relying on real wall-clock delays between
     * test setup steps, which would be slow and flaky.
     */
    private function make_inactive_team(int $playerid, string $name, DateTimeImmutable $deactivated_at): TEAM {
        $team = TEAM::create($this->db, $playerid, $name, []);
        $team->change_object_state($this->db, ObjectState::INACTIVE);
        $team->deactivated_at = $deactivated_at;
        $team->save($this->db);
        return $team;
    }

    public function test_create_succeeds_with_room_and_no_eviction_needed() {
        $this->reset_tables(TEAM::class, HERO::class);

        $playerid = 1001;
        TEAM::create($this->db, $playerid, 'Team A', []);
        TEAM::create($this->db, $playerid, 'Team B', []);

        // Two active teams, well under MAX_TEAMS (3) -- room exists, and
        // the eviction loop's own condition (total >= MAX_TEAMS) never
        // triggers, since total is only 2 at this point.
        $new_team = TEAM::create($this->db, $playerid, 'Team C', []);

        $all = TEAM::load_all_by_playerid($this->db, $playerid);
        $this->assertCount(3, $all);
        $this->assertSame('Team C', $new_team->name);
    }

    public function test_create_throws_when_active_teams_at_max() {
        $this->reset_tables(TEAM::class, HERO::class);

        $playerid = 1002;
        TEAM::create($this->db, $playerid, 'Team A', []);
        TEAM::create($this->db, $playerid, 'Team B', []);
        TEAM::create($this->db, $playerid, 'Team C', []);

        // Three active teams -- at MAX_TEAMS. has_room_for_new_team()
        // should reject this before the eviction loop is ever reached.
        $this->expectException(Exception::class);
        TEAM::create($this->db, $playerid, 'Team D', []);
    }

    public function test_create_evicts_the_one_inactive_team_when_at_total_max() {
        $this->reset_tables(TEAM::class, HERO::class);

        $playerid = 1003;
        TEAM::create($this->db, $playerid, 'Active A', []);
        TEAM::create($this->db, $playerid, 'Active B', []);
        $inactive = $this->make_inactive_team($playerid, 'Old Inactive', new DateTimeImmutable('-30 days'));

        // 2 active + 1 inactive = 3 total, at MAX_TEAMS. Room exists
        // (active count is only 2), so create() should succeed -- but the
        // total-count eviction loop should trigger once, removing the one
        // inactive team to make room.
        TEAM::create($this->db, $playerid, 'New Team', []);

        $all = TEAM::load_all_by_playerid($this->db, $playerid);
        $this->assertCount(3, $all);
        $this->assertArrayNotHasKey($inactive->teamid, $all);
    }

    public function test_create_evicts_multiple_inactive_teams_via_loop_not_just_once() {
        $this->reset_tables(TEAM::class, HERO::class);

        $playerid = 1004;
        TEAM::create($this->db, $playerid, 'Active A', []);
        $oldest = $this->make_inactive_team($playerid, 'Oldest', new DateTimeImmutable('-30 days'));
        $middle = $this->make_inactive_team($playerid, 'Middle', new DateTimeImmutable('-20 days'));
        $newest = $this->make_inactive_team($playerid, 'Newest', new DateTimeImmutable('-10 days'));

        // 1 active + 3 inactive = 4 total, already exceeding MAX_TEAMS.
        // Room exists (active count is only 1). The eviction loop needs to
        // run twice -- once wouldn't be enough to bring the total back
        // under the cap -- evicting the two oldest inactive teams and
        // leaving the newest one. This is the case that specifically
        // distinguishes a while loop from a single if: a naive "evict at
        // most once" implementation would leave the total at 4 after
        // evicting only one, then add a fifth on top of that.
        TEAM::create($this->db, $playerid, 'New Team', []);

        $all = TEAM::load_all_by_playerid($this->db, $playerid);
        $this->assertCount(3, $all);
        $this->assertArrayNotHasKey($oldest->teamid, $all);
        $this->assertArrayNotHasKey($middle->teamid, $all);
        $this->assertArrayHasKey($newest->teamid, $all);
    }

    public function test_has_room_for_new_team_ignores_inactive_teams() {
        $this->reset_tables(TEAM::class, HERO::class);

        $playerid = 1005;
        for ($i = 0; $i < 5; $i++) {
            $this->make_inactive_team($playerid, "Inactive $i", new DateTimeImmutable("-$i days"));
        }

        // Five inactive teams, well over MAX_TEAMS -- but zero active
        // ones. has_room_for_new_team() checks active count only, so this
        // should still report room, regardless of the large inactive total.
        $this->assertTrue(TEAM::has_room_for_new_team($this->db, $playerid));
    }
}
