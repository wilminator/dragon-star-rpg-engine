<?php

require_once INCLUDE_GAME . 'party.php';

use PHPUnit\Framework\TestCase;

/**
 * add_team() never touches the database -- it's pure, in-memory logic
 * over already-constructed TEAM/CHARACTER objects. This is a pure unit
 * test, not an integration test, for that reason.
 */
class PartyAddTeamTest extends TestCase {

    /**
     * add_hero() only ever reads ->name from a character; add_team()
     * itself only reads ->charid. A minimal stand-in is sufficient --
     * no real CHARACTER needed.
     */
    private function make_fake_character(int $charid, string $name): CHARACTER {
        // Reflection bypasses CHARACTER's constructor -- it transitively
        // calls ITEM::get() (via calculate_pxp()), which reads a
        // file-based cache not available in this context. Only
        // charid/name are set here, since those are the only properties
        // add_team()/add_hero()/insert_character() actually read.
        $reflection = new ReflectionClass(CHARACTER::class);
        $character = $reflection->newInstanceWithoutConstructor();
        $character->charid = $charid;
        $character->name = $name;
        return $character;
    }

    /**
     * Builds a TEAM via reflection, bypassing its private constructor
     * and TEAM::MAX_COUNT entirely -- deliberately, since one test here
     * needs an oversized team to exercise PARTY's own capacity rejection,
     * independent of what a real TEAM would ever allow.
     */
    private function make_fake_team(int $teamid, string $name, int $character_count): TEAM {
        $reflection = new ReflectionClass(TEAM::class);
        $team = $reflection->newInstanceWithoutConstructor();
        $team->teamid = $teamid;
        $team->name = $name;
        $team->characters = array_map(
            fn($i) => $this->make_fake_character($teamid * 100 + $i, "{$name} Member {$i}"),
            range(0, $character_count - 1)
        );
        return $team;
    }

    public function test_adding_the_first_team_names_the_party_after_it() {
        $party = new PARTY();
        $team = $this->make_fake_team(1, 'Dragons', 2);

        $party->add_team($team);

        $this->assertSame('Dragons', $party->name);
    }

    public function test_adding_a_second_team_renames_the_party_to_an_alliance() {
        $party = new PARTY();
        $team_a = $this->make_fake_team(1, 'Dragons', 2);
        $team_b = $this->make_fake_team(2, 'Phoenixes', 2);

        $party->add_team($team_a);
        $party->add_team($team_b);

        $this->assertSame('The Dragons alliance', $party->name);
    }

    public function test_adding_a_third_team_does_not_rename_again() {
        $party = new PARTY();
        $team_a = $this->make_fake_team(1, 'Dragons', 2);
        $team_b = $this->make_fake_team(2, 'Phoenixes', 2);
        $team_c = $this->make_fake_team(3, 'Griffins', 2);

        $party->add_team($team_a);
        $party->add_team($team_b);
        $party->add_team($team_c);

        // The rename logic is specifically gated on count($this->teams)
        // == 1 -- exactly one existing team, not "at least one" -- so a
        // third team joining should leave the alliance name from the
        // second team untouched.
        $this->assertSame('The Dragons alliance', $party->name);
    }

    public function test_adding_a_team_records_its_characters_charids() {
        $party = new PARTY();
        $team = $this->make_fake_team(1, 'Dragons', 3);

        $party->add_team($team);

        $expected_charids = array_map(fn($c) => $c->charid, $team->characters);
        $this->assertSame($expected_charids, $party->teams[$team->teamid]);
    }

    public function test_adding_a_team_that_would_exceed_party_capacity_is_rejected() {
        $party = new PARTY();
        // GROUP::MAX_COUNT (5) * PARTY_MAX_COUNT (3) = 15 total capacity.
        // A single fake team of 20 -- something no real TEAM could ever
        // be, since TEAM::MAX_COUNT caps it well below this -- exercises
        // PARTY's own rejection path in isolation.
        $team = $this->make_fake_team(1, 'Too Many', 20);

        $result = $party->add_team($team);

        $this->assertTrue($result);
        $this->assertSame(0, $party->count());
        $this->assertArrayNotHasKey($team->teamid, $party->teams);
    }

    public function test_a_teams_characters_fit_within_a_single_groups_capacity_dont_overflow() {
        $party = new PARTY();
        // 4 characters, GROUP::MAX_COUNT is 5 -- all four should land in
        // one group, not spill into a second.
        $team = $this->make_fake_team(1, 'Dragons', 4);

        $party->add_team($team);

        $this->assertSame(1, $party->count());
        $this->assertSame(4, $party->groups[0]->count());
    }
}
