<?php

require_once __DIR__ . '/../DatabaseTestCase.php';

class HeroTest extends DatabaseTestCase {

    /**
     * personalityid is set explicitly, not left at CHARACTER's own
     * default (null) -- HERO::__construct() requires it as a
     * non-nullable int. jobid is left unvalidated deliberately:
     * save_character() never checks it against the jobs table, only
     * make_leveled_hero() does, so this stays free of any job-system
     * dependency.
     */
    private function make_test_character(string $name): CHARACTER {
        $character = new CHARACTER();
        $character->charid = 0; // new-hero convention
        $character->name = $name;
        $character->jobid = 1;
        $character->personalityid = 1;
        $character->level = 1;
        $character->need = 100;
        $character->teamid = 0;
        return $character;
    }

    public function test_save_character_assigns_a_real_charid_to_a_new_character() {
        $this->reset_tables(HERO::class);
        $this->reset_hero_cache();

        $character = $this->make_test_character('Alice');
        $this->assertSame(0, $character->charid);

        HERO::save_character($this->db, $character);

        // charid=0 is the "new" convention going in; save_character()
        // should have replaced it with a real, auto-incremented ID --
        // never left at 0, never null (null is reserved for monsters).
        $this->assertNotSame(0, $character->charid);
        $this->assertNotNull($character->charid);
    }

    public function test_save_character_on_an_existing_charid_does_not_reassign_it() {
        $this->reset_tables(HERO::class);
        $this->reset_hero_cache();

        $character = $this->make_test_character('Alice');
        HERO::save_character($this->db, $character);
        $original_id = $character->charid;

        $character->level = 5;
        HERO::save_character($this->db, $character);

        // This is an update, not an insert -- the ID should be stable
        // across repeated saves of the same character.
        $this->assertSame($original_id, $character->charid);
    }

    public function test_save_character_throws_on_null_charid() {
        $character = $this->make_test_character('A Monster');
        $character->charid = null;

        // null is reserved for monster-spawned CHARACTERs, per
        // make_monster(). save_character() should refuse this outright
        // rather than silently treating it as some other case.
        $this->expectException(Exception::class);
        HERO::save_character($this->db, $character);
    }

    public function test_save_character_populates_the_cache() {
        $this->reset_tables(HERO::class);
        $this->reset_hero_cache();

        $character = $this->make_test_character('Alice');
        HERO::save_character($this->db, $character);

        // load_character() for the charid just saved should return the
        // *same* object instance that was passed to save_character() --
        // not a fresh, separately-built copy from the database. This is
        // the "same charid, different object" gap the cache exists to
        // close.
        $loaded = HERO::load_character($this->db, $character->charid);
        $this->assertSame($character, $loaded);
    }

    public function test_load_character_returns_the_same_instance_on_repeated_calls() {
        $this->reset_tables(HERO::class);
        $this->reset_hero_cache();

        $character = $this->make_test_character('Alice');
        HERO::save_character($this->db, $character);

        // Clear the cache so the next load_character() call is a
        // genuine fresh load from the database, not a hit from
        // save_character()'s own cache population -- this isolates
        // load_character()'s own caching behavior specifically.
        $this->reset_hero_cache();

        $first = HERO::load_character($this->db, $character->charid);
        $second = HERO::load_character($this->db, $character->charid);

        $this->assertSame($first, $second);
    }

    public function test_load_character_after_cache_clear_reflects_persisted_changes() {
        $this->reset_tables(HERO::class);
        $this->reset_hero_cache();

        $character = $this->make_test_character('Alice');
        HERO::save_character($this->db, $character);

        $character->level = 42;
        HERO::save_character($this->db, $character);

        // A genuinely fresh load (cache cleared first) should reflect
        // what was actually persisted, confirming the cache isn't
        // masking a save that didn't really take effect.
        $this->reset_hero_cache();
        $reloaded = HERO::load_character($this->db, $character->charid);

        $this->assertSame(42, $reloaded->level);
    }
}
