<?php

use PHPUnit\Framework\TestCase;

/**
 * A minimal stand-in for CHARACTER -- charid_in_team() only ever reads
 * ->charid, so a full CHARACTER (which needs base stats, equipment, etc.
 * to construct) is unnecessary overhead for this test.
 */
class FakeCharacter {
    public function __construct(public int $charid) {}
}

class TeamTest extends TestCase {

    /**
     * Builds a TEAM with the given characters, without touching the
     * database. TEAM's constructor is private (by design -- see team.php),
     * so this bypasses it via reflection rather than going through
     * create()/load(), both of which require a live Database connection.
     */
    private function make_team_with_characters(array $characters): TEAM {
        $reflection = new ReflectionClass(TEAM::class);
        $team = $reflection->newInstanceWithoutConstructor();
        $team->characters = $characters;
        return $team;
    }

    public function test_charid_in_team_finds_character_at_first_position() {
        $team = $this->make_team_with_characters([
            new FakeCharacter(101),
            new FakeCharacter(102),
        ]);

        $this->assertSame(0, $team->charid_in_team(101));
    }

    public function test_charid_in_team_finds_character_at_later_position() {
        $team = $this->make_team_with_characters([
            new FakeCharacter(101),
            new FakeCharacter(102),
            new FakeCharacter(103),
        ]);

        $this->assertSame(2, $team->charid_in_team(103));
    }

    /**
     * The critical case. charid_in_team() is declared `int|bool`
     * specifically so a not-found result (false) can never be confused
     * with "found at index 0" (int 0) -- the two must remain distinct
     * types, not just distinct values. assertSame (not assertEquals) is
     * required here: assertEquals treats 0 and false as equal under PHP's
     * loose comparison rules and would silently pass even if the method
     * were mistakenly declared to return `?int`, coercing false to 0.
     */
    public function test_charid_in_team_returns_false_when_not_found() {
        $team = $this->make_team_with_characters([
            new FakeCharacter(101),
            new FakeCharacter(102),
        ]);

        $this->assertSame(false, $team->charid_in_team(999));
    }
}
