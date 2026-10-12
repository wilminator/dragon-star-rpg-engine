<?php

/**
 * A fight with an alliance: two of one player's teams in one party. The
 * player commands both, the AI commands neither, both teams' histories
 * get the fight, the client sees both as the player's own, and the
 * fight's length comes off the alliance's time.
 */
trait AllianceFightPortabilityTests {

    private const STRONG = ['HP' => 500, 'MP' => 10, 'Speed' => 50, 'Accuracy' => 100, 'Strength' => 30, 'Dodge' => 10, 'Block' => 10, 'Power' => 10, 'Resistance' => 10, 'Focus' => 10];
    private const WEAK = ['HP' => 20, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 1, 'Focus' => 1];

    // Player 42's team $first and $second_owner's team $second in one
    // party against one NPC; 42 is in the fight, and so is $second_owner
    // when $both_play.
    private function alliance_fight(int $second_owner = 42, bool $both_play = false): array {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class,
            PendingAction::class, FightSave::class, GameLog::class, RateLimit::class, AppSetting::class, TEAM::class, HERO::class,
            MonsterToken::class, Alliance::class);
        $party = new PARTY();
        $teams = [];
        foreach (['First' => 42, 'Second' => $second_owner] as $name => $owner) {
            $team = TEAM::create($this->db, $owner, $name, [['name' => "$name hero", 'jobid' => 1, 'personalityid' => 1]]);
            foreach ($team->characters as $character) {
                $character->current = self::STRONG;
                $character->base = self::STRONG;
            }
            $party->add_team($team);
            $teams[] = $team->teamid;
        }
        $npc = CHARACTER::create_hero('Target', 999, 1, null);
        $npc->teamid = 999;
        $npc->current = self::WEAK;
        $npc->base = self::WEAK;
        $npc_group = new GROUP();
        $npc_group->add_hero($npc);
        $other = new PARTY();
        $other->add($npc_group);

        $fight = new FIGHT('bg', 'music');
        $fight->add($party);
        $fight->add($other);
        $fight->parties[0]->enemies[] = 1;
        $fight->parties[1]->enemies[] = 0;
        $players = [['playerid' => 42, 'teamid' => $teams[0], 'player_party' => 0]];
        if ($both_play) {
            $players[] = ['playerid' => $second_owner, 'teamid' => $teams[1], 'player_party' => 0];
        }
        $fightid = FightCommunicator::start_fight($this->db, $fight, $players);
        return [$fightid, $teams[0], $teams[1]];
    }

    // [group, character] of each of the player's heroes.
    private function hero_positions(int $fightid): array {
        $positions = [];
        foreach (FIGHT::load_current($this->db, $fightid)->parties[0]->groups as $g => $group) {
            foreach (array_keys($group->characters) as $c) {
                $positions[] = [$g, $c];
            }
        }
        return $positions;
    }

    public function test_the_player_owns_every_team_in_their_party() {
        [$fightid, $first, $second] = $this->alliance_fight();
        $fight = FIGHT::load_current($this->db, $fightid);
        $this->assertSame([$first, $second], FightCommunicator::own_teamids($this->db, $fight, 42, $first, 0));
        $this->assertSame([-1], FightCommunicator::own_teamids($this->db, $fight, 42, -1, 1), 'a monster controller owns the monsters only');
        $this->assertSame([777], FightCommunicator::own_teamids($this->db, $fight, 42, 777, 0), 'a team not in the party trusts only itself');
    }

    public function test_the_player_can_command_both_teams_heroes() {
        [$fightid] = $this->alliance_fight();
        $player = new FightCommunicator($this->db, 42);
        $positions = $this->hero_positions($fightid);
        $this->assertCount(2, $positions);
        foreach ($positions as [$g, $c]) {
            $this->assertTrue($player->submit_character_command(0, $g, $c, [0, [1, 0, 0], 0]), "hero at $g,$c");
        }
        $this->assertFalse((new FightCommunicator($this->db, 43))->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]), 'another player cannot');
    }

    public function test_the_client_sees_both_teams_as_its_own() {
        [$fightid, $first, $second] = $this->alliance_fight();
        $view = (new FightCommunicator($this->db, 42))->get_fight_view();
        $this->assertSame([$first, $second], $view['teamids']);
        $party = $view['client_fight']['parties'][0];
        $this->assertSame([$first, $second], array_map('intval', array_keys($party['teams'])));
        foreach ($party['groups'] as $group) {
            foreach ($group['characters'] as $character) {
                $this->assertArrayHasKey('target', $character, 'own-team detail for both teams');
            }
        }
        $this->assertSame([$first, $second], (new FightCommunicator($this->db, 42))->get_fight_status()['teamids']);
    }

    public function test_a_finished_fight_goes_in_both_histories_and_off_the_alliance_clock() {
        [$fightid, $first, $second] = $this->alliance_fight();
        MonsterToken::grant($this->db, 42, 1, 1);
        $this->assertNull(Alliance::form($this->db, 42, [$first, $second], $first, [$second]));
        $this->db->prepare("UPDATE fights SET created_at = ? WHERE id = ?")->execute([time() - 600, $fightid]);

        $player = new FightCommunicator($this->db, 42);
        for ($round = 0; $round < 20 && FightSemaphore::load($this->db, $fightid)->state !== FightStatus::Resolved; $round++) {
            foreach ($this->hero_positions($fightid) as [$g, $c]) {
                $player->submit_character_command(0, $g, $c, [0, [1, 0, 0], 0]);
            }
            $player->confirm_round(FightSemaphore::load($this->db, $fightid)->sequence);
        }
        $this->assertSame(FightStatus::Resolved, FightSemaphore::load($this->db, $fightid)->state);

        $this->assertContains($fightid, FightSave::load_player_history_fightids($this->db, $first));
        $this->assertContains($fightid, FightSave::load_player_history_fightids($this->db, $second));
        $this->assertEqualsWithDelta(Alliance::HOUR - 600, Alliance::load($this->db, 42)->seconds_left, 5);
    }

    // --- another player's team in the same party ---

    public function test_another_players_team_in_the_party_is_not_yours() {
        [$fightid, $first, $second] = $this->alliance_fight(43);
        $fight = FIGHT::load_current($this->db, $fightid);
        $this->assertSame([$first], FightCommunicator::own_teamids($this->db, $fight, 42, $first, 0));
        $this->assertSame([$second], FightCommunicator::own_teamids($this->db, $fight, 43, $second, 0));

        $player = new FightCommunicator($this->db, 42);
        [[$g0, $c0], [$g1, $c1]] = $this->hero_positions($fightid);
        $this->assertTrue($player->submit_character_command(0, $g0, $c0, [0, [1, 0, 0], 0]));
        $this->assertFalse($player->submit_character_command(0, $g1, $c1, [0, [1, 0, 0], 0]), "43's hero");

        $view = $player->get_fight_view();
        $this->assertSame([$first], $view['teamids']);
        $this->assertArrayHasKey('target', $view['client_fight']['parties'][0]['groups'][$g0]['characters'][$c0]);
        $this->assertArrayNotHasKey('target', $view['client_fight']['parties'][0]['groups'][$g1]['characters'][$c1]);
    }

    public function test_each_player_in_the_party_commands_only_their_own_team() {
        [$fightid, $first, $second] = $this->alliance_fight(43, both_play: true);
        [[$g0, $c0], [$g1, $c1]] = $this->hero_positions($fightid);
        $mine = new FightCommunicator($this->db, 42);
        $theirs = new FightCommunicator($this->db, 43);
        $this->assertTrue($theirs->submit_character_command(0, $g1, $c1, [0, [1, 0, 0], 0]));
        $this->assertFalse($theirs->submit_character_command(0, $g0, $c0, [0, [1, 0, 0], 0]));
        $this->assertFalse($mine->submit_character_command(0, $g1, $c1, [0, [1, 0, 0], 0]));
    }
}
