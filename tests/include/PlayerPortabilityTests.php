<?php

/**
 * Covers PLAYER: guid_for() creates a guid lazily on first ask and
 * returns the same one on every subsequent ask, and
 * find_playerid_by_guid() is the correct inverse.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait.
 */
trait PlayerPortabilityTests {
    public function test_guid_for_creates_a_guid_on_first_ask() {
        $this->reset_tables(PLAYER::class);

        $guid = PLAYER::guid_for($this->db, 42);

        $this->assertSame(32, strlen($guid));
    }

    public function test_guid_for_returns_the_same_guid_on_repeated_asks() {
        $this->reset_tables(PLAYER::class);

        $first = PLAYER::guid_for($this->db, 42);
        $second = PLAYER::guid_for($this->db, 42);

        $this->assertSame($first, $second);
    }

    public function test_guid_for_is_distinct_per_player() {
        $this->reset_tables(PLAYER::class);

        $guid_a = PLAYER::guid_for($this->db, 42);
        $guid_b = PLAYER::guid_for($this->db, 43);

        $this->assertNotSame($guid_a, $guid_b);
    }

    public function test_find_playerid_by_guid_is_the_correct_inverse() {
        $this->reset_tables(PLAYER::class);
        $guid = PLAYER::guid_for($this->db, 42);

        $found = PLAYER::find_playerid_by_guid($this->db, $guid);

        $this->assertSame(42, $found);
    }

    public function test_find_playerid_by_guid_returns_null_for_an_unknown_guid() {
        $this->reset_tables(PLAYER::class);

        $found = PLAYER::find_playerid_by_guid($this->db, 'nonexistent');

        $this->assertNull($found);
    }
}
