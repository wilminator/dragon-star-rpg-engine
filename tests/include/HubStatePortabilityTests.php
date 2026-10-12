<?php

/**
 * What the hub shows (HubState) and the inn it offers (Inn): the party in
 * formation, each team's purse and room price, and resting only when
 * every team can pay.
 */
trait HubStatePortabilityTests {

    private function reset_hub(): void {
        $this->reset_tables(PartyState::class, TEAM::class, HERO::class, MonsterToken::class, Alliance::class);
        $this->reset_hero_cache();
    }

    private function hub_team(int $playerid, string $name, int $heroes, int $gold): TEAM {
        $specs = [];
        for ($i = 0; $i < $heroes; $i++) {
            $specs[] = ['name' => "$name $i", 'jobid' => 1, 'personalityid' => 1];
        }
        $team = TEAM::create($this->db, $playerid, $name, $specs);
        $team->gold = $gold;
        $team->save($this->db);
        return $team;
    }

    public function test_nothing_to_show_until_a_team_is_picked() {
        $this->reset_hub();
        $this->assertNull(HubState::for_player($this->db, 7));
    }

    public function test_the_hub_shows_the_party_in_formation_with_purses() {
        $this->reset_hub();
        $team = $this->hub_team(7, 'Mine', 2, 50);
        PartyState::choose_team($this->db, 7, $team->teamid);
        PartyState::move($this->db, 7, 0, 1, 'l');
        $state = HubState::for_player($this->db, 7);
        $this->assertSame([['teamid' => $team->teamid, 'name' => 'Mine', 'gold' => 50, 'inn_price' => Inn::price($team)]], $state['teams']);
        $this->assertSame(['Mine 1', 'Mine 0'], array_column($state['groups'][0], 'name'));
        $hero = $state['groups'][0][0];
        $this->assertSame(['charid', 'name', 'job', 'level', 'hp', 'mp', 'picture', 'team'], array_keys($hero));
        $this->assertCount(2, $hero['hp']);
        $this->assertNull($state['alliance']);
    }

    public function test_the_inn_restores_everyone_and_takes_each_teams_price() {
        $this->reset_hub();
        $team = $this->hub_team(7, 'Mine', 2, 1000);
        $hero = $team->characters[array_key_first($team->characters)];
        $hero->current['HP'] = 1;
        HERO::save_character($this->db, $hero);
        $team = TEAM::load($this->db, $team->teamid);
        $price = Inn::price($team);
        $this->assertNull(Inn::rest($this->db, [$team]));
        $after = TEAM::load($this->db, $team->teamid);
        $this->assertSame(1000 - $price, $after->gold);
        foreach ($after->characters as $character) {
            $this->assertSame($character->base['HP'], $character->current['HP']);
        }
    }

    public function test_nobody_rests_unless_every_team_can_pay() {
        $this->reset_hub();
        $rich = $this->hub_team(7, 'Rich', 1, 1000);
        $poor = $this->hub_team(7, 'Poor', 1, 0);
        if (Inn::price($poor) === 0) {
            $this->markTestSkipped('fixture heroes are too weak to cost anything at the inn');
        }
        $this->assertStringContainsString('Poor', (string) Inn::rest($this->db, [$rich, $poor]));
        $this->assertSame(1000, TEAM::load($this->db, $rich->teamid)->gold, 'the rich team paid nothing');
        $this->assertSame('You do not have enough gold.', Inn::rest($this->db, [$poor]));
    }
}
