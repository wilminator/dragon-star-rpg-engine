<?php

/**
 * Covers FightBrowser (admin/fight_playback.php): searching every
 * recorded fight by player, team and time, and the admin retention flag
 * -- unlimited, only on resolved fights a team took part in, and kept
 * out of the team's own fight history. Also covers FightSave accepting
 * a tag on a resolved fight whose semaphore has been purged.
 *
 * Fixture: player 42 plays team "Dragon Slayers", player 43 plays team
 * "Night Owls".
 */
trait FightBrowserPortabilityTests {

    private TEAM $slayers;
    private TEAM $owls;

    private function reset_browser_tables(): void {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class,
            PendingAction::class, FightSave::class, TEAM::class, HERO::class);
        $this->slayers = TEAM::create($this->db, 42, 'Dragon Slayers', []);
        $this->owls = TEAM::create($this->db, 43, 'Night Owls', []);
    }

    private function browser_party(int $teamid): PARTY {
        $character = CHARACTER::create_hero('Hero', 999, 1, null);
        $character->teamid = $teamid;
        $stats = ['HP' => 100, 'MP' => 10, 'Speed' => 10, 'Accuracy' => 10, 'Strength' => 10, 'Dodge' => 10, 'Block' => 10, 'Power' => 10, 'Resistance' => 10, 'Focus' => 10];
        $character->current = $stats;
        $character->base = $stats;
        $group = new GROUP();
        $group->add_hero($character);
        $party = new PARTY();
        $party->add($group);
        return $party;
    }

    // $players: [[playerid, TEAM], ...], one party each, in order.
    private function browser_fight(array $players, int $created_at, bool $resolved = true): int {
        $fight = new FIGHT('bg', 'music');
        $seats = [];
        foreach ($players as $index => [$playerid, $team]) {
            $fight->add($this->browser_party($team->teamid));
            $seats[] = ['playerid' => $playerid, 'teamid' => $team->teamid, 'player_party' => $index];
        }
        $fightid = FightCommunicator::start_fight($this->db, $fight, $seats);
        $this->db->prepare("UPDATE fights SET created_at = ? WHERE id = ?")->execute([$created_at, $fightid]);
        if ($resolved) {
            FightSemaphore::claim($this->db, $fightid, 42, 30)->release($this->db, FightStatus::Resolved, 1, 600);
        }
        return $fightid;
    }

    private function fightids(array $rows): array {
        return array_map(fn($row) => $row['fightid'], $rows);
    }

    // --- searching ---

    public function test_every_participant_of_every_fight_is_listed_newest_first() {
        $this->reset_browser_tables();
        $first = $this->browser_fight([[42, $this->slayers]], 1000);
        $second = $this->browser_fight([[42, $this->slayers], [43, $this->owls]], 2000, resolved: false);

        $rows = FightBrowser::search($this->db);

        $this->assertSame([$second, $second, $first], $this->fightids($rows));
        $this->assertSame([0, 1], [$rows[0]['party'], $rows[1]['party']]);
        $this->assertSame('Night Owls', $rows[1]['team_name']);
        $this->assertSame(43, $rows[1]['playerid']);
    }

    public function test_rows_say_whether_the_fight_is_resolved_and_what_round_a_live_one_is_on() {
        $this->reset_browser_tables();
        $live = $this->browser_fight([[42, $this->slayers]], 1000, resolved: false);
        $done = $this->browser_fight([[43, $this->owls]], 2000);
        $purged = $this->browser_fight([[43, $this->owls]], 3000);
        FightSemaphore::delete($this->db, $purged);

        $by_fight = array_column(FightBrowser::search($this->db), null, 'fightid');

        $this->assertFalse($by_fight[$live]['resolved']);
        $this->assertSame(0, $by_fight[$live]['round']);
        $this->assertTrue($by_fight[$done]['resolved']);
        $this->assertTrue($by_fight[$purged]['resolved'], 'a purged semaphore means it resolved');
        $this->assertNull($by_fight[$purged]['round']);
        $this->assertNotSame('', $by_fight[$done]['guid']);
    }

    public function test_search_by_player() {
        $this->reset_browser_tables();
        $this->browser_fight([[42, $this->slayers]], 1000);
        $owls_fight = $this->browser_fight([[43, $this->owls]], 2000);

        $this->assertSame([$owls_fight], $this->fightids(FightBrowser::search($this->db, ['playerid' => 43])));
    }

    public function test_search_by_team_id_or_part_of_its_name_in_any_case() {
        $this->reset_browser_tables();
        $slayers_fight = $this->browser_fight([[42, $this->slayers]], 1000);
        $this->browser_fight([[43, $this->owls]], 2000);

        $this->assertSame([$slayers_fight], $this->fightids(FightBrowser::search($this->db, ['team' => (string) $this->slayers->teamid])));
        $this->assertSame([$slayers_fight], $this->fightids(FightBrowser::search($this->db, ['team' => 'SLAYER'])));
        $this->assertSame([], FightBrowser::search($this->db, ['team' => 'nobody']));
    }

    public function test_search_by_time_is_inclusive_at_both_ends() {
        $this->reset_browser_tables();
        $early = $this->browser_fight([[42, $this->slayers]], 1000);
        $middle = $this->browser_fight([[42, $this->slayers]], 2000);
        $late = $this->browser_fight([[42, $this->slayers]], 3000);

        $this->assertSame([$late, $middle], $this->fightids(FightBrowser::search($this->db, ['from' => 2000])));
        $this->assertSame([$middle, $early], $this->fightids(FightBrowser::search($this->db, ['to' => 2000])));
        $this->assertSame([$middle], $this->fightids(FightBrowser::search($this->db, ['from' => 1500, 'to' => 2500])));
    }

    public function test_filters_combine() {
        $this->reset_browser_tables();
        $this->browser_fight([[42, $this->slayers]], 1000);
        $this->browser_fight([[43, $this->owls]], 3000);
        $match = $this->browser_fight([[42, $this->slayers], [43, $this->owls]], 3000);

        $rows = FightBrowser::search($this->db, ['playerid' => 42, 'from' => 2000]);

        $this->assertSame([$match], $this->fightids($rows));
    }

    public function test_search_returns_at_most_the_limit_newest_first() {
        $this->reset_browser_tables();
        $this->browser_fight([[42, $this->slayers]], 1000);
        $newer = $this->browser_fight([[42, $this->slayers]], 2000);

        $this->assertSame([$newer], $this->fightids(FightBrowser::search($this->db, ['limit' => 1])));
    }

    // --- the admin retention flag ---

    public function test_flagging_a_resolved_fight_for_a_team_that_fought_in_it() {
        $this->reset_browser_tables();
        $fightid = $this->browser_fight([[42, $this->slayers], [43, $this->owls]], 1000);

        $this->assertTrue(FightBrowser::set_flag($this->db, $fightid, $this->slayers->teamid, true));

        $rows = FightBrowser::search($this->db);
        $this->assertTrue($rows[0]['flagged'], "the Dragon Slayers' row");
        $this->assertFalse($rows[1]['flagged'], 'flagged for one team, not every team in the fight');
        $this->assertSame([$fightid], $this->fightids(FightBrowser::search($this->db, ['flagged_only' => true])));
    }

    public function test_unflagging() {
        $this->reset_browser_tables();
        $fightid = $this->browser_fight([[42, $this->slayers]], 1000);
        FightBrowser::set_flag($this->db, $fightid, $this->slayers->teamid, true);

        $this->assertTrue(FightBrowser::set_flag($this->db, $fightid, $this->slayers->teamid, false));

        $this->assertSame([], FightBrowser::search($this->db, ['flagged_only' => true]));
    }

    public function test_there_is_no_limit_on_how_many_fights_an_admin_can_flag() {
        $this->reset_browser_tables();
        for ($i = 0; $i < 30; $i++) {
            $fightid = $this->browser_fight([[42, $this->slayers]], 1000 + $i);
            $this->assertTrue(FightBrowser::set_flag($this->db, $fightid, $this->slayers->teamid, true));
        }

        $this->assertCount(30, FightBrowser::search($this->db, ['flagged_only' => true]));
    }

    public function test_a_live_fight_cannot_be_flagged() {
        $this->reset_browser_tables();
        $fightid = $this->browser_fight([[42, $this->slayers]], 1000, resolved: false);

        $this->assertFalse(FightBrowser::set_flag($this->db, $fightid, $this->slayers->teamid, true));
        $this->assertNull(FightSave::load($this->db, $fightid, $this->slayers->teamid, FightSave::ADMIN_TYPE));
    }

    public function test_a_fight_can_only_be_flagged_for_a_team_that_fought_in_it() {
        $this->reset_browser_tables();
        $fightid = $this->browser_fight([[42, $this->slayers]], 1000);

        $this->assertFalse(FightBrowser::set_flag($this->db, $fightid, $this->owls->teamid, true));
        $this->assertFalse(FightBrowser::set_flag($this->db, $fightid, CHARACTER::MONSTER_TEAMID, true));
    }

    public function test_a_fight_can_be_flagged_after_its_semaphore_is_purged() {
        $this->reset_browser_tables();
        $fightid = $this->browser_fight([[42, $this->slayers]], 1000);
        FightSemaphore::delete($this->db, $fightid);

        $this->assertTrue(FightBrowser::set_flag($this->db, $fightid, $this->slayers->teamid, true));
    }

    // --- the team's own history ---

    public function test_a_fight_only_an_admin_flagged_stays_out_of_the_teams_history() {
        $this->reset_browser_tables();
        $played = $this->browser_fight([[42, $this->slayers]], 1000);
        FightSave::record_user_history($this->db, $played, $this->slayers->teamid);
        $admin_only = $this->browser_fight([[42, $this->slayers]], 2000);
        FightBrowser::set_flag($this->db, $admin_only, $this->slayers->teamid, true);

        $this->assertSame([$played], FightSave::load_player_history_fightids($this->db, $this->slayers->teamid));
    }

    public function test_a_flagged_fight_the_team_also_tagged_is_in_its_history_once() {
        $this->reset_browser_tables();
        $fightid = $this->browser_fight([[42, $this->slayers]], 1000);
        FightSave::record_user_history($this->db, $fightid, $this->slayers->teamid);
        FightBrowser::set_flag($this->db, $fightid, $this->slayers->teamid, true);

        $this->assertSame([$fightid], FightSave::load_player_history_fightids($this->db, $this->slayers->teamid));
    }

    public function test_the_teams_history_includes_retains_and_highlights_newest_first() {
        $this->reset_browser_tables();
        $retained = $this->browser_fight([[42, $this->slayers]], 1000);
        $underdog = $this->browser_fight([[42, $this->slayers]], 2000);
        $overkill = $this->browser_fight([[42, $this->slayers]], 3000);
        FightSave::retain($this->db, $retained, $this->slayers->teamid);
        FightSave::consider_for_underdog($this->db, $underdog, $this->slayers->teamid, 10, 5);
        FightSave::consider_for_overkill($this->db, $overkill, $this->slayers->teamid, 10, 5);

        $this->assertSame([$overkill, $underdog, $retained], FightSave::load_player_history_fightids($this->db, $this->slayers->teamid));
    }

    public function test_a_resolved_fight_whose_semaphore_was_purged_can_still_be_tagged() {
        $this->reset_browser_tables();
        $fightid = $this->browser_fight([[42, $this->slayers]], 1000);
        FightSemaphore::delete($this->db, $fightid);

        (new FightSave($fightid, $this->slayers->teamid, 'retain'))->save($this->db);

        $this->assertNotNull(FightSave::load($this->db, $fightid, $this->slayers->teamid, 'retain'));
    }
}
