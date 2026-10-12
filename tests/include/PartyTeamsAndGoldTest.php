<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once INCLUDE_GAME . 'party.php';

class PartyTeamsAndGoldTest extends DatabaseTestCase {

    public function test_get_teams_in_party_loads_each_team_fresh_from_the_database() {
        $this->reset_tables(TEAM::class, HERO::class);

        $party = new PARTY();
        $team_a = TEAM::create($this->db, 5001, 'Dragons', []);
        $team_b = TEAM::create($this->db, 5002, 'Phoenixes', []);
        $party->add_team($team_a);
        $party->add_team($team_b);

        $loaded = $party->get_teams_in_party($this->db);

        $this->assertCount(2, $loaded);
        $this->assertSame('Dragons', $loaded[$team_a->teamid]->name);
        $this->assertSame('Phoenixes', $loaded[$team_b->teamid]->name);
    }

    public function test_get_teams_in_party_reflects_changes_made_since_the_party_joined() {
        $this->reset_tables(TEAM::class, HERO::class);

        $party = new PARTY();
        $team = TEAM::create($this->db, 5003, 'Dragons', []);
        $party->add_team($team);

        // Change the team's gold directly in the database, independent of
        // the PARTY object -- get_teams_in_party() should reflect this,
        // confirming it's a genuine fresh load, not returning a cached or
        // stale copy from whenever add_team() ran.
        $team->gold = 250;
        $team->save($this->db);

        $loaded = $party->get_teams_in_party($this->db);

        $this->assertSame(250, $loaded[$team->teamid]->gold);
    }

    public function test_distribute_gold_splits_evenly_and_persists_to_each_team() {
        $this->reset_tables(TEAM::class, HERO::class);

        $party = new PARTY();
        $team_a = TEAM::create($this->db, 5004, 'Dragons', []);
        $team_b = TEAM::create($this->db, 5005, 'Phoenixes', []);
        $party->add_team($team_a);
        $party->add_team($team_b);

        $party->distribute_gold($this->db, 100);

        $reloaded_a = TEAM::load($this->db, $team_a->teamid);
        $reloaded_b = TEAM::load($this->db, $team_b->teamid);
        $this->assertSame(50, $reloaded_a->gold);
        $this->assertSame(50, $reloaded_b->gold);
    }

    public function test_distribute_gold_uses_floor_for_an_uneven_split() {
        $this->reset_tables(TEAM::class, HERO::class);

        $party = new PARTY();
        $team_a = TEAM::create($this->db, 5006, 'A', []);
        $team_b = TEAM::create($this->db, 5007, 'B', []);
        $team_c = TEAM::create($this->db, 5008, 'C', []);
        $party->add_team($team_a);
        $party->add_team($team_b);
        $party->add_team($team_c);

        // 100 / 3 = 33.33... -- each team should get exactly 33, not a
        // fractional amount, and nothing should attempt to distribute
        // the leftover remainder.
        $take = $party->distribute_gold($this->db, 100);

        $this->assertSame(33.0, $take);
        $reloaded_a = TEAM::load($this->db, $team_a->teamid);
        $this->assertSame(33, $reloaded_a->gold);
    }

    public function test_distribute_gold_adds_to_existing_gold_rather_than_replacing_it() {
        $this->reset_tables(TEAM::class, HERO::class);

        $party = new PARTY();
        $team = TEAM::create($this->db, 5009, 'Dragons', []);
        $team->gold = 200;
        $team->save($this->db);
        $party->add_team($team);

        $party->distribute_gold($this->db, 100);

        $reloaded = TEAM::load($this->db, $team->teamid);
        $this->assertSame(300, $reloaded->gold);
    }
}
