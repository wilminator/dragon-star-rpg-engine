<?php

/**
 * The hub's Alliance dialog (AllianceScreen): what the player sees and
 * can buy, and buying it -- Alliance's rules, as setup_fight.php shows
 * them.
 */
trait AllianceScreenPortabilityTests {

    private function three_teams(): array {
        $this->reset_tables(PartyState::class, TEAM::class, HERO::class, MonsterToken::class, Alliance::class,
            FIGHT::class, FightPlayerState::class);
        $this->reset_hero_cache();
        $teams = [];
        foreach (['Red', 'Blue', 'Gold'] as $name) {
            $teams[] = TEAM::create($this->db, 7, $name, [['name' => "$name 1", 'jobid' => 2, 'personalityid' => 1]]);
        }
        PartyState::choose_team($this->db, 7, $teams[0]->teamid);
        return $teams;
    }

    public function test_without_tokens_there_is_only_a_hint() {
        $this->three_teams();
        $view = AllianceScreen::view($this->db, 7);
        $this->assertSame(0, $view['balance']);
        $this->assertSame([], $view['choices']);
        $this->assertSame('Earn tokens fighting as the monsters to bring your other teams along.', $view['hint']);
        $this->assertSame(['Blue', 'Gold'], array_column($view['formable'], 1));
        $this->assertNull(AllianceScreen::view($this->db, 8), 'no team picked');
    }

    public function test_forming_adding_and_extending() {
        [$red, $blue, $gold] = $this->three_teams();
        MonsterToken::grant($this->db, 7, 3, 1);
        $this->assertSame(['form'], array_column(AllianceScreen::view($this->db, 7)['choices'], 'action'));

        $this->assertSame([null, 'Your alliance is formed.'], AllianceScreen::act($this->db, 7, 'form', [$blue->teamid]));
        $view = AllianceScreen::view($this->db, 7);
        $this->assertStringStartsWith('Allied: Red, Blue — 60 minutes left.', $view['status']);
        $this->assertSame([['add', 'Bring Gold too', $gold->teamid]],
            array_map(fn($c) => [$c['action'], $c['label'], $c['teamid']], $view['choices']));
        $this->assertSame(['Red', 'Blue'], array_column(HubState::for_player($this->db, 7)['teams'], 'name'), 'the party grows');

        $this->assertSame([null, 'Another team joins you.'], AllianceScreen::act($this->db, 7, 'add', [$gold->teamid]));
        $this->assertSame(['extend'], array_column(AllianceScreen::view($this->db, 7)['choices'], 'action'), 'full: tokens add time');
        $this->assertSame([null, 'Your alliance has more time.'], AllianceScreen::act($this->db, 7, 'extend', []));
        $this->assertSame(0, AllianceScreen::view($this->db, 7)['balance']);
        $this->assertStringContainsString('120 minutes left', AllianceScreen::view($this->db, 7)['status']);
    }

    public function test_refusals_come_from_the_alliance_rules() {
        [$red, $blue] = $this->three_teams();
        $this->assertSame('Choose one or two teams to join you.', AllianceScreen::act($this->db, 7, 'form', [])[0]);
        $this->assertSame("You don't have an alliance to extend.", AllianceScreen::act($this->db, 7, 'extend', [])[0]);
        $this->assertSame('Do what?', AllianceScreen::act($this->db, 7, 'sell', [])[0]);
        $this->assertSame('Pick a team first.', AllianceScreen::act($this->db, 8, 'form', [$blue->teamid])[0]);
    }
}
