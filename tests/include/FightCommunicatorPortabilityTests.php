<?php

/**
 * Covers FightCommunicator::submit_character_command(): the full
 * trust-derivation + set_PC_action() + PendingAction persistence flow,
 * against real FIGHT/FightPlayerState/FightSemaphore/PendingAction
 * data -- not unit tests of any one class in isolation (those already
 * exist elsewhere), but of the sequence this class exists to get right
 * every time.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait. See FightCommunicatorPortabilitySqliteTest/
 * FightCommunicatorPortabilityMysqlTest/FightCommunicatorPortabilityPostgresTest.
 */
trait FightCommunicatorPortabilityTests {

    private function team_member_party(string $name, int $teamid): PARTY {
        $character = CHARACTER::create_hero($name, 999, 1, null); // jobid 999: doesn't need to exist, see create_hero()'s own test coverage
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

    private function target_party(): PARTY {
        return $this->team_member_party('Target', 999);
    }

    // Builds and persists a real fight with one player-controlled
    // character (teamid 5) and one target, sets up the matching
    // FightPlayerState (playerid 42) and a live (non-Resolved)
    // FightSemaphore -- the minimum a real submit_character_command()
    // call needs to succeed.
    private function make_ready_fight(): int {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);

        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', 5));
        $fight->add($this->target_party());
        $fight->save($this->db);

        (new FightPlayerState($fight->id, 42, 5, 0, false, 0))->save($this->db);
        FightSemaphore::create($this->db, $fight->id, 600);

        return $fight->id;
    }

    public function test_submit_character_command_succeeds_and_persists_a_pending_action() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);

        $result = $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);

        $this->assertTrue($result);
        $pending = PendingAction::load($this->db, $fightid, 0, 0, 0);
        $this->assertNotNull($pending);
        $this->assertSame(42, $pending->playerid);
        $this->assertSame(0, $pending->command);
        $this->assertSame([1, 0, 0], $pending->target);
    }

    public function test_submit_character_command_fails_for_a_player_not_in_any_fight() {
        $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 999999); // no FightPlayerState row at all

        $result = $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);

        $this->assertFalse($result);
    }

    public function test_submit_character_command_fails_once_the_fight_is_resolved() {
        $fightid = $this->make_ready_fight();
        $semaphore = FightSemaphore::claim($this->db, $fightid, 42, 30);
        $semaphore->release($this->db, FightStatus::Resolved, 1, 600);
        $comm = new FightCommunicator($this->db, 42);

        $result = $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);

        $this->assertFalse($result);
    }

    public function test_submit_character_command_rejects_a_character_belonging_to_another_team() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);

        // party 1 is the target party (teamid 999) -- not player 42's
        // own team (5). The trusted teamid comes from
        // FightPlayerState, not from anything in this call.
        $result = $comm->submit_character_command(1, 0, 0, [0, [1, 0, 0], 0]);

        $this->assertFalse($result);
        $this->assertNull(PendingAction::load($this->db, $fightid, 1, 0, 0));
    }

    // --- monster controllers ---
    // Two monster parties both carry CHARACTER::MONSTER_TEAMID, so the
    // team check alone can't tell them apart. The party a player may
    // command comes from their own fight_players row.

    private function make_monster_vs_monster_fight(): int {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);

        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('MineMonster', CHARACTER::MONSTER_TEAMID));
        $fight->add($this->team_member_party('TheirMonster', CHARACTER::MONSTER_TEAMID));
        $fight->save($this->db);

        // Player 42 controls party 0 as monsters.
        (new FightPlayerState($fight->id, 42, CHARACTER::MONSTER_TEAMID, 0, false, 0))->save($this->db);
        FightSemaphore::create($this->db, $fight->id, 600);

        return $fight->id;
    }

    public function test_submit_character_command_lets_a_monster_controller_command_their_own_party() {
        $fightid = $this->make_monster_vs_monster_fight();
        $comm = new FightCommunicator($this->db, 42);

        $result = $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);

        $this->assertTrue($result);
        $this->assertNotNull(PendingAction::load($this->db, $fightid, 0, 0, 0));
    }

    public function test_submit_character_command_rejects_a_monster_controller_commanding_another_monster_party() {
        $fightid = $this->make_monster_vs_monster_fight();
        $comm = new FightCommunicator($this->db, 42);

        // Party 1's monster carries the same MONSTER_TEAMID, so only the
        // player_party check stops this.
        $result = $comm->submit_character_command(1, 0, 0, [0, [0, 0, 0], 0]);

        $this->assertFalse($result);
        $this->assertNull(PendingAction::load($this->db, $fightid, 1, 0, 0));
    }

    public function test_player_controlled_monster_parties_finds_monster_controllers_only() {
        // Regression: this used to look for teamid === 0, which no real
        // monster controller ever has (they're registered with
        // MONSTER_TEAMID, -1), so their monsters were given AI actions
        // every round, overwriting the player's own commands.
        $states = [
            new FightPlayerState(1, 42, 5, 0, false, 0),                             // hero player, party 0
            new FightPlayerState(1, 43, CHARACTER::MONSTER_TEAMID, 1, false, 0),     // monster player, party 1
        ];

        $this->assertSame([1], FightCommunicator::player_controlled_monster_parties($states));
    }

    public function test_player_controlled_monster_parties_is_empty_for_a_heroes_only_fight() {
        $states = [new FightPlayerState(1, 42, 5, 0, false, 0)];

        $this->assertSame([], FightCommunicator::player_controlled_monster_parties($states));
    }

    public function test_submit_character_command_rejects_out_of_bounds_indices() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);

        $result = $comm->submit_character_command(0, 0, 99, [0, [1, 0, 0], 0]);

        $this->assertFalse($result);
        $this->assertNull(PendingAction::load($this->db, $fightid, 0, 0, 99));
    }

    public function test_submitting_a_second_command_overwrites_the_first_pending_action() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);
        $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);

        $comm->submit_character_command(0, 0, 0, [1, [1, 0, 0], 0]);

        $pending = PendingAction::load($this->db, $fightid, 0, 0, 0);
        $this->assertSame(1, $pending->command);
        $all = PendingAction::load_all_by_fightid($this->db, $fightid);
        $this->assertCount(1, $all);
    }

    // --- confirm_round() / check_timeout() / process_round() ---
    // make_ready_fight()'s target_party() (teamid 999) doubles as the
    // NPC opponent here: not in $pc_teams, so process_round() gives it
    // a real AI-driven turn -- these tests exercise do_combat() for
    // real, not a stub.

    public function test_confirm_round_rejects_a_stale_sequence_number() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);

        $result = $comm->confirm_round(5); // fight's actual sequence is 0

        $this->assertSame('stale', $result['status']);
        $this->assertSame(0, $result['sequence']);
        $this->assertSame($fightid, $result['fightid']);
    }

    public function test_confirm_round_fails_for_a_player_not_in_any_fight() {
        $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 999999);

        $result = $comm->confirm_round(0);

        $this->assertSame('not_in_fight', $result['status']);
    }

    public function test_confirm_round_waits_when_other_players_have_not_confirmed_yet() {
        $fightid = $this->make_ready_fight();
        (new FightPlayerState($fightid, 43, 5, 1, false, 0))->save($this->db); // a second player, hasn't confirmed
        $comm = new FightCommunicator($this->db, 42);

        $result = $comm->confirm_round(0);

        $this->assertSame('waiting', $result['status']);
        $semaphore = FightSemaphore::load($this->db, $fightid);
        $this->assertSame(FightStatus::WaitingForInput, $semaphore->state); // not claimed
    }

    public function test_confirm_round_processes_the_turn_once_everyone_has_confirmed() {
        $fightid = $this->make_ready_fight(); // single player (42), so confirming is also "everyone"
        $comm = new FightCommunicator($this->db, 42);
        $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);

        $result = $comm->confirm_round(0);

        $this->assertSame('processed', $result['status']);
        $this->assertSame($fightid, $result['fightid']);
        $this->assertSame(1, $result['sequence']);
        $this->assertGreaterThan(0, count($result['action_list']));

        $fight_action = FightAction::load($this->db, $fightid, 1);
        $this->assertNotNull($fight_action);
        $semaphore = FightSemaphore::load($this->db, $fightid);
        $this->assertSame(1, $semaphore->sequence);
        $this->assertNull($semaphore->lock_token); // released, not left claimed
    }

    public function test_check_timeout_reports_waiting_before_the_deadline() {
        $this->make_ready_fight(); // create()'s own 10-minute default timeout hasn't passed
        $comm = new FightCommunicator($this->db, 42);

        $result = $comm->check_timeout(0);

        $this->assertSame('waiting', $result['status']);
        $this->assertArrayHasKey('seconds_remaining', $result);
    }

    public function test_check_timeout_processes_the_turn_once_expired() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);
        $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET timeout = ? WHERE fightid = ?");
        $stmt->execute([time() - 1, $fightid]);

        $result = $comm->check_timeout(0);

        $this->assertSame('processed', $result['status']);
        $this->assertNotNull(FightAction::load($this->db, $fightid, 1));
    }

    public function test_check_timeout_does_not_double_process_a_fight_already_claimed() {
        $fightid = $this->make_ready_fight();
        $stmt = $this->db->prepare("UPDATE fight_semaphore SET timeout = ? WHERE fightid = ?");
        $stmt->execute([time() - 1, $fightid]);
        FightSemaphore::claim($this->db, $fightid, 999, 30); // some other request already won the race

        $comm = new FightCommunicator($this->db, 42);
        $result = $comm->check_timeout(0);

        $this->assertSame('waiting', $result['status']);
    }

    public function test_check_timeout_reports_stale_when_the_round_has_already_moved_on() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);
        $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
        $comm->confirm_round(0); // advances the fight to sequence 1

        $result = $comm->check_timeout(0); // client still thinks it's sequence 0

        $this->assertSame('stale', $result['status']);
        $this->assertSame($fightid, $result['fightid']);
        $this->assertSame(1, $result['sequence']);
    }

    // --- full play-through ---
    // Everything above exercises exactly one round in isolation. A
    // real fight is many rounds, with multiple players confirming
    // independently, ending only when one side is actually wiped out
    // -- none of which any single-round test touches. This plays a
    // real fight, through the full FightCommunicator surface only (no
    // direct FIGHT/FightAction manipulation), until it resolves.

    private function heavily_favored_character(string $name, int $teamid): CHARACTER {
        $character = CHARACTER::create_hero($name, 999, 1, null);
        $character->teamid = $teamid;
        // Deliberately lopsided, not realistic: this test's only
        // purpose is confirming a fight can be played to resolution
        // through the new classes, not exercising combat balance --
        // heavily favoring the two PCs keeps it short and reliably
        // terminating rather than a long, luck-dependent slog.
        $stats = ['HP' => 200, 'MP' => 20, 'Speed' => 20, 'Accuracy' => 200, 'Strength' => 50, 'Dodge' => 50, 'Block' => 50, 'Power' => 50, 'Resistance' => 50, 'Focus' => 50];
        $character->current = $stats;
        $character->base = $stats;
        return $character;
    }

    public function test_full_play_through_two_players_versus_an_npc_reaches_resolution() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class, FightSave::class);

        // party 0: both players' own characters, sharing one team --
        // count_live_parties() counts parties, not teams, so this
        // (rather than 3 separate parties) is what makes "the NPC
        // party dies" actually trigger resolution at exactly 2 total
        // parties.
        $group = new GROUP();
        $group->add_hero($this->heavily_favored_character('Hero A', 5));
        $group->add_hero($this->heavily_favored_character('Hero B', 5));
        $party_pcs = new PARTY();
        $party_pcs->add($group);

        $npc = CHARACTER::create_hero('Weak Monster', 999, 1, null);
        $npc->teamid = 999;
        $npc->current = ['HP' => 1, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 1, 'Focus' => 1];
        $npc->base = $npc->current;
        $npc_group = new GROUP();
        $npc_group->add_hero($npc);
        $party_npc = new PARTY();
        $party_npc->add($npc_group);

        $fight = new FIGHT('bg', 'music');
        $fight->add($party_pcs);
        $fight->add($party_npc);
        $fight->save($this->db);
        $fightid = $fight->id;

        (new FightPlayerState($fightid, 42, 5, 0, false, 0))->save($this->db);
        (new FightPlayerState($fightid, 43, 5, 0, false, 0))->save($this->db);
        FightSemaphore::create($this->db, $fightid, 600);

        $comm_a = new FightCommunicator($this->db, 42);
        $comm_b = new FightCommunicator($this->db, 43);

        $rounds_played = 0;
        $max_rounds = 20;
        $resolved = false;
        while ($rounds_played < $max_rounds) {
            $sequence = FightSemaphore::load($this->db, $fightid)->sequence;

            // Both players attack the NPC -- character 1's own target
            // (party 1, group 0, char 0) never moves regardless of who
            // else has died on its own side, so this stays valid
            // across every round.
            $submitted_a = $comm_a->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
            $submitted_b = $comm_b->submit_character_command(0, 0, 1, [0, [1, 0, 0], 0]);
            $this->assertTrue($submitted_a, "player A's command rejected on round $rounds_played");
            $this->assertTrue($submitted_b, "player B's command rejected on round $rounds_played");

            $first = $comm_a->confirm_round($sequence);
            $this->assertSame('waiting', $first['status'], "expected player A's confirmation alone to still be waiting on round $rounds_played");

            $second = $comm_b->confirm_round($sequence);
            $this->assertSame('processed', $second['status'], "expected the round to process once both confirmed, round $rounds_played");

            $rounds_played++;

            $semaphore = FightSemaphore::load($this->db, $fightid);
            if ($semaphore->state === FightStatus::Resolved) {
                $resolved = true;
                break;
            }
        }

        $this->assertTrue($resolved, "fight did not reach Resolved within $max_rounds rounds");

        $final = FIGHT::load_current($this->db, $fightid);
        $this->assertSame(0, $final->parties[1]->groups[0]->characters[0]->current['HP'], "expected the NPC to have died");
        $this->assertSame(1, $final->count_live_parties());

        // The action log genuinely accumulated one FightAction per
        // round played, not just the last one.
        $all_actions = FightAction::load_all_by_fightid($this->db, $fightid);
        $this->assertCount($rounds_played, $all_actions);

        // Both players' FightPlayerState correctly tracked every round,
        // not just the first.
        $state_a = FightPlayerState::load($this->db, $fightid, 42);
        $state_b = FightPlayerState::load($this->db, $fightid, 43);
        $this->assertSame($rounds_played, $state_a->sequence);
        $this->assertSame($rounds_played, $state_b->sequence);

        // record_user_history() actually ran once the fight resolved --
        // confirmed missing entirely from any real gameplay path before
        // this was wired in. Both players share teamid 5 here, so this
        // also confirms the team only gets tagged once, not twice.
        $this->assertNotNull(FightSave::load($this->db, $fightid, 5, 'user'));
        // A won PvE fight is also the team's first underdog and overkill
        // record -- each once, though two players share the team.
        $types = array_map(fn($save) => $save->type, FightSave::load_all_by_fightid($this->db, $fightid));
        sort($types);
        $this->assertSame(['overkill', 'underdog', 'user'], $types);
    }

    // --- is_in_active_fight() / get_fight_view() ---

    public function test_is_in_active_fight_true_when_in_one() {
        $this->make_ready_fight();

        $this->assertTrue((new FightCommunicator($this->db, 42))->is_in_active_fight());
    }

    public function test_is_in_active_fight_false_when_not_in_one() {
        $this->make_ready_fight();

        $this->assertFalse((new FightCommunicator($this->db, 999999))->is_in_active_fight());
    }

    public function test_get_fight_view_returns_null_when_not_in_a_fight() {
        $this->reset_tables(FightPlayerState::class, FightSemaphore::class);

        $this->assertNull((new FightCommunicator($this->db, 999999))->get_fight_view());
    }

    public function test_get_fight_view_on_a_fresh_fight_has_no_action_list_yet() {
        $fightid = $this->make_ready_fight();

        $view = (new FightCommunicator($this->db, 42))->get_fight_view();

        $this->assertSame($fightid, $view['fightid']);
        $this->assertSame(5, $view['teamid']);
        $this->assertSame(0, $view['sequence']);
        $this->assertSame(FightStatus::WaitingForInput, $view['active']);
        // Empty, not null: the client-side JS always calls .length on
        // this with no null-handling of its own (confirmed by a real
        // client crash otherwise -- TypeError reading 'length' of
        // null).
        $this->assertSame([], $view['action_list']);
        $this->assertSame($view['fight']->initial_state, $view['prefight']);
        $this->assertInstanceOf(FIGHT::class, $view['fight']);
        $this->assertCount(1, $view['players']); // only player 42 in make_ready_fight()'s fixture
        // client_fight/client_prefight: the sanitized projections
        // actually sent to the client, distinct from the raw fight/
        // prefight above -- own team's own character has a real,
        // present target (a raw, dehydrated prefight never carries
        // target at all, for any team, since it isn't part of
        // CHARACTER::DEHYDRATE_PROPERTIES).
        $own_client_char = $view['client_fight']['parties'][0]['groups'][0]['characters'][0];
        $this->assertArrayHasKey('target', $own_client_char);
        $own_client_prefight_char = $view['client_prefight']['parties'][0]['groups'][0]['characters'][0];
        $this->assertArrayHasKey('target', $own_client_prefight_char);
    }

    public function test_get_fight_view_redirect_is_null_for_an_active_player_in_an_unresolved_fight() {
        $_SESSION = [];
        $fightid = $this->make_ready_fight();

        $view = (new FightCommunicator($this->db, 42))->get_fight_view();

        $this->assertNull($view['redirect'], 'still playing -- nowhere to send them yet');
    }

    public function test_get_fight_view_sends_an_active_player_back_to_the_hub_once_resolved() {
        $_SESSION = [];
        $fightid = $this->make_ready_fight();
        $semaphore = FightSemaphore::claim($this->db, $fightid, 42, 30);
        $semaphore->release($this->db, FightStatus::Resolved, 1, 600);

        $view = (new FightCommunicator($this->db, 42))->get_fight_view($fightid);

        $this->assertSame('app.php', $view['redirect']);
        // The real player's own data -- they earned this by actually playing.
        $this->assertNotNull($view['client_fight']);
    }

    public function test_get_fight_view_sends_a_monster_player_back_to_team_selection_once_resolved() {
        $_SESSION = [];
        $fightid = $this->make_monster_vs_monster_fight();
        $semaphore = FightSemaphore::claim($this->db, $fightid, 42, 30);
        $semaphore->release($this->db, FightStatus::Resolved, 1, 600);

        $view = (new FightCommunicator($this->db, 42))->get_fight_view($fightid);

        $this->assertSame('pick_team.php', $view['redirect'], 'they queued from there, and may have no team in the hub');
    }

    public function test_get_fight_view_after_a_round_shows_the_last_actions_and_correct_prefight() {
        $fightid = $this->make_ready_fight();
        $comm = new FightCommunicator($this->db, 42);
        $comm->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
        $comm->confirm_round(0);

        $view = $comm->get_fight_view();

        $this->assertSame(1, $view['sequence']);
        $this->assertNotNull($view['action_list']);
        $this->assertGreaterThan(0, count($view['action_list']));
        // Sequence 1's prefight is the fight's own initial_state (the
        // very first round has no earlier FightAction to draw from).
        $first_action = FightAction::load($this->db, $fightid, 1);
        $this->assertSame($view['fight']->initial_state, $view['prefight']);
        // action_list is the client-filtered version (XpDebt stripped
        // -- see FightAction::filter_for_client()), not the raw saved
        // action_data directly; the raw data may include XpDebt
        // messages the saved FightAction needs for replay but a client
        // should never see.
        $this->assertSame(FightAction::filter_for_client($first_action->action_data, 5), $view['action_list']);
        // client_prefight, hydrated from the same raw prefight, still
        // has a real target for the requesting team's own character.
        $own_client_prefight_char = $view['client_prefight']['parties'][0]['groups'][0]['characters'][0];
        $this->assertArrayHasKey('target', $own_client_prefight_char);
    }

    // --- get_fight_status() ---

    public function test_get_fight_status_with_no_argument_finds_the_active_fight() {
        $fightid = $this->make_ready_fight();

        $status = (new FightCommunicator($this->db, 42))->get_fight_status();

        $this->assertSame($fightid, $status['fightid']);
        $this->assertSame(5, $status['teamid']);
        $this->assertSame(0, $status['player_party']);
        $this->assertTrue($status['active']);
    }

    public function test_get_fight_status_returns_null_with_no_argument_and_no_active_fight() {
        $this->reset_tables(FightPlayerState::class, FightSemaphore::class);

        $this->assertNull((new FightCommunicator($this->db, 999999))->get_fight_status());
    }

    public function test_get_fight_status_finds_a_specific_fight_even_once_resolved() {
        $fightid = $this->make_ready_fight();
        $semaphore = FightSemaphore::claim($this->db, $fightid, 42, 30);
        $semaphore->release($this->db, FightStatus::Resolved, 1, 600);

        // is_in_active_fight()/get_fight_view() couldn't find this
        // fight anymore (it's Resolved) -- get_fight_status() with the
        // specific fightid still can, and correctly reports it as no
        // longer active.
        $comm = new FightCommunicator($this->db, 42);
        $this->assertFalse($comm->is_in_active_fight());

        $status = $comm->get_fight_status($fightid);

        $this->assertSame($fightid, $status['fightid']);
        $this->assertFalse($status['active']);
    }

    public function test_get_fight_status_rejects_a_fightid_the_player_was_never_part_of() {
        $fightid = $this->make_ready_fight();

        // playerid 999999 has no FightPlayerState row for this fight
        // at all -- $fightid supplied by a caller is never trusted on
        // its own.
        $status = (new FightCommunicator($this->db, 999999))->get_fight_status($fightid);

        $this->assertNull($status);
    }

    public function test_get_fight_view_with_a_specific_fightid_works_even_once_resolved() {
        $fightid = $this->make_ready_fight();
        $semaphore = FightSemaphore::claim($this->db, $fightid, 42, 30);
        $semaphore->release($this->db, FightStatus::Resolved, 1, 600);

        // The no-argument form can't find this fight anymore (it's
        // Resolved) -- setup_fight.php's exact situation, needing the
        // full view of a fight specifically because it just resolved.
        $comm = new FightCommunicator($this->db, 42);
        $this->assertNull($comm->get_fight_view());

        $view = $comm->get_fight_view($fightid);

        $this->assertNotNull($view);
        $this->assertSame($fightid, $view['fightid']);
        $this->assertInstanceOf(FIGHT::class, $view['fight']);
    }

    // --- start_fight() ---

    public function test_start_fight_creates_the_fight_every_players_state_and_the_semaphore_together() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('PC', 5));
        $players = [
            ['playerid' => 42, 'teamid' => 5, 'player_party' => 0],
            ['playerid' => 43, 'teamid' => 6, 'player_party' => 1],
        ];

        $fightid = FightCommunicator::start_fight($this->db, $fight, $players);

        $this->assertSame($fightid, $fight->id);
        $this->assertNotNull($fight->initial_state);

        $state_a = FightPlayerState::load($this->db, $fightid, 42);
        $this->assertSame(5, $state_a->teamid);
        $this->assertSame(0, $state_a->player_party);
        $this->assertTrue($state_a->seen);
        $this->assertSame(0, $state_a->sequence);
        $state_b = FightPlayerState::load($this->db, $fightid, 43);
        $this->assertSame(6, $state_b->teamid);

        $semaphore = FightSemaphore::load($this->db, $fightid);
        $this->assertNotNull($semaphore);
        $this->assertSame(FightStatus::WaitingForInput, $semaphore->state);
        $this->assertSame(0, $semaphore->sequence);

        // The fight is immediately usable through the normal
        // FightCommunicator surface, not just directly constructible.
        $comm = new FightCommunicator($this->db, 42);
        $this->assertTrue($comm->is_in_active_fight());
    }

    // --- simulate_round() ---

    public function test_simulate_round_ai_controls_every_character_with_no_real_players() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('A', 5));
        $fight->add($this->target_party());
        // No $players at all -- unlike every other fixture in this
        // file, this fight has zero FightPlayerState rows.
        $fightid = FightCommunicator::start_fight($this->db, $fight, []);

        $result = FightCommunicator::simulate_round($this->db, $fightid);

        // do_combat() records a turn for every living participant
        // regardless of the actual outcome (hit, miss, or a
        // non-attack action the AI happened to choose), so a non-empty
        // action_list is the reliable signal that set_npc_action() ran
        // for characters with no owning player at all -- not just the
        // ones process_fight() already excluded from pc_teams --
        // without this test depending on any specific combat result.
        $this->assertGreaterThan(0, count($result['action_list']));
        $this->assertSame(1, $result['sequence']);
    }

    public function test_simulate_round_throws_on_an_already_resolved_fight() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->team_member_party('A', 5));
        $fight->add($this->target_party());
        $fightid = FightCommunicator::start_fight($this->db, $fight, []);
        $semaphore = FightSemaphore::claim($this->db, $fightid, 0, 30);
        $semaphore->release($this->db, FightStatus::Resolved, 1, 600);

        $this->expectException(\Exception::class);
        FightCommunicator::simulate_round($this->db, $fightid);
    }

    // --- purge_ephemeral_state() ---

    public function test_purge_ephemeral_state_purges_both_tables_for_resolved_fights_only() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class, FightSave::class);
        $resolved_id = $this->make_ready_fight();
        (new PendingAction($resolved_id, 0, 0, 0, 42, 0, 0, [1, 0, 0]))->save($this->db);
        $semaphore = FightSemaphore::claim($this->db, $resolved_id, 42, 30);
        $semaphore->release($this->db, FightStatus::Resolved, 1, 600);

        $active_fight = new FIGHT('bg', 'music');
        $active_fight->add($this->team_member_party('B', 6));
        $active_fight->add($this->target_party());
        $active_id = FightCommunicator::start_fight($this->db, $active_fight, []);
        (new PendingAction($active_id, 0, 0, 0, 43, 0, 0, [1, 0, 0]))->save($this->db);

        $result = FightCommunicator::purge_ephemeral_state($this->db);

        $this->assertSame(1, $result['pending_actions_purged']);
        $this->assertSame(1, $result['semaphores_purged']);
        // The resolved fight's ephemeral state is gone...
        $this->assertNull(PendingAction::load($this->db, $resolved_id, 0, 0, 0));
        $this->assertNull(FightSemaphore::load($this->db, $resolved_id));
        // ...but the active fight's is untouched.
        $this->assertNotNull(PendingAction::load($this->db, $active_id, 0, 0, 0));
        $this->assertNotNull(FightSemaphore::load($this->db, $active_id));
    }

    // --- round deadlines and playback acknowledgement ---
    // Fallback: 150 s per party + 40 s per living member of the largest
    // player-controlled team. Once every player with a living character
    // has acknowledged playback, the deadline drops to the 40 s part.

    private function party_of_members(string $name, int $teamid, int $living, int $dead = 0): PARTY {
        $group = new GROUP();
        $stats = ['HP' => 100, 'MP' => 10, 'Speed' => 10, 'Accuracy' => 10, 'Strength' => 10, 'Dodge' => 10, 'Block' => 10, 'Power' => 10, 'Resistance' => 10, 'Focus' => 10];
        for ($i = 0; $i < $living + $dead; $i++) {
            $character = CHARACTER::create_hero("$name$i", 999, 1, null);
            $character->teamid = $teamid;
            $character->base = $stats;
            $character->current = $stats;
            if ($i >= $living) {
                $character->current['HP'] = 0;
            }
            $group->add_hero($character);
        }
        $party = new PARTY();
        $party->add($group);
        return $party;
    }

    // Party 0: player 42, team 5, 2 living + 1 dead. Party 1: player 43,
    // team 6, $team6_living living (+1 dead if $team6_living is 0).
    private function make_two_player_fight(int $team6_living = 1): int {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_of_members('A', 5, 2, 1));
        $fight->add($this->party_of_members('B', 6, $team6_living, $team6_living === 0 ? 1 : 0));
        return FightCommunicator::start_fight($this->db, $fight, [
            ['playerid' => 42, 'teamid' => 5, 'player_party' => 0],
            ['playerid' => 43, 'teamid' => 6, 'player_party' => 1],
        ]);
    }

    private function seconds_left(int $fightid): int {
        return FightSemaphore::load($this->db, $fightid)->seconds_remaining();
    }

    public function test_the_fallback_is_150_seconds_per_party_plus_40_per_member_of_the_largest_living_team() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_of_members('A', 5, 2, 1)); // 2 living count, the dead one doesn't
        $fight->add($this->party_of_members('B', 6, 1));
        $states = [new FightPlayerState(1, 42, 5, 0, true, 0), new FightPlayerState(1, 43, 6, 1, true, 0)];

        $this->assertSame(150 * 2 + 40 * 2, FightCommunicator::fallback_timeout_seconds($fight, $states));
        $this->assertSame(40 * 2, FightCommunicator::command_timeout_seconds($fight, $states));
    }

    public function test_a_monster_controller_commands_their_partys_living_monsters() {
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->party_of_members('M', CHARACTER::MONSTER_TEAMID, 3, 2));
        $state = new FightPlayerState(1, 42, CHARACTER::MONSTER_TEAMID, 0, true, 0);

        $this->assertSame(3, FightCommunicator::living_controlled_members($fight, $state));
    }

    public function test_start_fight_sets_the_fallback_deadline() {
        $fightid = $this->make_two_player_fight();
        $this->assertEqualsWithDelta(150 * 2 + 40 * 2, $this->seconds_left($fightid), 2);
    }

    public function test_the_deadline_stays_at_the_fallback_until_every_player_has_acknowledged() {
        $fightid = $this->make_two_player_fight();
        $first = new FightCommunicator($this->db, 42);

        $first->acknowledge_playback(0);
        $first->acknowledge_playback(0); // repeating it changes nothing
        $this->assertEqualsWithDelta(380, $this->seconds_left($fightid), 2, 'player 43 is still playing back');

        $result = (new FightCommunicator($this->db, 43))->acknowledge_playback(0);
        $this->assertSame('acknowledged', $result['status']);
        $this->assertEqualsWithDelta(80, $this->seconds_left($fightid), 2, 'everyone has played back: command time only');
        $this->assertEqualsWithDelta(80, $result['seconds_remaining'], 2);
    }

    public function test_a_player_with_no_living_characters_does_not_hold_up_the_deadline() {
        $fightid = $this->make_two_player_fight(team6_living: 0);

        (new FightCommunicator($this->db, 42))->acknowledge_playback(0);

        $this->assertEqualsWithDelta(80, $this->seconds_left($fightid), 2);
    }

    public function test_acknowledging_a_round_that_is_not_current_is_rejected() {
        $fightid = $this->make_two_player_fight();

        $result = (new FightCommunicator($this->db, 42))->acknowledge_playback(5);

        $this->assertSame('stale', $result['status']);
        $this->assertSame(-1, FightPlayerState::load($this->db, $fightid, 42)->played_sequence);
    }

    private function play_one_round(): int {
        $fightid = $this->make_two_player_fight();
        $a = new FightCommunicator($this->db, 42);
        $b = new FightCommunicator($this->db, 43);
        $a->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
        $a->submit_character_command(0, 0, 1, [0, [1, 0, 0], 0]);
        $b->submit_character_command(1, 0, 0, [0, [0, 0, 0], 0]);
        $a->confirm_round(0);
        $b->confirm_round(0);
        $this->assertSame(1, FightSemaphore::load($this->db, $fightid)->sequence, 'the round was processed');
        return $fightid;
    }

    public function test_processing_a_round_resets_the_deadline_to_the_fallback() {
        $fightid = $this->play_one_round();
        $fight = FIGHT::load_current($this->db, $fightid);
        $expected = FightCommunicator::fallback_timeout_seconds($fight, FightPlayerState::load_all_by_fightid($this->db, $fightid));

        $this->assertEqualsWithDelta($expected, $this->seconds_left($fightid), 2);
    }

    public function test_a_refreshed_player_who_acknowledged_the_round_starts_at_its_end_without_a_replay() {
        $fightid = $this->play_one_round();
        (new FightCommunicator($this->db, 42))->acknowledge_playback(1);

        $acked = (new FightCommunicator($this->db, 42))->get_fight_view($fightid);
        $not_acked = (new FightCommunicator($this->db, 43))->get_fight_view($fightid);

        $this->assertSame([], $acked['action_list'], 'nothing to replay');
        $this->assertSame(
            FightAction::load($this->db, $fightid, 1)->final_state['parties'][1]['groups'][0]['characters'][0]['current']['HP'],
            $acked['client_prefight']['parties'][1]['groups'][0]['characters'][0]['current']['HP'],
            'starts from the round\'s end state'
        );
        $this->assertNotSame([], $not_acked['action_list'], 'a player who has not acknowledged still gets the replay');
    }
}
