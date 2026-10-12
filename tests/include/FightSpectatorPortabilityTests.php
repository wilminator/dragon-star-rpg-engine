<?php

/**
 * Covers FightSpectator: who may watch a live fight, from which party,
 * and what they're sent. Also covers the spectatable flag travelling
 * from FIGHT's constructor through start_fight() to the semaphore.
 *
 * Fixture: party 0 is a hero team (player 42, team 5), party 1 is a
 * player-controlled monster party (player 43), party 2 is AI-only.
 */
trait FightSpectatorPortabilityTests {

    private function spectator_party(string $name, int $teamid): PARTY {
        $character = CHARACTER::create_hero($name, 999, 1, null);
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

    private function make_spectator_fight(bool $spectatable): int {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);

        $fight = new FIGHT('bg', 'music', $spectatable);
        $heroes = $this->spectator_party('Hero', 5);
        $heroes->teams[5] = [0];
        $fight->add($heroes);
        $fight->add($this->spectator_party('PlayerMonster', CHARACTER::MONSTER_TEAMID));
        $fight->add($this->spectator_party('AiMonster', CHARACTER::MONSTER_TEAMID));

        return FightCommunicator::start_fight($this->db, $fight, [
            ['playerid' => 42, 'teamid' => 5, 'player_party' => 0],
            ['playerid' => 43, 'teamid' => CHARACTER::MONSTER_TEAMID, 'player_party' => 1],
        ]);
    }

    private function advance_to_round(int $fightid, int $sequence, FightStatus $state = FightStatus::WaitingForInput): void {
        FightSemaphore::claim($this->db, $fightid, 42, 30)->release($this->db, $state, $sequence, 600);
    }

    // --- the spectatable flag ---

    public function test_start_fight_passes_the_spectatable_flag_to_the_semaphore() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->assertTrue(FightSemaphore::load($this->db, $fightid)->spectatable);
    }

    public function test_fights_are_not_spectatable_by_default() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class);
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->spectator_party('Hero', 5));
        $fightid = FightCommunicator::start_fight($this->db, $fight, [['playerid' => 42, 'teamid' => 5, 'player_party' => 0]]);

        $this->assertFalse(FightSemaphore::load($this->db, $fightid)->spectatable);
    }

    // --- who may watch ---

    public function test_a_non_admin_cannot_watch_a_fight_that_is_not_spectatable() {
        $fightid = $this->make_spectator_fight(spectatable: false);
        $this->assertNull(FightSpectator::open($this->db, $fightid, 0, is_admin: false));
    }

    public function test_an_admin_can_watch_a_fight_that_is_not_spectatable() {
        $fightid = $this->make_spectator_fight(spectatable: false);
        $this->assertNotNull(FightSpectator::open($this->db, $fightid, 0, is_admin: true));
    }

    public function test_a_non_admin_cannot_sit_with_a_player_controlled_monster_party() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->assertNull(FightSpectator::open($this->db, $fightid, 1, is_admin: false));
    }

    public function test_an_admin_can_sit_with_a_player_controlled_monster_party() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->assertSame(1, FightSpectator::open($this->db, $fightid, 1, is_admin: true)->party);
    }

    public function test_nobody_can_sit_with_an_ai_only_party() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->assertNull(FightSpectator::open($this->db, $fightid, 2, is_admin: false));
        $this->assertNull(FightSpectator::open($this->db, $fightid, 2, is_admin: true));
    }

    public function test_a_random_seat_is_always_a_hero_party() {
        // Only one hero party here, so every random pick must be it --
        // never the monster-controlled party, even for an admin.
        $fightid = $this->make_spectator_fight(spectatable: true);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(0, FightSpectator::open($this->db, $fightid, null, is_admin: false)->party);
            $this->assertSame(0, FightSpectator::open($this->db, $fightid, null, is_admin: true)->party);
        }
    }

    public function test_a_fight_whose_semaphore_was_purged_opens_as_a_replay() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        FightSemaphore::delete($this->db, $fightid);

        $spectator = FightSpectator::open($this->db, $fightid, 0, is_admin: false);

        $this->assertNotNull($spectator);
        $this->assertTrue($spectator->is_replay);
    }

    // --- what a spectator is sent ---

    public function test_the_view_shows_the_seated_partys_classes_and_nothing_private() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        // A pending command a spectator must never see.
        (new FightCommunicator($this->db, 42))->submit_character_command(0, 0, 0, [0, [2, 0, 0], 0]);

        $view = FightSpectator::open($this->db, $fightid, 0, is_admin: false)->get_view('spectate.php');

        $this->assertNull($view['teamid']);
        $this->assertSame(0, $view['player_party']);
        $this->assertNull($view['redirect'], 'still live');
        foreach ([$view['client_fight'], $view['client_prefight']] as $client) {
            $seated = $client['parties'][0]['groups'][0]['characters'][0];
            $this->assertSame(999, $seated['jobid']);
            foreach (['command', 'target', 'using', 'abilities', 'equipment', 'inventory'] as $private) {
                $this->assertArrayNotHasKey($private, $seated);
            }
            $this->assertArrayNotHasKey('jobid', $client['parties'][2]['groups'][0]['characters'][0]);
        }
    }

    public function test_the_view_redirects_to_the_exit_url_once_resolved() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->advance_to_round($fightid, 1, FightStatus::Resolved);

        $view = FightSpectator::open($this->db, $fightid, 0, is_admin: false)->get_view('spectate.php');

        $this->assertSame('spectate.php', $view['redirect']);
    }

    // --- polling ---

    public function test_poll_reports_waiting_with_the_timer_when_nothing_changed() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $result = FightSpectator::open($this->db, $fightid, 0, is_admin: false)->poll(0, 'spectate.php');

        $this->assertSame('waiting', $result['status']);
        $this->assertGreaterThan(0, $result['timeout']);
        $this->assertArrayNotHasKey('client_fight', $result);
    }

    public function test_poll_sends_the_new_round_once_the_sequence_advances() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->advance_to_round($fightid, 1);

        $result = FightSpectator::open($this->db, $fightid, 0, is_admin: false)->poll(0, 'spectate.php');

        $this->assertSame('updated', $result['status']);
        $this->assertSame(1, $result['sequence']);
        $this->assertArrayHasKey('client_fight', $result);
    }

    public function test_a_live_spectator_polling_past_the_final_round_gets_only_that_round() {
        // The spectator was watching live when the fight resolved: the
        // final round, not the whole fight again.
        $fightid = $this->play_to_resolution();
        $last = count(FightAction::load_all_by_fightid($this->db, $fightid));

        $result = $this->open_replay($fightid)->poll($last - 1, 'spectate.php');

        $this->assertSame('updated', $result['status']);
        $this->assertSame(
            FightAction::filter_for_client(FightAction::load($this->db, $fightid, $last)->action_data, null),
            $result['action_list']
        );
        $this->assertSame('spectate.php', $result['redirect']);
    }

    public function test_poll_reports_ended_once_the_final_round_has_been_seen() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->advance_to_round($fightid, 1, FightStatus::Resolved);

        $result = FightSpectator::open($this->db, $fightid, 0, is_admin: false)->poll(1, 'spectate.php');

        $this->assertSame(['status' => 'ended', 'redirect' => 'spectate.php'], $result);
    }

    public function test_poll_never_processes_an_expired_round() {
        // A spectator's poll must not do what check_fight_timeout does:
        // even with the round's timer long expired, nothing advances.
        $fightid = $this->make_spectator_fight(spectatable: true);
        FightSemaphore::tighten_timeout($this->db, $fightid, time() - 600);

        FightSpectator::open($this->db, $fightid, 0, is_admin: false)->poll(0, 'spectate.php');

        $this->assertSame(0, FightSemaphore::load($this->db, $fightid)->sequence);
        $this->assertNull(FightAction::load($this->db, $fightid, 1));
    }

    // --- listing (spectate.php, admin/active_fights.php) ---

    public function test_the_public_list_shows_only_spectatable_live_fights_and_only_hero_parties() {
        $open = $this->make_spectator_fight(spectatable: true);
        $this->reset_tables(TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 42, 'Dragon Slayers', []);
        $fight = new FIGHT('bg', 'music', false);
        $fight->add($this->spectator_party('Owl', $team->teamid));
        FightCommunicator::start_fight($this->db, $fight, [['playerid' => 42, 'teamid' => $team->teamid, 'player_party' => 0]]);

        $list = FightSpectator::list_watchable($this->db, is_admin: false);

        $this->assertCount(1, $list, 'the admin-only fight is not listed');
        $this->assertSame(FIGHT::load($this->db, $open)->guid, $list[0]['guid']);
        $this->assertSame([0], array_column($list[0]['parties'], 'index'), 'the monster-controlled and AI parties are not listed');
    }

    public function test_the_admin_list_shows_every_live_fight_and_monster_controlled_parties() {
        $this->make_spectator_fight(spectatable: false);

        $list = FightSpectator::list_watchable($this->db, is_admin: true);

        $this->assertCount(1, $list);
        $this->assertFalse($list[0]['spectatable']);
        $this->assertSame([0, 1], array_column($list[0]['parties'], 'index'), 'never the AI-only party 2');
        $this->assertFalse($list[0]['parties'][0]['monster_controlled']);
        $this->assertTrue($list[0]['parties'][1]['monster_controlled']);
    }

    public function test_the_list_shows_each_partys_name_and_its_player_teams_names() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class, TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 42, 'Dragon Slayers', []);
        $party = $this->spectator_party('Hero', $team->teamid);
        $party->name = "Alice's party";
        $fight = new FIGHT('bg', 'music', true);
        $fight->add($party);
        FightCommunicator::start_fight($this->db, $fight, [['playerid' => 42, 'teamid' => $team->teamid, 'player_party' => 0]]);

        $listed = FightSpectator::list_watchable($this->db, is_admin: false)[0]['parties'][0];

        $this->assertSame("Alice's party", $listed['name']);
        $this->assertSame(['Dragon Slayers'], $listed['teams']);
    }

    public function test_resolved_fights_are_not_listed() {
        $fightid = $this->make_spectator_fight(spectatable: true);
        $this->advance_to_round($fightid, 1, FightStatus::Resolved);

        $this->assertSame([], FightSpectator::list_watchable($this->db, is_admin: true));
    }

    // --- replays (resolved fights) ---

    // The guid of the team play_to_resolution() fought with.
    private ?string $replay_team_guid = null;

    // Player 42's team against a sturdy AI opponent: several rounds to
    // resolve, so a replay has more than one round to join together.
    private function play_to_resolution(bool $spectatable = false): int {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class, FightSave::class, TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 42, 'Replayers', []);
        $this->replay_team_guid = $team->guid;

        $hero = $this->spectator_party('Hero', $team->teamid);
        $strong = ['HP' => 500, 'MP' => 10, 'Speed' => 50, 'Accuracy' => 100, 'Strength' => 30, 'Dodge' => 10, 'Block' => 10, 'Power' => 10, 'Resistance' => 10, 'Focus' => 10];
        $hero->groups[0]->characters[0]->current = $strong;
        $hero->groups[0]->characters[0]->base = $strong;

        $monster = $this->spectator_party('Sturdy', 999);
        $sturdy = ['HP' => 150, 'MP' => 0, 'Speed' => 1, 'Accuracy' => 1, 'Strength' => 1, 'Dodge' => 1, 'Block' => 1, 'Power' => 1, 'Resistance' => 10, 'Focus' => 1];
        $monster->groups[0]->characters[0]->current = $sturdy;
        $monster->groups[0]->characters[0]->base = $sturdy;

        $fight = new FIGHT('bg', 'music', $spectatable);
        $fight->add($hero);
        $fight->add($monster);
        $fightid = FightCommunicator::start_fight($this->db, $fight, [['playerid' => 42, 'teamid' => $team->teamid, 'player_party' => 0]]);

        $player = new FightCommunicator($this->db, 42);
        for ($round = 0; $round < 20; $round++) {
            $semaphore = FightSemaphore::load($this->db, $fightid);
            if ($semaphore->state === FightStatus::Resolved) {
                break;
            }
            $player->submit_character_command(0, 0, 0, [0, [1, 0, 0], 0]);
            $player->confirm_round($semaphore->sequence);
        }
        $this->assertSame(FightStatus::Resolved, FightSemaphore::load($this->db, $fightid)->state, 'the fixture fight should resolve');
        $this->assertGreaterThan(1, count(FightAction::load_all_by_fightid($this->db, $fightid)), 'the fixture should take more than one round');
        return $fightid;
    }

    // As a shared replay link opens it: by team guid, not logged in.
    private function open_replay(int $fightid): FightSpectator {
        return FightSpectator::open($this->db, $fightid, null, is_admin: false, team_guid: $this->replay_team_guid);
    }

    public function test_anyone_with_the_team_guid_can_open_a_replay_of_a_fight_that_was_not_spectatable() {
        $fightid = $this->play_to_resolution();

        $spectator = $this->open_replay($fightid);

        $this->assertNotNull($spectator);
        $this->assertTrue($spectator->is_replay);
        $this->assertSame(0, $spectator->party, "seated with the team's party");
    }

    public function test_the_team_guid_decides_the_seat_over_a_requested_party() {
        $fightid = $this->play_to_resolution(spectatable: true);

        $spectator = FightSpectator::open($this->db, $fightid, 1, is_admin: false, team_guid: $this->replay_team_guid);

        $this->assertSame(0, $spectator->party);
    }

    public function test_a_team_that_was_not_in_the_fight_opens_nothing() {
        $fightid = $this->play_to_resolution();
        $other = TEAM::create($this->db, 43, 'Bystanders', []);

        $this->assertNull(FightSpectator::open($this->db, $fightid, null, is_admin: false, team_guid: $other->guid));
        $this->assertNull(FightSpectator::open($this->db, $fightid, null, is_admin: false, team_guid: 'no-such-guid'));
    }

    public function test_a_replay_of_a_fight_that_was_not_spectatable_cannot_be_opened_by_party_number() {
        $fightid = $this->play_to_resolution();

        $this->assertNull(FightSpectator::open($this->db, $fightid, 0, is_admin: false), 'by party');
        $this->assertNull(FightSpectator::open($this->db, $fightid, null, is_admin: false), 'at random');
    }

    public function test_an_admin_can_open_any_replay_by_party_number() {
        $fightid = $this->play_to_resolution();
        $this->assertNotNull(FightSpectator::open($this->db, $fightid, 0, is_admin: true));
    }

    public function test_a_replay_of_a_spectatable_fight_opens_by_party_number_even_after_the_semaphore_is_purged() {
        $fightid = $this->play_to_resolution(spectatable: true);
        $this->assertNotNull(FightSpectator::open($this->db, $fightid, 0, is_admin: false));

        FightSemaphore::delete($this->db, $fightid);

        $this->assertNotNull(FightSpectator::open($this->db, $fightid, 0, is_admin: false), 'the flag is saved with the fight');
    }

    public function test_a_team_guid_does_not_open_a_live_fight_that_is_not_spectatable() {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class, PendingAction::class, TEAM::class, HERO::class);
        $team = TEAM::create($this->db, 42, 'Live', []);
        $fight = new FIGHT('bg', 'music');
        $fight->add($this->spectator_party('Hero', $team->teamid));
        $fightid = FightCommunicator::start_fight($this->db, $fight, [['playerid' => 42, 'teamid' => $team->teamid, 'player_party' => 0]]);

        $this->assertNull(FightSpectator::open($this->db, $fightid, null, is_admin: false, team_guid: $team->guid));
    }

    public function test_the_spectatable_flag_survives_being_saved_with_the_fight() {
        $fight = new FIGHT('bg', 'music', true);
        $fight->add($this->spectator_party('Hero', 5));

        $this->assertTrue(FIGHT::hydrate($fight->dehydrate())->spectatable);
    }

    public function test_a_fight_saved_before_the_flag_existed_reads_as_not_spectatable() {
        $fight = new FIGHT('bg', 'music', true);
        $fight->add($this->spectator_party('Hero', 5));
        $data = $fight->dehydrate();
        unset($data['spectatable']);

        $this->assertFalse(FIGHT::hydrate($data)->spectatable);
    }

    public function test_a_replay_plays_the_whole_fight_from_its_start() {
        $fightid = $this->play_to_resolution();
        $actions = FightAction::load_all_by_fightid($this->db, $fightid);

        $view = $this->open_replay($fightid)->get_view('fight_history.php');

        $expected = [];
        foreach ($actions as $action) {
            array_push($expected, ...FightAction::filter_for_client($action->action_data, null));
        }
        $this->assertSame($expected, $view['action_list']);
        $this->assertFalse(in_array('XpDebt', array_column($view['action_list'], 0), true));
        $fight = FIGHT::load_current($this->db, $fightid);
        $this->assertSame(FIGHT::hydrate($fight->initial_state)->to_client(null, 0), $view['client_prefight']);
        $this->assertSame(count($actions), $view['sequence']);
        $this->assertSame('fight_history.php', $view['redirect'], 'leaves once playback finishes');
    }

    public function test_a_replay_ends_in_the_final_state() {
        $fightid = $this->play_to_resolution();

        $view = $this->open_replay($fightid)->get_view('spectate.php');

        $monster = $view['client_fight']['parties'][1]['groups'][0]['characters'][0];
        $this->assertSame(0.0, $monster['hp_display'], 'the opponent is shown dead');
    }

    public function test_a_replay_still_works_after_the_semaphore_is_purged() {
        $fightid = $this->play_to_resolution();
        $before = $this->open_replay($fightid)->get_view('spectate.php');
        FightSemaphore::delete($this->db, $fightid);

        $after = $this->open_replay($fightid)->get_view('spectate.php');

        $this->assertSame($before, $after);
    }

    public function test_a_replay_shows_the_seated_partys_classes_and_nothing_private() {
        $fightid = $this->play_to_resolution();

        $view = $this->open_replay($fightid)->get_view('spectate.php');

        $this->assertNull($view['teamid']);
        foreach ([$view['client_fight'], $view['client_prefight']] as $client) {
            $seated = $client['parties'][0]['groups'][0]['characters'][0];
            $this->assertSame(999, $seated['jobid']);
            foreach (['command', 'target', 'using', 'abilities', 'equipment', 'inventory'] as $private) {
                $this->assertArrayNotHasKey($private, $seated);
            }
            $this->assertArrayNotHasKey('jobid', $client['parties'][1]['groups'][0]['characters'][0]);
        }
    }
}
