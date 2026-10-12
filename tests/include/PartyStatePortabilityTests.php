<?php

/**
 * The party between fights, kept in the database (PartyState): which
 * team is being played, the alliance's teams joining it, and a
 * formation that survives reloads and keeps up with who's in the party.
 */
trait PartyStatePortabilityTests {

    private function reset_parties(): void {
        $this->reset_tables(PartyState::class, TEAM::class, HERO::class, MonsterToken::class, Alliance::class);
        $this->reset_hero_cache();
    }

    private function team(int $playerid, string $name, int $heroes = 3): TEAM {
        $specs = [];
        for ($i = 0; $i < $heroes; $i++) {
            $specs[] = ['name' => "$name $i", 'jobid' => 1, 'personalityid' => 1];
        }
        return TEAM::create($this->db, $playerid, $name, $specs);
    }

    private function names(PARTY $party): array {
        return array_map(fn($group) => array_values(array_map(fn($c) => $c->name, $group->characters)), $party->groups);
    }

    public function test_only_the_players_own_team_can_be_chosen() {
        $this->reset_parties();
        $mine = $this->team(7, 'Mine');
        $theirs = $this->team(8, 'Theirs');
        $this->assertNull(PartyState::party($this->db, 7), 'nothing picked yet');
        $this->assertFalse(PartyState::choose_team($this->db, 7, $theirs->teamid));
        $this->assertTrue(PartyState::choose_team($this->db, 7, $mine->teamid));
        $this->assertSame($mine->teamid, PartyState::lead_teamid($this->db, 7));
        $this->assertSame([['Mine 0', 'Mine 1', 'Mine 2']], $this->names(PartyState::party($this->db, 7)));
    }

    public function test_a_move_is_kept_across_loads() {
        $this->reset_parties();
        $mine = $this->team(7, 'Mine');
        PartyState::choose_team($this->db, 7, $mine->teamid);
        $this->assertTrue(PartyState::move($this->db, 7, 0, 2, 'l'));
        $this->assertSame([['Mine 0', 'Mine 2', 'Mine 1']], $this->names(PartyState::party($this->db, 7)));
        $this->assertTrue(PartyState::move($this->db, 7, 0, 0, 'd'), 'down: a new group');
        $this->assertSame([['Mine 2', 'Mine 1'], ['Mine 0']], $this->names(PartyState::party($this->db, 7)));
        $this->assertTrue(PartyState::move($this->db, 7, 1, 0, 'u'));
        $this->assertSame([['Mine 0', 'Mine 2', 'Mine 1']], $this->names(PartyState::party($this->db, 7)), 'back up to the same place; the empty group goes');
        $this->assertFalse(PartyState::move($this->db, 7, 0, 0, 'l'), 'nowhere to go');
        $this->assertFalse(PartyState::move($this->db, 7, 0, 0, 'u'));
        $this->assertFalse(PartyState::move($this->db, 7, 4, 0, 'd'), 'no such hero');
    }

    public function test_a_lone_hero_does_not_move_down_into_nothing() {
        $this->reset_parties();
        $mine = $this->team(7, 'Mine', 1);
        PartyState::choose_team($this->db, 7, $mine->teamid);
        $this->assertFalse(PartyState::move($this->db, 7, 0, 0, 'd'));
    }

    public function test_choosing_another_team_starts_a_new_formation() {
        $this->reset_parties();
        $one = $this->team(7, 'One');
        $two = $this->team(7, 'Two');
        PartyState::choose_team($this->db, 7, $one->teamid);
        PartyState::move($this->db, 7, 0, 1, 'l');
        PartyState::choose_team($this->db, 7, $one->teamid);
        $this->assertSame([['One 1', 'One 0', 'One 2']], $this->names(PartyState::party($this->db, 7)), 're-picking keeps it');
        PartyState::choose_team($this->db, 7, $two->teamid);
        PartyState::choose_team($this->db, 7, $one->teamid);
        $this->assertSame([['One 0', 'One 1', 'One 2']], $this->names(PartyState::party($this->db, 7)));
    }

    public function test_an_alliance_team_joins_the_party_where_there_is_room_and_leaves_with_it() {
        $this->reset_parties();
        $one = $this->team(7, 'One', 4);
        $two = $this->team(7, 'Two', 2);
        PartyState::choose_team($this->db, 7, $one->teamid);
        PartyState::move($this->db, 7, 0, 3, 'l');
        MonsterToken::grant($this->db, 7, 1, 1);
        $this->assertNull(Alliance::form($this->db, 7, [$one->teamid, $two->teamid], $one->teamid, [$two->teamid]));
        $party = PartyState::party($this->db, 7);
        $this->assertSame([['One 0', 'One 1', 'One 3', 'One 2'], ['Two 0', 'Two 1']], $this->names($party));
        $this->assertSame([$one->teamid, $two->teamid], array_keys($party->teams));
        Alliance::end($this->db, 7);
        $this->assertSame([['One 0', 'One 1', 'One 3', 'One 2']], $this->names(PartyState::party($this->db, 7)));
    }

    public function test_a_retired_team_is_no_longer_the_party() {
        $this->reset_parties();
        $mine = $this->team(7, 'Mine');
        PartyState::choose_team($this->db, 7, $mine->teamid);
        $mine->change_object_state($this->db, ObjectState::INACTIVE);
        $this->assertNull(PartyState::lead_teamid($this->db, 7));
        $this->assertNull(PartyState::party($this->db, 7));
    }

    public function test_moving_into_a_full_group_trades_places() {
        $this->reset_parties();
        $one = $this->team(7, 'One', 4);
        $two = $this->team(7, 'Two', 4);
        PartyState::choose_team($this->db, 7, $one->teamid);
        MonsterToken::grant($this->db, 7, 1, 1);
        Alliance::form($this->db, 7, [$one->teamid, $two->teamid], $one->teamid, [$two->teamid]);
        $this->assertTrue(PartyState::move($this->db, 7, 1, 0, 'u'), 'a vacancy above: taken');
        $this->assertSame([['Two 0', 'One 0', 'One 1', 'One 2', 'One 3'], ['Two 1', 'Two 2', 'Two 3']],
            $this->names(PartyState::party($this->db, 7)));
        $this->assertTrue(PartyState::move($this->db, 7, 1, 2, 'u'), 'full above: a trade');
        $this->assertSame([['Two 0', 'One 0', 'Two 3', 'One 2', 'One 3'], ['Two 1', 'Two 2', 'One 1']],
            $this->names(PartyState::party($this->db, 7)));
        $this->assertTrue(PartyState::move($this->db, 7, 0, 4, 'd'), 'room below: taken, at the end when that is nearest');
        $this->assertSame([['Two 0', 'One 0', 'Two 3', 'One 2'], ['Two 1', 'Two 2', 'One 1', 'One 3']],
            $this->names(PartyState::party($this->db, 7)));
        $this->assertTrue(PartyState::move($this->db, 7, 1, 3, 'd'), 'down from the last group: a third');
        $this->assertTrue(PartyState::move($this->db, 7, 1, 2, 'd'), 'into the third, which has room');
        $this->assertTrue(PartyState::move($this->db, 7, 2, 1, 'd'), 'and a fourth');
        $this->assertSame([['Two 0', 'One 0', 'Two 3', 'One 2'], ['Two 1', 'Two 2'], ['One 3'], ['One 1']],
            $this->names(PartyState::party($this->db, 7)));
        $this->assertFalse(PartyState::move($this->db, 7, 3, 0, 'd'), 'no fifth group');
    }
}
