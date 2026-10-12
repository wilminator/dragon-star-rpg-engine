<?php

require_once __DIR__ . '/../DatabaseTestCase.php';

class TeamDeleteAndGameplayTest extends DatabaseTestCase {

    public function test_delete_removes_the_teams_own_outgoing_relations() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team_a = TEAM::create($this->db, 4001, 'Team A', []);
        $team_b = TEAM::create($this->db, 4002, 'Team B', []);
        $team_a->relations[$team_b->teamid] = RelationState::ALLY;
        $team_a->save($this->db);

        $team_a->delete($this->db);

        // team_relations has no public accessor of its own -- inspecting
        // it directly via Database's generic get_all_where() is the only
        // way to confirm the row is actually gone, not just that the
        // in-memory object looks right.
        $remaining = $this->db->get_all_where('team_relations', 'teamid', $team_a->teamid);
        $this->assertCount(0, $remaining);
    }

    public function test_delete_removes_other_teams_relations_pointing_at_it() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team_a = TEAM::create($this->db, 4003, 'Team A', []);
        $team_b = TEAM::create($this->db, 4004, 'Team B', []);
        // B points at A -- this is the "other direction" the delete_where
        // on otherteamid specifically exists for. Deleting A should also
        // clean up this row, even though it belongs to B, not A.
        $team_b->relations[$team_a->teamid] = RelationState::ENEMY;
        $team_b->save($this->db);

        $team_a->delete($this->db);

        $remaining = $this->db->get_all_where('team_relations', 'otherteamid', $team_a->teamid);
        $this->assertCount(0, $remaining);

        // Confirm B itself is untouched -- only the dangling relation row
        // should be gone, not B as a whole.
        $reloaded_b = TEAM::load($this->db, $team_b->teamid);
        $this->assertCount(0, $reloaded_b->relations);
    }

    public function test_load_for_gameplay_scrubs_relations_pointing_at_inactive_teams() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team_a = TEAM::create($this->db, 4005, 'Team A', []);
        $team_b = TEAM::create($this->db, 4006, 'Team B', []); // stays active
        $team_c = TEAM::create($this->db, 4007, 'Team C', []);
        $team_c->change_object_state($this->db, ObjectState::INACTIVE);

        $team_a->relations[$team_b->teamid] = RelationState::ALLY;
        $team_a->relations[$team_c->teamid] = RelationState::ENEMY;
        $team_a->save($this->db);

        $gameplay_team = TEAM::load_for_gameplay($this->db, $team_a->teamid);

        $this->assertCount(1, $gameplay_team->relations);
        $this->assertArrayHasKey($team_b->teamid, $gameplay_team->relations);
        $this->assertArrayNotHasKey($team_c->teamid, $gameplay_team->relations);
    }

    public function test_load_for_gameplay_does_not_persist_the_scrub() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team_a = TEAM::create($this->db, 4008, 'Team A', []);
        $team_c = TEAM::create($this->db, 4009, 'Team C', []);
        $team_c->change_object_state($this->db, ObjectState::INACTIVE);

        $team_a->relations[$team_c->teamid] = RelationState::ENEMY;
        $team_a->save($this->db);

        // Scrubbing happens on the returned object only -- this must not
        // write anything back to the database. A plain load() afterward
        // should still see the original, unscrubbed relation.
        TEAM::load_for_gameplay($this->db, $team_a->teamid);

        $reloaded = TEAM::load($this->db, $team_a->teamid);
        $this->assertCount(1, $reloaded->relations);
        $this->assertArrayHasKey($team_c->teamid, $reloaded->relations);
    }

    public function test_load_for_gameplay_with_no_relations_does_not_error() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 4010, 'Team A', []);

        $gameplay_team = TEAM::load_for_gameplay($this->db, $team->teamid);

        $this->assertSame([], $gameplay_team->relations);
    }
}
