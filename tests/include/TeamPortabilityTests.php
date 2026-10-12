<?php

/**
 * Covers the cross-database portability work on TEAM/HERO: the DDL and
 * upsert-statement changes needed so save()/load() work identically on
 * MySQL, Postgres, and SQLite alike.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait -- each provides its own $this->db (via its base
 * class's setUp()) and inherits reset_tables()/reset_hero_cache() from
 * that same base class. See TeamPortabilitySqliteTest/
 * TeamPortabilityMysqlTest/TeamPortabilityPostgresTest for the three
 * concrete classes.
 */
trait TeamPortabilityTests {

    public function test_create_then_load_round_trips_correctly() {
        $this->reset_tables(TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 5001, 'Dragons', []);

        $this->assertNotNull($team->teamid);

        $reloaded = TEAM::load($this->db, $team->teamid);

        $this->assertSame('Dragons', $reloaded->name);
        $this->assertSame(5001, $reloaded->playerid);
        $this->assertSame(ObjectState::ACTIVE, $reloaded->state);
    }

    public function test_successive_creates_get_distinct_ids() {
        $this->reset_tables(TEAM::class, HERO::class);
        $team1 = TEAM::create($this->db, 5001, 'Dragons', []);
        $team2 = TEAM::create($this->db, 5002, 'Phoenixes', []);

        $this->assertNotSame($team1->teamid, $team2->teamid);
    }

    public function test_change_object_state_persists() {
        $this->reset_tables(TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 5001, 'Dragons', []);

        $team->change_object_state($this->db, ObjectState::INACTIVE);
        $reloaded = TEAM::load($this->db, $team->teamid);

        $this->assertSame(ObjectState::INACTIVE, $reloaded->state);
    }

    public function test_saving_an_existing_team_updates_in_place_not_a_duplicate() {
        $this->reset_tables(TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 5001, 'Dragons', []);
        $original_id = $team->teamid;

        $team->gold = 500;
        $team->save($this->db);

        $reloaded = TEAM::load($this->db, $original_id);
        $this->assertSame(500, $reloaded->gold);
        $this->assertSame($original_id, $reloaded->teamid);
    }
}
