<?php

require_once __DIR__ . '/../DatabaseTestCase.php';

class TeamLoadRelatedTest extends DatabaseTestCase {

    /**
     * Builds a minimal, valid CHARACTER directly, bypassing
     * make_leveled_hero()'s job lookup entirely -- that lookup is what
     * would otherwise require a real, seeded JOB row to exist in the
     * test database. save_character()/load_all_by_teamid() never
     * validate jobid against the jobs table themselves; only
     * make_leveled_hero() does. So a character built this way is fully
     * valid for testing TEAM's own persistence/relation logic, without
     * pulling the job system in as an incidental dependency.
     *
     * personalityid is set explicitly, not left at CHARACTER's own
     * default (null) -- HERO::__construct() requires it as a
     * non-nullable int, a real gap between the two classes that this
     * direct-construction path has to account for.
     */
    private function make_test_character(string $name, int $teamid): CHARACTER {
        $character = new CHARACTER();
        $character->charid = 0; // new-hero convention, per save_character()
        $character->name = $name;
        $character->jobid = 1;
        $character->personalityid = 1;
        $character->level = 1;
        $character->need = 100;
        $character->teamid = $teamid;
        return $character;
    }

    public function test_load_populates_characters() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team = TEAM::create($this->db, 3001, 'Team A', []);
        $char1 = $this->make_test_character('Alice', $team->teamid);
        $char2 = $this->make_test_character('Bob', $team->teamid);
        HERO::save_character($this->db, $char1);
        HERO::save_character($this->db, $char2);

        $loaded = TEAM::load($this->db, $team->teamid);

        $this->assertCount(2, $loaded->characters);
        $names = array_map(fn($c) => $c->name, $loaded->characters);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function test_load_populates_relations() {
        $this->reset_tables(TEAM::class);

        $team_a = TEAM::create($this->db, 3002, 'Team A', []);
        $team_b = TEAM::create($this->db, 3003, 'Team B', []);

        $team_a->relations[$team_b->teamid] = RelationState::ALLY;
        $team_a->save($this->db);

        $loaded = TEAM::load($this->db, $team_a->teamid);

        $this->assertCount(1, $loaded->relations);
        $this->assertSame(RelationState::ALLY, $loaded->relations[$team_b->teamid]);
    }

    public function test_load_all_from_database_also_populates_characters_and_relations() {
        $this->reset_tables(TEAM::class, HERO::class);

        $team_a = TEAM::create($this->db, 3004, 'Team A', []);
        $team_b = TEAM::create($this->db, 3005, 'Team B', []);
        $char = $this->make_test_character('Alice', $team_a->teamid);
        HERO::save_character($this->db, $char);
        $team_a->relations[$team_b->teamid] = RelationState::ENEMY;
        $team_a->save($this->db);

        // This is the trait-provided, generic bulk-load path -- not
        // TEAM's own load(). Confirming it also goes through
        // load_related() is the whole point of this test: before that
        // hook existed, this path built teams from row data alone,
        // leaving characters/relations empty regardless of what actually
        // existed in the database.
        $all = TEAM::load_all_from_database($this->db, true);

        $this->assertArrayHasKey($team_a->teamid, $all);
        $this->assertCount(1, $all[$team_a->teamid]->characters);
        $this->assertSame('Alice', $all[$team_a->teamid]->characters[0]->name);
        $this->assertCount(1, $all[$team_a->teamid]->relations);
        $this->assertSame(RelationState::ENEMY, $all[$team_a->teamid]->relations[$team_b->teamid]);
    }

    public function test_load_all_by_playerid_also_populates_characters_and_relations() {
        $this->reset_tables(TEAM::class, HERO::class);

        $playerid = 3006;
        $team_a = TEAM::create($this->db, $playerid, 'Team A', []);
        $team_b = TEAM::create($this->db, 9999, 'Team B', []); // different player
        $char = $this->make_test_character('Alice', $team_a->teamid);
        HERO::save_character($this->db, $char);
        $team_a->relations[$team_b->teamid] = RelationState::NEUTRAL;
        $team_a->save($this->db);

        $mine = TEAM::load_all_by_playerid($this->db, $playerid);

        $this->assertCount(1, $mine);
        $this->assertCount(1, $mine[$team_a->teamid]->characters);
        $this->assertSame('Alice', $mine[$team_a->teamid]->characters[0]->name);
        $this->assertCount(1, $mine[$team_a->teamid]->relations);
        $this->assertSame(RelationState::NEUTRAL, $mine[$team_a->teamid]->relations[$team_b->teamid]);
    }

    // --- one CHARACTER object per hero ---
    // Regression: TEAM::load() used to build its own CHARACTER copies,
    // separate from the ones HERO::load_character() hands to the session
    // PARTY. store.php added a purchase to the party's copy, then saved
    // the team's copy with store_team() -- writing the old inventory
    // back. The gold was still deducted, so the player paid for nothing.

    public function test_load_shares_character_objects_with_load_character() {
        $this->reset_tables(TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 3101, 'Team A', []);
        $char = $this->make_test_character('Alice', $team->teamid);
        HERO::save_character($this->db, $char);
        $this->reset_hero_cache();

        $from_party = HERO::load_character($this->db, $char->charid);
        $loaded = TEAM::load($this->db, $team->teamid);

        $this->assertSame($from_party, $loaded->characters[0]);
    }

    public function test_store_team_persists_a_change_made_through_the_party_copy() {
        // The exact store.php sequence: modify the character obtained
        // from the session party, then save via the separately loaded team.
        $this->reset_tables(TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 3102, 'Team A', []);
        $char = $this->make_test_character('Alice', $team->teamid);
        HERO::save_character($this->db, $char);
        $this->reset_hero_cache();

        $party_character = HERO::load_character($this->db, $char->charid);
        $loaded_team = TEAM::load($this->db, $team->teamid);
        $party_character->add_item(1, 3);
        $loaded_team->store_team($this->db);

        // Genuinely re-read from the database, not the per-request cache.
        $this->reset_hero_cache();
        $reloaded = TEAM::load($this->db, $team->teamid);
        $this->assertEquals([0 => ['item' => 1, 'qty' => 3]], $reloaded->characters[0]->inventory);
    }
}
