<?php

require_once INCLUDE_GAME . 'party.php';

use PHPUnit\Framework\TestCase;

/**
 * remove_team() never touches the database -- same as add_team(), this
 * is a pure unit test.
 */
class PartyRemoveTeamTest extends TestCase {

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

    public function test_removing_a_team_removes_its_characters_from_the_party() {
        $party = new PARTY();
        $team_a = $this->make_fake_team(1, 'Dragons', 3);
        $team_b = $this->make_fake_team(2, 'Phoenixes', 2);
        $party->add_team($team_a);
        $party->add_team($team_b);

        $party->remove_team($team_a->teamid);

        $remaining = array_sum(array_map(fn($g) => $g->count(), $party->groups));
        $this->assertSame(2, $remaining);
    }

    public function test_removing_a_team_clears_it_from_the_teams_array() {
        $party = new PARTY();
        $team_a = $this->make_fake_team(1, 'Dragons', 3);
        $team_b = $this->make_fake_team(2, 'Phoenixes', 2);
        $party->add_team($team_a);
        $party->add_team($team_b);

        $party->remove_team($team_a->teamid);

        $this->assertArrayNotHasKey($team_a->teamid, $party->teams);
        $this->assertArrayHasKey($team_b->teamid, $party->teams);
    }

    public function test_removing_a_team_clears_it_from_team_names() {
        $party = new PARTY();
        $team_a = $this->make_fake_team(1, 'Dragons', 3);
        $team_b = $this->make_fake_team(2, 'Phoenixes', 2);
        $party->add_team($team_a);
        $party->add_team($team_b);

        $party->remove_team($team_a->teamid);

        $this->assertArrayNotHasKey($team_a->teamid, $party->team_names);
        $this->assertArrayHasKey($team_b->teamid, $party->team_names);
    }

    public function test_removing_a_team_leaves_the_other_teams_characters_untouched() {
        $party = new PARTY();
        $team_a = $this->make_fake_team(1, 'Dragons', 3);
        $team_b = $this->make_fake_team(2, 'Phoenixes', 2);
        $party->add_team($team_a);
        $party->add_team($team_b);

        $party->remove_team($team_a->teamid);

        // Confirms the removal was targeted -- team B's own characters
        // and charid list should be exactly what add_team() originally
        // recorded for it, untouched by team A's removal.
        $expected_charids = array_map(fn($c) => $c->charid, $team_b->characters);
        $this->assertSame($expected_charids, $party->teams[$team_b->teamid]);
    }

    public function test_removing_an_unknown_teamid_returns_true_and_changes_nothing() {
        $party = new PARTY();
        $team = $this->make_fake_team(1, 'Dragons', 2);
        $party->add_team($team);

        $result = $party->remove_team(9999);

        $this->assertTrue($result);
        $this->assertArrayHasKey($team->teamid, $party->teams);
        $remaining = array_sum(array_map(fn($g) => $g->count(), $party->groups));
        $this->assertSame(2, $remaining);
    }
}
