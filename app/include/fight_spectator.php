<?php
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'team.php';

// A read-only viewer of one fight: seated with one party, owning no
// team. Unlike FightCommunicator, a spectator is not a participant --
// there's no fight_players row for them -- so nothing here ever
// changes a fight, submits a command, or processes a timeout.
//
// A live fight is watched round by round. A resolved fight is a replay:
// the whole fight from its start.
//
// Where the viewer sits:
//   - by team guid: with that team's party. This is what a shared
//     replay link carries (fight_history.php hands them out), so a
//     replay of any fight can be watched without revealing party
//     numbers. It doesn't open a live fight that isn't spectatable.
//   - by party number, or at random: only for a spectatable fight, or
//     for an admin.
//
// Access is re-checked by open() on every request, never remembered.
class FightSpectator {
    private function __construct(
        private Database $db,
        public readonly int $fightid,
        public readonly int $party,
        public readonly bool $is_replay,
    ) {}

    // Parties a viewer may sit with, from the fight's own player-state
    // rows. Everyone may sit with a hero player's party; only admins may
    // sit with a party a player is controlling as monsters. AI-only
    // parties have no player-state row, so they never appear.
    public static function selectable_parties(array $player_states, bool $is_admin): array {
        $parties = [];
        foreach ($player_states as $state) {
            if ($state->teamid > 0 || ($is_admin && $state->teamid === CHARACTER::MONSTER_TEAMID)) {
                $parties[$state->player_party] = true;
            }
        }
        $parties = array_keys($parties);
        sort($parties);
        return $parties;
    }

    // Returns null when this viewer may not watch this fight from this
    // seat. $team_guid, when given, decides the seat and $requested_party
    // is ignored. Otherwise, with no $requested_party, a seat is chosen at
    // random among hero parties (admins fall back to any selectable party
    // if there are no hero parties at all). A fight with no semaphore
    // left has resolved and been purged; it opens as a replay like any
    // other resolved fight.
    public static function open(Database $db, int $fightid, ?int $requested_party, bool $is_admin, ?string $team_guid = null): ?FightSpectator {
        $semaphore = FightSemaphore::load($db, $fightid);
        $is_replay = $semaphore === null || $semaphore->state === FightStatus::Resolved;
        $spectatable = $semaphore !== null
            ? $semaphore->spectatable
            : (FIGHT::load_current($db, $fightid)?->spectatable ?? false);
        $open_seating = $is_admin || $spectatable;
        if (!$is_replay && !$open_seating) {
            return null;
        }

        $player_states = FightPlayerState::load_all_by_fightid($db, $fightid);

        if ($team_guid !== null) {
            $party = self::party_of_team($db, $player_states, $team_guid);
            return $party === null ? null : new FightSpectator($db, $fightid, $party, $is_replay);
        }
        if (!$open_seating) {
            return null;
        }

        $allowed = self::selectable_parties($player_states, $is_admin);
        if ($requested_party !== null) {
            return in_array($requested_party, $allowed, true)
                ? new FightSpectator($db, $fightid, $requested_party, $is_replay)
                : null;
        }

        $pool = self::selectable_parties($player_states, false) ?: $allowed;
        if ($pool === []) {
            return null;
        }
        return new FightSpectator($db, $fightid, $pool[array_rand($pool)], $is_replay);
    }

    // The party the team with this guid fought in, or null if that team
    // wasn't in this fight.
    private static function party_of_team(Database $db, array $player_states, string $team_guid): ?int {
        $team = TEAM::get_team_from_guid($db, $team_guid);
        if ($team === null) {
            return null;
        }
        foreach ($player_states as $state) {
            if ($state->teamid > 0 && $state->teamid === $team->teamid) {
                return $state->player_party;
            }
        }
        return null;
    }

    // What a spectator is sent on arrival: the whole fight for a replay,
    // otherwise the current round (see round_view()). $exit_url is
    // where the client goes once the fight has resolved and playback
    // has finished.
    public function get_view(string $exit_url): array {
        return $this->is_replay ? $this->replay_view($exit_url) : $this->round_view($exit_url);
    }

    // The whole fight, start to finish: the starting state, then every
    // round's actions in order, ending in the final state.
    private function replay_view(string $exit_url): array {
        $fight = FIGHT::load_current($this->db, $this->fightid);
        $sequence = 0;
        $action_list = [];
        foreach (FightAction::load_all_by_fightid($this->db, $this->fightid) as $recorded) {
            array_push($action_list, ...FightAction::filter_for_client($recorded->action_data, null));
            $sequence = max($sequence, $recorded->sequence);
        }

        return [
            'fightid' => $this->fightid,
            'teamid' => null,
            'player_party' => $this->party,
            'sequence' => $sequence,
            'active' => FightStatus::Resolved,
            'timeout' => 0,
            'redirect' => $exit_url,
            'client_prefight' => FIGHT::hydrate($fight->initial_state)->to_client(null, $this->party),
            'action_list' => $action_list,
            'client_fight' => $fight->to_client(null, $this->party),
        ];
    }

    // The same shape FightCommunicator::get_fight_view() returns to a
    // participant's client, but as a spectator sees it: team ID null
    // (owns nothing), and only the seated party's classes revealed.
    // Just the latest round -- also what a live spectator is sent for
    // the final round, rather than the whole fight again.
    private function round_view(string $exit_url): array {
        $semaphore = FightSemaphore::load($this->db, $this->fightid);
        $fight = FIGHT::load_current($this->db, $this->fightid);
        $is_resolved = $semaphore->state === FightStatus::Resolved;

        if ($semaphore->sequence === 0) {
            $prefight = $fight->initial_state;
            $action_list = [];
        } else {
            $last_action = FightAction::load($this->db, $this->fightid, $semaphore->sequence);
            $prefight = $semaphore->sequence === 1
                ? $fight->initial_state
                : FightAction::load($this->db, $this->fightid, $semaphore->sequence - 1)?->final_state;
            $action_list = FightAction::filter_for_client($last_action?->action_data ?? [], null);
        }

        return [
            'fightid' => $this->fightid,
            'teamid' => null,
            'player_party' => $this->party,
            'sequence' => $semaphore->sequence,
            'active' => $semaphore->state,
            'timeout' => $semaphore->seconds_remaining(),
            'redirect' => $is_resolved ? $exit_url : null,
            'client_prefight' => $prefight !== null ? FIGHT::hydrate($prefight)->to_client(null, $this->party) : null,
            'action_list' => $action_list,
            'client_fight' => $fight->to_client(null, $this->party),
            // The round in words, from the seated party's side.
            'round_log' => $action_list === [] ? [] : (function () use ($action_list, $prefight, $fight) {
                require_once INCLUDE_GAME . 'combat_log.php';
                return CombatLog::turns($action_list, $prefight !== null ? FIGHT::hydrate($prefight) : $fight, $this->party);
            })(),
        ];
    }

    // What a spectator's client asks while waiting for the next round,
    // instead of the participant-only check_fight_timeout (which can
    // process an expired round -- something a spectator must never do).
    //   'waiting' -- nothing new since $last_sequence; here's the timer.
    //   'updated' -- a new round; the full view, as get_view().
    //   'ended'   -- nothing more to show; go to $exit_url.
    public function poll(int $last_sequence, string $exit_url): array {
        $semaphore = FightSemaphore::load($this->db, $this->fightid);
        if ($semaphore === null) {
            return ['status' => 'ended', 'redirect' => $exit_url];
        }
        if ($semaphore->sequence === $last_sequence) {
            if ($semaphore->state === FightStatus::Resolved) {
                return ['status' => 'ended', 'redirect' => $exit_url];
            }
            return ['status' => 'waiting', 'sequence' => $semaphore->sequence, 'timeout' => $semaphore->seconds_remaining()];
        }
        return ['status' => 'updated'] + $this->round_view($exit_url);
    }

    // Every live fight this viewer may watch, for the listing pages
    // (spectate.php, admin/active_fights.php). Resolved fights are left
    // out: they're watched through their replay link instead. For each fight,
    // the parties this viewer may sit with (see selectable_parties()),
    // each with its name, its player teams' names, and whether a player
    // is controlling it as monsters (admins only ever see those).
    //
    //   [['guid' => string, 'round' => int, 'seconds_remaining' => int,
    //     'spectatable' => bool,
    //     'parties' => [['index' => int, 'name' => string,
    //                    'teams' => string[], 'monster_controlled' => bool]]]]
    public static function list_watchable(Database $db, bool $is_admin): array {
        $fights = [];
        foreach (FightSemaphore::load_all_from_database($db) as $fightid => $semaphore) {
            if ($semaphore->state === FightStatus::Resolved) {
                continue;
            }
            if (!$is_admin && !$semaphore->spectatable) {
                continue;
            }
            $player_states = FightPlayerState::load_all_by_fightid($db, $fightid);
            $selectable = self::selectable_parties($player_states, $is_admin);
            if ($selectable === []) {
                continue;
            }
            // The saved fight (its starting state) is enough here: it
            // carries the public guid, which load_current() doesn't, and
            // party names never change mid-fight.
            try {
                $fight = FIGHT::load($db, $fightid);
            } catch (Exception $e) {
                continue;
            }

            $parties = [];
            foreach ($selectable as $index) {
                $team_names = [];
                $monster_controlled = false;
                foreach ($player_states as $state) {
                    if ($state->player_party !== $index) {
                        continue;
                    }
                    if ($state->teamid === CHARACTER::MONSTER_TEAMID) {
                        $monster_controlled = true;
                        continue;
                    }
                    try {
                        $team_names[] = TEAM::load($db, $state->teamid)->name;
                    } catch (Exception $e) {
                        // A team deleted mid-fight: nothing to list for it.
                    }
                }
                $parties[] = [
                    'index' => $index,
                    'name' => $fight->parties[$index]->name ?? "Party $index",
                    'teams' => $team_names,
                    'monster_controlled' => $monster_controlled,
                    'is_monsters' => isset($fight->parties[$index]) && count($fight->parties[$index]->teams) === 0,
                ];
            }

            $fights[] = [
                'fightid' => $is_admin ? $fightid : null,
                'guid' => $fight->guid,
                'round' => $semaphore->sequence,
                'seconds_remaining' => $semaphore->seconds_remaining(),
                'spectatable' => $semaphore->spectatable,
                'parties' => $parties,
            ];
        }
        return $fights;
    }

    public function collect_resource_ids(): array {
        return FIGHT::load_current($this->db, $this->fightid)->collect_resource_ids();
    }
}
