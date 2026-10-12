<?php

/**
 * A fight that gets a problem report is flagged for admin review: at
 * once if it has resolved, otherwise when it resolves.
 */
trait AnomalyFlagPortabilityTests {

    private function flag_fixture(): array {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class,
            PendingAction::class, FightSave::class, GameLog::class, RateLimit::class, AppSetting::class, TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 42, 'Reporters', []);
        $character = CHARACTER::create_hero('Hero', 999, 1, null);
        $character->teamid = $team->teamid;
        $strong = ['HP' => 500, 'MP' => 10, 'Speed' => 50, 'Accuracy' => 100, 'Strength' => 30, 'Dodge' => 10, 'Block' => 10, 'Power' => 10, 'Resistance' => 10, 'Focus' => 10];
        $character->current = $strong;
        $character->base = $strong;
        $group = new GROUP();
        $group->add_hero($character);
        $hero = new PARTY();
        $hero->add($group);

        $npc = CHARACTER::create_hero('Target', 999, 1, null);
        $npc->teamid = 999;
        $weak = ['HP' => 20, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 1, 'Focus' => 1];
        $npc->current = $weak;
        $npc->base = $weak;
        $npc_group = new GROUP();
        $npc_group->add_hero($npc);
        $other = new PARTY();
        $other->add($npc_group);

        $fight = new FIGHT('bg', 'music');
        $fight->add($hero);
        $fight->add($other);
        $fightid = FightCommunicator::start_fight($this->db, $fight, [['playerid' => 42, 'teamid' => $team->teamid, 'player_party' => 0]]);
        return [$fightid, $team];
    }

    private function play_out(int $fightid): void {
        $player = new FightCommunicator($this->db, 42);
        for ($round = 0; $round < 20 && FightSemaphore::load($this->db, $fightid)->state !== FightStatus::Resolved; $round++) {
            $player->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
            $player->confirm_round(FightSemaphore::load($this->db, $fightid)->sequence);
        }
        $this->assertSame(FightStatus::Resolved, FightSemaphore::load($this->db, $fightid)->state);
    }

    private function flagged(int $fightid, TEAM $team): bool {
        return FightSave::load($this->db, $fightid, $team->teamid, FightSave::ADMIN_TYPE) !== null;
    }

    public function test_a_report_on_a_resolved_fight_flags_it_at_once() {
        [$fightid, $team] = $this->flag_fixture();
        $this->play_out($fightid);

        Anomaly::record_reports($this->db, [['type' => 'x']], null, $fightid, '203.0.113.1');
        $this->assertSame(1, Anomaly::flag_fight($this->db, $fightid));

        $this->assertTrue($this->flagged($fightid, $team));
        $this->assertNotContains($fightid, FightSave::load_player_history_fightids($this->db, 999), 'no player team for the NPC side');
    }

    public function test_a_report_during_a_live_fight_flags_it_when_it_resolves() {
        [$fightid, $team] = $this->flag_fixture();
        Anomaly::record_reports($this->db, [['type' => 'x']], 42, $fightid, '203.0.113.1');

        $this->assertSame(0, Anomaly::flag_fight($this->db, $fightid), 'not yet: still live');
        $this->assertFalse($this->flagged($fightid, $team));

        $this->play_out($fightid);

        $this->assertTrue($this->flagged($fightid, $team));
    }

    public function test_a_fight_without_reports_is_not_flagged() {
        [$fightid, $team] = $this->flag_fixture();
        $this->play_out($fightid);
        $this->assertFalse($this->flagged($fightid, $team));
    }
}
