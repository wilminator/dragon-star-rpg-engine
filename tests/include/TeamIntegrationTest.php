<?php

require_once __DIR__ . '/../DatabaseTestCase.php';

class TeamIntegrationTest extends DatabaseTestCase {

    public function test_create_then_load_round_trips_correctly() {
        $this->reset_tables(TEAM::class, HERO::class);

        $playerid = 12345;
        $team = TEAM::create($this->db, $playerid, 'The Testers', []);

        // create() should have assigned a real, persisted teamid.
        $this->assertNotNull($team->teamid);

        $loaded = TEAM::load($this->db, $team->teamid);

        $this->assertSame($team->teamid, $loaded->teamid);
        $this->assertSame($playerid, $loaded->playerid);
        $this->assertSame('The Testers', $loaded->name);
        $this->assertSame(0, $loaded->gold);
        $this->assertSame(ObjectState::ACTIVE, $loaded->state);
        $this->assertSame([], $loaded->characters);
    }

    public function test_gold_changes_persist_across_save_and_load() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 12345, 'The Testers', []);
        $team->gold = 500;
        $team->save($this->db);

        $loaded = TEAM::load($this->db, $team->teamid);

        $this->assertSame(500, $loaded->gold);
    }

    // --- guid ---

    public function test_guid_is_generated_on_create_and_persists_through_load() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 12345, 'The Testers', []);
        $this->assertSame(32, strlen($team->guid));

        $loaded = TEAM::load($this->db, $team->teamid);

        $this->assertSame($team->guid, $loaded->guid);
    }

    public function test_get_team_from_guid_resolves_a_real_guid() {
        $this->reset_tables(TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 12345, 'The Testers', []);

        $found = TEAM::get_team_from_guid($this->db, $team->guid);

        $this->assertSame($team->teamid, $found->teamid);
    }

    public function test_get_team_from_guid_returns_null_for_an_unrecognized_guid() {
        $this->reset_tables(TEAM::class, HERO::class);

        $found = TEAM::get_team_from_guid($this->db, 'nonexistent');

        $this->assertNull($found);
    }
}
