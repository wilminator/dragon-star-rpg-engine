<?php

/**
 * Playing the monsters (monster_side.php): the queue, being seated in a
 * fight someone starts, the practice fight, abandoning and rejoining,
 * tokens and the lockout at the end, and admin takeovers.
 */
trait MonsterSidePortabilityTests {

    private const HERO = 42;
    private const MONSTER = 77;
    private const STRONG_STATS = ['HP' => 500, 'MP' => 10, 'Speed' => 50, 'Accuracy' => 100, 'Strength' => 30, 'Dodge' => 10, 'Block' => 10, 'Power' => 10, 'Resistance' => 10, 'Focus' => 10];

    private function reset_monster_side(): void {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class,
            PendingAction::class, FightSave::class, GameLog::class, RateLimit::class, AppSetting::class, TEAM::class, HERO::class,
            MonsterToken::class, Alliance::class, MonsterQueue::class, MonsterLockout::class, MonsterSeat::class, PermissionGrant::class);
    }

    private function team_for(int $playerid, string $name): TEAM {
        return TEAM::create($this->db, $playerid, $name, [['name' => "$name hero", 'jobid' => 1, 'personalityid' => 1]]);
    }

    // A fight HERO starts with a strong team against one weak monster,
    // with anyone queued seated on the monsters. [fightid, seated player].
    private function start_fight_with_queue(): array {
        $team = TEAM::load_for_gameplay($this->db, $this->team_for(self::HERO, 'Heroes')->teamid);
        foreach ($team->characters as $character) {
            $character->current = self::STRONG_STATS;
            $character->base = self::STRONG_STATS;
        }
        $heroes = new PARTY();
        $heroes->add_team($team);
        $monster = CHARACTER::create_hero('Target', 999, 1, null);
        $monster->teamid = CHARACTER::MONSTER_TEAMID;
        $monster->current = ['HP' => 30] + self::STRONG_STATS;
        $monster->base = ['HP' => 30] + self::STRONG_STATS;
        $group = new GROUP();
        $group->add_hero($monster);
        $monsters = new PARTY();
        $monsters->add($group);
        $fight = new FIGHT('bg', 'music');
        $fight->add($heroes);
        $fight->add($monsters);
        FightSetup::link_as_enemies($fight);

        $players = [['playerid' => self::HERO, 'teamid' => $team->teamid, 'player_party' => 0]];
        $seated = FightSetup::claim_monster_player($this->db, self::HERO, $players);
        $fightid = FightCommunicator::start_fight($this->db, $fight, $players);
        if ($seated !== null) {
            MonsterSide::seat($this->db, $fightid, $seated, 1);
        }
        return [$fightid, $seated];
    }

    private function sequence(int $fightid): int {
        return FightSemaphore::load($this->db, $fightid)->sequence;
    }

    private function expire_round(int $fightid): void {
        $this->db->prepare("UPDATE fight_semaphore SET timeout = ? WHERE fightid = ?")->execute([time() - 1, $fightid]);
    }

    // --- the queue ---

    public function test_joining_needs_a_team_no_fight_and_no_lockout() {
        $this->reset_monster_side();
        $this->assertNotNull(MonsterQueue::join($this->db, self::MONSTER), 'no team');
        $this->team_for(self::MONSTER, 'Imps');
        MonsterLockout::lock($this->db, self::MONSTER, time() + 600);
        $this->assertStringContainsString('10 minutes', MonsterQueue::join($this->db, self::MONSTER));
        MonsterLockout::lock($this->db, self::MONSTER, time() - 1);
        $this->assertNull(MonsterQueue::join($this->db, self::MONSTER));
        $this->assertNull(MonsterQueue::join($this->db, self::MONSTER), 'already queued is fine');
        $this->assertTrue(MonsterQueue::is_queued($this->db, self::MONSTER));
    }

    public function test_the_longest_waiting_player_still_checking_in_is_claimed_but_never_the_fights_own() {
        $this->reset_monster_side();
        foreach ([self::MONSTER, 78, 79, self::HERO] as $player) {
            $this->team_for($player, "Team $player");
        }
        $now = time();
        MonsterQueue::join($this->db, 79, $now - 50);          // gone quiet
        MonsterQueue::join($this->db, self::HERO, $now - 40);  // the fight's own player
        MonsterQueue::join($this->db, 78, $now - 20);
        MonsterQueue::join($this->db, self::MONSTER, $now - 5);
        $this->db->prepare("UPDATE monster_queue SET last_seen = ? WHERE userid = 79")->execute([$now - 60]);
        $this->db->prepare("UPDATE monster_queue SET last_seen = ? WHERE userid <> 79")->execute([$now]);

        $this->assertSame(78, MonsterQueue::claim_next($this->db, self::HERO, $now));
        $this->assertFalse(MonsterQueue::is_queued($this->db, 78));
        $this->assertFalse(MonsterQueue::is_queued($this->db, 79), 'dropped for not checking in');
        $this->assertSame(self::MONSTER, MonsterQueue::claim_next($this->db, self::HERO, $now));
        $this->assertNull(MonsterQueue::claim_next($this->db, self::HERO, $now));
    }

    public function test_a_started_fight_seats_the_queued_player_on_the_monsters() {
        $this->reset_monster_side();
        $this->team_for(self::MONSTER, 'Imps');
        MonsterQueue::join($this->db, self::MONSTER);
        [$fightid, $seated] = $this->start_fight_with_queue();

        $this->assertSame(self::MONSTER, $seated);
        $state = FightPlayerState::load($this->db, $fightid, self::MONSTER);
        $this->assertSame([CHARACTER::MONSTER_TEAMID, 1], [$state->teamid, $state->player_party]);
        $this->assertTrue(MonsterSeat::load($this->db, $fightid, self::MONSTER)->present);
        $pending = array_filter(PendingAction::load_all_by_fightid($this->db, $fightid), fn($p) => $p->party_index === 1);
        $this->assertCount(1, $pending, 'the monster starts with the AI\'s command');
        $this->assertSame(['state' => 'fight'], MonsterQueue::poll($this->db, self::MONSTER));
    }

    public function test_waiting_then_a_practice_fight_after_the_fallback_time() {
        $this->reset_monster_side();
        $mine = $this->team_for(self::MONSTER, 'Imps');
        $ally = $this->team_for(90, 'Friends');
        $this->db->prepare($this->db->insert_query('team_relations', ['teamid', 'otherteamid', 'state']))
            ->execute([$mine->teamid, $ally->teamid, RelationState::ALLY->value]);
        $this->assertEqualsCanonicalizing([$mine->teamid, $ally->teamid], PracticeFight::team_pool($this->db, self::MONSTER));

        $now = time();
        MonsterQueue::join($this->db, self::MONSTER, $now);
        $this->assertSame(['state' => 'waiting', 'seconds_left' => 20], MonsterQueue::poll($this->db, self::MONSTER, $now + 10));
        $this->assertSame(['state' => 'fight'], MonsterQueue::poll($this->db, self::MONSTER, $now + MonsterQueue::FALLBACK_SECONDS));

        $fightid = FightPlayerState::find_active_fightid_for_player($this->db, self::MONSTER);
        $fight = FIGHT::load($this->db, $fightid);
        $this->assertTrue($fight->practice);
        $this->assertSame(1, FightPlayerState::load($this->db, $fightid, self::MONSTER)->player_party);
        foreach (array_keys($fight->parties[0]->teams) as $teamid) {
            $this->assertContains((int) $teamid, [$mine->teamid, $ally->teamid]);
        }
        $this->assertFalse(MonsterQueue::is_queued($this->db, self::MONSTER));
    }

    public function test_a_practice_fight_that_cannot_start_says_why_instead_of_just_ending_the_wait() {
        $this->reset_monster_side();
        $team = $this->team_for(self::MONSTER, 'Imps');
        $now = time();
        MonsterQueue::join($this->db, self::MONSTER, $now);
        // No team left to draw from by the time the wait runs out.
        $team->change_object_state($this->db, ObjectState::INACTIVE);
        $this->assertSame(['state' => 'idle', 'problem' => 'no_teams'],
            MonsterQueue::poll($this->db, self::MONSTER, $now + MonsterQueue::FALLBACK_SECONDS));
        $this->assertFalse(MonsterQueue::is_queued($this->db, self::MONSTER));
    }

    public function test_a_practice_fight_that_fails_to_start_is_logged_and_reported() {
        $this->reset_monster_side();
        $this->team_for(self::MONSTER, 'Imps');
        $now = time();
        MonsterQueue::join($this->db, self::MONSTER, $now);
        // Saving the fight breaks (as an unsigned fight_players.teamid
        // did on MySQL, before admin/upgrade/fight-schema-v12.php).
        $this->db->prepare("DROP TABLE fights")->execute();
        GameLog::use_database($this->db);
        try {
            $answer = MonsterQueue::poll($this->db, self::MONSTER, $now + MonsterQueue::FALLBACK_SECONDS);
        } finally {
            GameLog::use_database(null);
            FIGHT::ensure_table_exists($this->db, true);
        }
        $this->assertSame(['state' => 'idle', 'problem' => 'failed'], $answer);
        $logged = GameLog::search($this->db, ['category' => 'server_error']);
        $this->assertCount(1, $logged);
        $this->assertSame('exception', $logged[0]['kind']);
    }

    public function test_a_practice_fight_changes_nothing_about_the_real_heroes() {
        $this->reset_monster_side();
        $mine = $this->team_for(self::MONSTER, 'Imps');
        $before = HERO::load_character($this->db, $mine->characters[0]->charid);
        $fightid = PracticeFight::start($this->db, self::MONSTER);
        $this->db->prepare("UPDATE fight_semaphore SET state = ? WHERE fightid = ?")->execute([FightStatus::Resolved->value, $fightid]);

        $fight = FIGHT::load_current($this->db, $fightid);
        $fight->process_fight_conclusion($this->db);
        $after = HERO::load_character($this->db, $mine->characters[0]->charid);
        $this->assertSame([$before->exp, $before->current], [$after->exp, $after->current]);
        $this->assertSame(0, TEAM::load($this->db, $mine->teamid)->gold);
        $this->assertSame([], FightSave::load_player_history_fightids($this->db, $mine->teamid));
    }

    // --- rounds ---

    public function test_letting_a_round_pass_hands_the_monsters_to_the_ai_until_the_player_acts() {
        $this->reset_monster_side();
        $this->team_for(self::MONSTER, 'Imps');
        MonsterQueue::join($this->db, self::MONSTER);
        [$fightid] = $this->start_fight_with_queue();
        $hero = new FightCommunicator($this->db, self::HERO);
        $monster = new FightCommunicator($this->db, self::MONSTER);

        $this->assertSame('waiting', $hero->confirm_round($this->sequence($fightid))['status'], 'waits for the monster player');
        $this->expire_round($fightid);
        $this->assertSame('processed', $hero->check_timeout($this->sequence($fightid))['status']);
        $seat = MonsterSeat::load($this->db, $fightid, self::MONSTER);
        $this->assertSame([false, 1], [$seat->present, $seat->abandoned_rounds]);

        if (FightSemaphore::load($this->db, $fightid)->state === FightStatus::Resolved) {
            $this->markTestIncomplete('The fight ended in the first round.');
        }
        $this->assertSame('processed', $hero->confirm_round($this->sequence($fightid))['status'], 'no longer waits for them');

        if (FightSemaphore::load($this->db, $fightid)->state !== FightStatus::Resolved) {
            $this->assertTrue($monster->submit_character_command(1, 0, 0, [0, [0, 0, 0], 0]));
            $this->assertTrue(MonsterSeat::load($this->db, $fightid, self::MONSTER)->present, 'acting brings them back');
        }
    }

    public function test_loading_the_fight_again_brings_an_absent_player_back() {
        $this->reset_monster_side();
        $this->team_for(self::MONSTER, 'Imps');
        MonsterQueue::join($this->db, self::MONSTER);
        [$fightid] = $this->start_fight_with_queue();
        $seat = MonsterSeat::load($this->db, $fightid, self::MONSTER);
        $seat->present = false;
        $seat->save($this->db);
        $this->assertTrue(MonsterSide::rejoin($this->db, $fightid, self::MONSTER));
        $this->assertFalse(MonsterSide::rejoin($this->db, $fightid, self::MONSTER), 'already back');
    }

    public function test_a_fight_played_through_earns_the_monster_player_a_token() {
        $this->reset_monster_side();
        $this->team_for(self::MONSTER, 'Imps');
        MonsterQueue::join($this->db, self::MONSTER);
        [$fightid] = $this->start_fight_with_queue();
        $hero = new FightCommunicator($this->db, self::HERO);
        $monster = new FightCommunicator($this->db, self::MONSTER);
        for ($round = 0; $round < 30 && FightSemaphore::load($this->db, $fightid)->state !== FightStatus::Resolved; $round++) {
            $sequence = $this->sequence($fightid);
            $monster->submit_character_command(1, 0, 0, [0, [0, 0, 0], 0]);
            $monster->confirm_round($sequence);
            $hero->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
            $hero->confirm_round($sequence);
        }
        $this->assertSame(FightStatus::Resolved, FightSemaphore::load($this->db, $fightid)->state);
        $this->assertSame(0, MonsterSeat::load($this->db, $fightid, self::MONSTER)->abandoned_rounds);
        $this->assertGreaterThanOrEqual(1, MonsterToken::balance($this->db, self::MONSTER));

        // The heroes beat a player's monsters: any overkill they dealt
        // earns them bonus XP, and the win is their team's first
        // underdog and overkill record.
        $messages = array_merge(...array_map(fn($a) => $a->action_data, FightAction::load_all_by_fightid($this->db, $fightid)));
        $dialog = implode('', array_map(fn($m) => $m[1][0], array_filter($messages, fn($m) => $m[0] === 'FightDialog')));
        preg_match('/has earned (\d+) XP/', $dialog, $earned);
        $share = min(FightCommunicator::XP_BONUS_CAP, (FightAction::overkill_taken($messages)[1] ?? 0) / 30);
        $bonus = (int) floor((int) $earned[1] * $share);
        if ($bonus > 0) {
            $this->assertStringContainsString("earns $bonus more for beating a player's monsters", $dialog);
        } else {
            $this->assertStringNotContainsString("for beating a player's monsters", $dialog, 'nothing to add once rounded down');
        }
        $teamid = FightPlayerState::load($this->db, $fightid, self::HERO)->teamid;
        $this->assertNotNull(FightSave::load($this->db, $fightid, $teamid, 'underdog'));
    }

    // --- settling ---

    private function seat(int $playerid, bool $present, int $abandoned, bool $admin_used = false): void {
        (new MonsterSeat(500, $playerid, 1, $present, $abandoned, 3, $admin_used))->save($this->db);
    }

    public function test_settling_gives_tokens_by_the_rules() {
        $this->reset_monster_side();
        $this->seat(1, true, 0);          // played it all, won
        $this->seat(2, true, 2);          // missed rounds, back for the win
        $this->seat(3, true, 0);          // played it all -- see the loss below
        $this->seat(4, false, 1);         // gone at the end
        $given = MonsterSide::settle($this->db, 500, [0 => false, 1 => true]);
        $this->assertSame([1 => 2, 2 => 1, 3 => 2, 4 => 0], $given);

        $this->reset_monster_side();
        $this->seat(3, true, 0);
        $this->assertSame([3 => 1], MonsterSide::settle($this->db, 500, [0 => true, 1 => false]), 'played, lost');
    }

    public function test_leaving_a_fight_the_monsters_lose_locks_the_player_out_but_not_an_admin() {
        $this->reset_monster_side();
        $this->seat(4, false, 1);
        $this->seat(5, false, 1);
        $this->seat(6, true, 1);   // came back: no lockout
        PermissionGrant::grant($this->db, 5, 'PLAY_AGAINST');
        $now = 1000000;
        MonsterSide::settle($this->db, 500, [0 => true, 1 => false], $now);
        $this->assertSame($now + MonsterLockout::SECONDS, MonsterLockout::until($this->db, 4));
        $this->assertSame(0, MonsterLockout::until($this->db, 5));
        $this->assertSame(0, MonsterLockout::until($this->db, 6));
    }

    public function test_an_admin_power_voids_the_tokens() {
        $this->reset_monster_side();
        $this->seat(7, true, 0, admin_used: true);
        $this->assertSame([7 => 0], MonsterSide::settle($this->db, 500, [0 => false, 1 => true]));
    }

    // --- admins ---

    public function test_an_admin_takes_over_monsters_no_one_is_playing_and_can_hand_them_back() {
        $this->reset_monster_side();
        [$fightid] = $this->start_fight_with_queue();   // no one queued: the AI's
        $this->assertNull(MonsterSide::take_over($this->db, $fightid, 5));
        $seat = MonsterSeat::load($this->db, $fightid, 5);
        $this->assertTrue($seat->admin_used);
        $this->assertSame(CHARACTER::MONSTER_TEAMID, FightPlayerState::load($this->db, $fightid, 5)->teamid);
        $this->assertNotNull(MonsterSide::take_over($this->db, $fightid, 6), 'someone is playing them now');

        MonsterSide::leave_to_spectate($this->db, $fightid, 5);
        $this->assertNull(FightPlayerState::load($this->db, $fightid, 5));
        $this->assertFalse(MonsterSeat::load($this->db, $fightid, 5)->present, 'kept as the record a player was there');
        $this->assertSame([1], MonsterSide::touched_party_indexes($this->db, $fightid));
        $this->assertNull(MonsterSide::take_over($this->db, $fightid, 6), 'free again');
    }

    public function test_an_admin_cannot_take_over_a_human_players_monsters() {
        $this->reset_monster_side();
        $this->team_for(self::MONSTER, 'Imps');
        MonsterQueue::join($this->db, self::MONSTER);
        [$fightid] = $this->start_fight_with_queue();
        $this->assertNotNull(MonsterSide::take_over($this->db, $fightid, 5));
    }
}
