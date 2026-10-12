<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'auth.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_action.php';
require_once INCLUDE_GAME . 'fight_player_state.php';
require_once INCLUDE_GAME . 'fight_semaphore.php';
require_once INCLUDE_GAME . 'fight_save.php';
require_once INCLUDE_GAME . 'pending_action.php';
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'monster_side.php';

/**
 * FightCommunicator is the routing point for player-originated fight
 * traffic, and the single boundary between client-supplied values and
 * trusted ones.
 *
 * The trust model this formalizes already existed, just implicitly and
 * distributed across every caller: change_character_command.php looked
 * up the authenticated player's own teamid via
 * FIGHT_STORE::get_fight($GLOBALS['userid']) -- trusted, since
 * $GLOBALS['userid'] comes from the session, not the request -- and
 * FIGHT::set_PC_action() itself then rejected any client-supplied
 * party/group/character that didn't resolve to a character actually
 * owned by that teamid. That's not broken; it's just fragile, since
 * its correctness depends on every one of ~14 callers threading the
 * trusted teamid through correctly and never substituting anything
 * client-supplied in its place. This class exists so that sequencing
 * only has to be gotten right once: constructed with nothing but an
 * authenticated playerid (never trust anything else as that), every
 * public method here derives fightid/teamid itself before touching
 * anything else, and orchestrates whichever of
 * FightPlayerState/PendingAction/FightSemaphore need to move together
 * for that one action to persist correctly.
 *
 * Reads fights via FIGHT::load_current() (initial_state + FightAction
 * replay), never FIGHT_STORE's legacy fight_data-based path.
 */
class FightCommunicator {
    private Database $db;
    private int $playerid;

    // $playerid: the logged-in player, and nothing else. Anyone watching
    // a fight that isn't theirs goes through FightSpectator instead.
    public function __construct(Database $db, int $playerid) {
        $this->db = $db;
        $this->playerid = $playerid;
    }

    // The trust boundary itself: given only $this->playerid (the
    // authenticated session's own id, fixed at construction), finds
    // which fight they currently have an unresolved-round row for, and
    // their verified teamid within it. Returns null if this player
    // isn't currently part of an active fight at all -- the caller's
    // signal to treat this as a hostile or stale request, the same way
    // change_character_command.php already logs a "HACK" warning on
    // exactly this condition today.
    // The shared trust-derivation core: given a SPECIFIC fightid,
    // confirms $this->playerid actually has a FightPlayerState row for
    // it -- still a genuine trust check even when $fightid came from
    // the caller, since it only ever returns something for a fight
    // this player was actually, verifiably part of -- and returns
    // their teamid. Works for any fight the player has ever been part
    // of, resolved or not, unlike find_trusted_context() below (which
    // only ever finds a still-*active* one, with no fightid input at
    // all). Both find_trusted_context() and get_fight_status() build
    // on this.
    // Party indexes a player is controlling as monsters, from the
    // fight's own player-state rows. process_round() skips these when
    // handing out AI actions, so the AI doesn't overwrite that player's
    // commands. This used to test teamid === 0, but init_fight.php
    // registers monster controllers with CHARACTER::MONSTER_TEAMID (-1),
    // so it never matched and player-controlled monsters were given AI
    // actions every round.
    // Extra XP for beating monsters a player controlled at any point in
    // the fight, however briefly: for each winning party with teams, the
    // overkill it dealt those monsters as a share of their total HP,
    // capped at XP_BONUS_CAP. [party index => share]. $human_parties:
    // MonsterSide::touched_party_indexes().
    const XP_BONUS_CAP = 0.15;

    public static function xp_bonus_shares(FIGHT $fight, array $alive, array $overkill, array $human_parties): array {
        $shares = [];
        foreach ($fight->parties as $index => $party) {
            if (count($party->teams) === 0 || !($alive[$index] ?? false)) {
                continue;
            }
            $dealt = 0;
            $hp = 0;
            foreach ($party->enemies as $enemy) {
                if (!in_array($enemy, $human_parties, true) || ($alive[$enemy] ?? true)) {
                    continue;
                }
                $dealt += $overkill[$enemy] ?? 0;
                foreach ($fight->parties[$enemy]->groups as $group) {
                    foreach ($group->characters as $character) {
                        $hp += $character->get_base('HP');
                    }
                }
            }
            if ($hp > 0 && $dealt > 0) {
                $shares[$index] = min(self::XP_BONUS_CAP, $dealt / $hp);
            }
        }
        return $shares;
    }

    // A won PvE fight (the hero party at index 0 against monster parties
    // only) is considered for each of the hero player's teams' underdog
    // record (how much stronger the monsters were: their pxp over the
    // heroes') and overkill record (the overkill the heroes dealt, over
    // the heroes' pxp). See FightSave::consider_for_underdog() and
    // consider_for_overkill(). $parties, $pxp and $alive: as they were
    // before the conclusion.
    private static function record_highlights(Database $db, int $fightid, FIGHT $fight, array $parties, array $player_states,
                                              array $alive, array $pxp, array $overkill): void {
        if (!($alive[0] ?? false) || $fight->practice) {
            return;
        }
        $monster_pxp = 0;
        $monster_overkill = 0;
        foreach ($parties as $index => $party) {
            if ($index === 0) {
                continue;
            }
            if (count($party->teams) > 0) {
                return; // another party of heroes: not PvE
            }
            $monster_pxp += $pxp[$index] ?? 0;
            $monster_overkill += $overkill[$index] ?? 0;
        }
        foreach ($player_states as $state) {
            if ($state->teamid <= 0 || $state->player_party !== 0) {
                continue;
            }
            foreach (self::own_teamids($db, $fight, $state->playerid, $state->teamid, 0) as $teamid) {
                FightSave::consider_for_underdog($db, $fightid, $teamid, $monster_pxp, $pxp[0] ?? 0);
                if ($monster_overkill > 0) {
                    FightSave::consider_for_overkill($db, $fightid, $teamid, $monster_overkill, $pxp[0] ?? 0);
                }
            }
        }
    }

    // The teams a player controls in $fight: the team recorded for them
    // when it started ($teamid), plus any other team in their party that
    // they own (an alliance of their teams). Another player's team in
    // the same party is never theirs -- it's that player's, if they're in
    // the fight, or the AI's. A monster controller's is just
    // MONSTER_TEAMID. In party order.
    public static function own_teamids(Database $db, FIGHT $fight, int $playerid, int $teamid, int $player_party): array {
        if ($teamid <= 0) {
            return [$teamid];
        }
        $party = $fight->parties[$player_party] ?? null;
        $party_teamids = $party === null ? [] : array_map('intval', array_keys($party->teams));
        if (!in_array($teamid, $party_teamids, true)) {
            return [$teamid];
        }
        $others = array_values(array_filter($party_teamids, fn($id) => $id !== $teamid));
        $owned = [];
        if ($others !== []) {
            TEAM::ensure_table_exists($db);
            $placeholders = implode(',', array_fill(0, count($others), '?'));
            $stmt = $db->prepare("SELECT teamid FROM teams WHERE playerid = ? AND teamid IN ($placeholders)");
            $stmt->execute([$playerid, ...$others]);
            $owned = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'teamid'));
        }
        return array_values(array_filter($party_teamids, fn($id) => $id === $teamid || in_array($id, $owned, true)));
    }

    public static function player_controlled_monster_parties(array $player_states): array {
        return array_values(array_map(
            fn($p) => $p->player_party,
            array_filter($player_states, fn($p) => $p->teamid === CHARACTER::MONSTER_TEAMID)
        ));
    }

    // --- round deadlines ---
    // Each round starts with a generous fallback deadline, covering
    // playback plus command entry. It only matters if some player's
    // client never reports finishing playback (a closed tab, a lost
    // connection). Once every player who still has a living character
    // has acknowledged playback (acknowledge_playback()), the deadline
    // drops to just the command-entry time.

    public const PLAYBACK_SECONDS_PER_PARTY = 150;
    public const COMMAND_SECONDS_PER_MEMBER = 40;

    // Living characters this player can give commands to: those in
    // their own party carrying their team ID. For a monster controller
    // that's their party's living monsters (CHARACTER::MONSTER_TEAMID).
    public static function living_controlled_members(FIGHT $fight, FightPlayerState $state): int {
        $party = $fight->parties[$state->player_party] ?? null;
        if ($party === null) {
            return 0;
        }
        $count = 0;
        foreach ($party->groups as $group) {
            foreach ($group->characters as $character) {
                if ($character->teamid === $state->teamid && !$character->is_dead()) {
                    $count++;
                }
            }
        }
        return $count;
    }

    // Command-entry time: enough for the player with the most living
    // characters to give each one a command, times the fight's
    // time_multiplier (a player's accessibility setting: 1.5 or 2).
    public static function command_timeout_seconds(FIGHT $fight, array $player_states): int {
        $largest = 0;
        foreach ($player_states as $state) {
            $largest = max($largest, self::living_controlled_members($fight, $state));
        }
        return (int) ceil(self::COMMAND_SECONDS_PER_MEMBER * $largest * max(1.0, $fight->time_multiplier));
    }

    // Playback for every party, plus command entry.
    public static function fallback_timeout_seconds(FIGHT $fight, array $player_states): int {
        return self::PLAYBACK_SECONDS_PER_PARTY * count($fight->parties)
            + self::command_timeout_seconds($fight, $player_states);
    }

    // A player's client reports that it has finished playing round
    // $sequence (for round 0, finished loading). Recorded, and once
    // every player who still has a living character has reported the
    // same round, the deadline drops to now + command_timeout_seconds().
    // That's never earlier than the latest deadline any one player would
    // have asked for (each player's own ack time plus 40 s per member),
    // since this is computed at the last ack with the largest team.
    // Repeating an acknowledgement changes nothing. Spectators and
    // replay viewers never acknowledge -- only participants get here.
    //
    // Returns ['status' => 'not_in_fight' | 'stale' | 'resolved' |
    // 'acknowledged', 'seconds_remaining' => int (acknowledged only)].
    public function acknowledge_playback(int $sequence): array {
        $context = $this->find_trusted_context();
        if ($context === null) {
            return ['status' => 'not_in_fight'];
        }
        $fightid = $context['fightid'];
        $semaphore = FightSemaphore::load($this->db, $fightid);
        if ($semaphore === null) {
            return ['status' => 'not_in_fight'];
        }
        if ($sequence !== $semaphore->sequence) {
            return ['status' => 'stale'];
        }
        if ($semaphore->state === FightStatus::Resolved) {
            return ['status' => 'resolved'];
        }

        $player_state = FightPlayerState::load($this->db, $fightid, $this->playerid);
        if ($player_state->played_sequence !== $sequence) {
            $player_state->played_sequence = $sequence;
            $player_state->save($this->db);

            $fight = FIGHT::load_current($this->db, $fightid);
            $player_states = FightPlayerState::load_all_by_fightid($this->db, $fightid);
            $still_playing = array_filter(
                $player_states,
                fn($p) => $p->played_sequence !== $sequence && self::living_controlled_members($fight, $p) > 0
            );
            if ($still_playing === []) {
                FightSemaphore::tighten_timeout($this->db, $fightid, time() + self::command_timeout_seconds($fight, $player_states));
            }
        }

        return ['status' => 'acknowledged', 'seconds_remaining' => FightSemaphore::load($this->db, $fightid)->seconds_remaining()];
    }

    private function trusted_context_for(int $fightid): ?array {
        $player_state = FightPlayerState::load($this->db, $fightid, $this->playerid);
        if ($player_state === null) {
            return null;
        }
        return ['fightid' => $fightid, 'teamid' => $player_state->teamid, 'player_party' => $player_state->player_party];
    }

    private function find_trusted_context(): ?array {
        $fightid = FightPlayerState::find_active_fightid_for_player($this->db, $this->playerid);
        if ($fightid === null) {
            return null;
        }
        return $this->trusted_context_for($fightid);
    }

    // Submits this player's proposed command for one of their own
    // characters. $party/$group/$character/$action are client-supplied
    // and untrusted throughout -- never used here to determine which
    // player is acting (that's $this->playerid) or which team they
    // control (that's the trusted teamid from find_trusted_context()),
    // only to say which of that team's own characters the player
    // means. FIGHT::set_PC_action() is what actually enforces that:
    // it rejects any targeted character that doesn't belong to the
    // trusted teamid, exactly as it already does today.
    //
    // Returns false (rather than throwing) for both "not currently in
    // an active fight" and "set_PC_action() itself rejected this" --
    // the first is logged, matching change_character_command.php's
    // existing "HACK" warning on the same condition; the second isn't,
    // matching its existing behavior too (an ordinary invalid
    // submission, not inherently suspicious on its own).
    public function submit_character_command(int $party, int $group, int $character, array $action): bool {
        $context = $this->find_trusted_context();
        if ($context === null) {
            log_error("HACK:\nAttempt to submit a fight command while not part of an active fight\nplayerid: {$this->playerid}", 100);
            return false;
        }

        $fight = FIGHT::load_current($this->db, $context['fightid']);
        if ($fight === null) {
            return false;
        }

        // A player may only command characters in their own party, as
        // recorded in fight_players -- never whichever party index the
        // client happened to send. Heroes are already confined by
        // team ID; this matters for monster controllers, since every
        // monster party carries the same MONSTER_TEAMID.
        if ($party !== $context['player_party']) {
            log_error("HACK:\nAttempt to command a character outside the player's own party\nplayerid: {$this->playerid} party: {$party}", 100);
            return false;
        }

        $accepted = $fight->set_PC_action($party, $group, $character,
            self::own_teamids($this->db, $fight, $this->playerid, $context['teamid'], $context['player_party']), $action);
        if (!$accepted) {
            return false;
        }

        $fighter = $fight->parties[$party]->groups[$group]->characters[$character];
        $pending_action = new PendingAction(
            $context['fightid'], $party, $group, $character,
            $this->playerid,
            $fighter->command, $fighter->using, $fighter->target,
        );
        $pending_action->save($this->db);
        if ($context['teamid'] === CHARACTER::MONSTER_TEAMID) {
            $semaphore = FightSemaphore::load($this->db, $context['fightid']);
            MonsterSide::note_acted($this->db, $context['fightid'], $this->playerid, $semaphore?->sequence ?? 0);
        }

        return true;
    }

    // Ports process_fight_commands.php's role: a player confirming
    // "my command stands, I'm done for this round." $sequence_number
    // is client-supplied and untrusted -- sanity-checked here against
    // the fight's actual current sequence (from FightSemaphore, the
    // authoritative source now) rather than trusted at face value. The
    // legacy code enforced this too, just split across two places
    // (process_fight_commands.php's own early "you're behind, refresh"
    // shortcut, then FIGHT_STORE::update_player()'s internal != check
    // as a second layer) -- folded into one explicit equality check
    // here, since a client sending a *future* sequence number (which
    // the legacy early check alone wouldn't have caught) is exactly
    // what a sanity check should reject, not silently trust.
    //
    // Bumps this player's own FightPlayerState forward, then checks
    // whether every other player has already done the same for this
    // round (mirrors update_player()'s own "count players still at the
    // old sequence" check exactly) -- if so, attempts to claim the
    // semaphore and, on winning, processes the round right here in
    // this same call, the same way the legacy code's "last confirming
    // player ends up running the turn" behavior worked.
    public function confirm_round(int $sequence_number): array {
        $context = $this->find_trusted_context();
        if ($context === null) {
            log_error("HACK:\nAttempt to confirm a fight round while not part of an active fight\nplayerid: {$this->playerid}", 100);
            return ['status' => 'not_in_fight'];
        }

        $semaphore = FightSemaphore::load($this->db, $context['fightid']);
        if ($semaphore === null) {
            return ['status' => 'not_in_fight'];
        }
        if ($sequence_number !== $semaphore->sequence) {
            return ['status' => 'stale', 'fightid' => $context['fightid'], 'sequence' => $semaphore->sequence];
        }

        $player_state = FightPlayerState::load($this->db, $context['fightid'], $this->playerid);
        $player_state->sequence = $sequence_number + 1;
        $player_state->seen = true;
        $player_state->save($this->db);

        if ($context['teamid'] === CHARACTER::MONSTER_TEAMID) {
            MonsterSide::note_acted($this->db, $context['fightid'], $this->playerid, $sequence_number);
        }

        // An absent monster player doesn't hold the round up.
        $absent = MonsterSide::absent_playerids($this->db, $context['fightid']);
        $all_players = FightPlayerState::load_all_by_fightid($this->db, $context['fightid']);
        $still_waiting = array_filter($all_players,
            fn($p) => $p->sequence === $sequence_number && !in_array($p->playerid, $absent, true));
        if (count($still_waiting) > 0) {
            return ['status' => 'waiting', 'fightid' => $context['fightid']];
        }

        $claimed = FightSemaphore::claim($this->db, $context['fightid'], $this->playerid, 30);
        if ($claimed === null) {
            // Someone else (another confirming player's request, or a
            // timeout check) already claimed it first -- not an error,
            // just lost the race for who gets to run this turn.
            return ['status' => 'waiting', 'fightid' => $context['fightid']];
        }

        return $this->process_round($claimed);
    }

    // Ports check_fight_timeout.php's role: force this round to
    // process even without every player's confirmation, once the
    // gameplay timeout has passed. is_timed_out()/claim() together
    // are what already make this safe to call from any connected
    // player's routine poll, unconditionally -- claim() only succeeds
    // for whichever single request actually wins the race.
    //
    // $sequence_number: the same staleness check confirm_round() does
    // -- FIGHT_STORE::has_timed_out() checked this too (a $sequence_number
    // that no longer matches the fight's actual current sequence means
    // someone else already advanced the round since this client last
    // knew, independent of whether the timeout itself has passed),
    // and a poller repeatedly hitting this endpoint while waiting can
    // genuinely have gone stale between polls.
    public function check_timeout(int $sequence_number): array {
        $context = $this->find_trusted_context();
        if ($context === null) {
            return ['status' => 'not_in_fight'];
        }

        $semaphore = FightSemaphore::load($this->db, $context['fightid']);
        if ($semaphore === null) {
            return ['status' => 'not_in_fight'];
        }
        if ($sequence_number !== $semaphore->sequence) {
            return ['status' => 'stale', 'fightid' => $context['fightid'], 'sequence' => $semaphore->sequence];
        }
        if (!$semaphore->is_timed_out()) {
            return ['status' => 'waiting', 'fightid' => $context['fightid'], 'seconds_remaining' => $semaphore->seconds_remaining()];
        }

        $claimed = FightSemaphore::claim($this->db, $context['fightid'], $this->playerid, 30);
        if ($claimed === null) {
            // Already being processed by whoever else's request won
            // the claim first.
            return ['status' => 'waiting', 'fightid' => $context['fightid']];
        }

        return $this->process_round($claimed);
    }

    // The actual turn, shared by confirm_round()/check_timeout() --
    // whichever request's claim() call above won, this is what it
    // does with that claim. Ports process_fight()'s exact sequence:
    // give every NPC-controlled character a turn (same eligibility
    // logic, including its not-actually-a-bug redundancy of calling
    // set_npc_action() on some already-dead characters that
    // do_combat()'s own participant filtering excludes from acting
    // anyway -- preserved here faithfully rather than "cleaned up",
    // since it's not broken, just slightly wasteful), run do_combat(),
    // persist the result, and release the semaphore with wherever the
    // fight actually landed.
    private function process_round(FightSemaphore $claimed): array {
        $fight = FIGHT::load_current($this->db, $claimed->fightid);

        $fight->apply_pending_actions(PendingAction::load_all_by_fightid($this->db, $claimed->fightid));

        $player_states = FightPlayerState::load_all_by_fightid($this->db, $claimed->fightid);
        // Every real team a player controls here, alliance teams included.
        $pc_teams = [];
        foreach ($player_states as $state) {
            if ($state->teamid > 0) {
                array_push($pc_teams, ...self::own_teamids($this->db, $fight, $state->playerid, $state->teamid, $state->player_party));
            }
        }
        $pc_teams = array_values(array_unique($pc_teams));
        // Monster parties a human still controls this round; anyone who
        // let this round pass without acting is marked absent (see
        // MonsterSide).
        $player_monster_parties = MonsterSide::begin_round($this->db, $claimed->fightid, $player_states, $claimed->sequence);

        foreach ($fight->parties as $party_index => $party) {
            if (in_array($party_index, $player_monster_parties, true)) {
                continue;
            }
            foreach ($party->groups as $group_index => $group) {
                foreach ($group->characters as $char_index => $character) {
                    if (
                        (!$character->is_dead() && $character->teamid === null)
                        || !in_array($character->teamid, $pc_teams, true)
                    ) {
                        $fight->set_npc_action($party_index, $group_index, $char_index);
                    }
                }
            }
        }

        $action_list = $fight->do_combat();
        $new_sequence = $claimed->sequence + 1;
        $new_state = $fight->count_live_parties() < 2 ? FightStatus::Resolved : FightStatus::WaitingForInput;

        // Exactly once, tied to the one real transition to Resolved
        // (guarded by the semaphore's own claim/release mechanism, the
        // same guarantee record_user_history() below already relies
        // on) -- not to get_fight_view()'s own, repeatedly-called
        // path. Appended to $action_list before it's used to build
        // $fight_action below, so the 'FightDialog' message is part
        // of this round's own persisted action_data, and the
        // final_state snapshot saved for this round reflects the
        // post-reward state (restored HP/MP, updated exp).
        if ($new_state === FightStatus::Resolved) {
            // Who won, and each side's strength, before the conclusion's
            // consolation healing and leveling change them.
            $alive = array_map(fn($party) => !$party->is_dead(), $fight->parties);
            $pxp = array_map(fn($party) => $party->get_pxp(), $fight->parties);
            // The conclusion drops defeated parties from $fight->parties.
            $parties_before = $fight->parties;
            $overkill = FightAction::overkill_taken(array_merge($action_list,
                ...array_map(fn($recorded) => $recorded->action_data, FightAction::load_all_by_fightid($this->db, $claimed->fightid))));
            $xp_bonus = self::xp_bonus_shares($fight, $alive, $overkill,
                MonsterSide::touched_party_indexes($this->db, $claimed->fightid));
            $action_list[] = ['FightDialog', [$fight->process_fight_conclusion($this->db, $xp_bonus)]];
        }

        // $action_list includes each XpDebt message CHARACTER::
        // add_debt_to_xp_tree() appended during do_combat() above,
        // alongside every Damage/Restore/etc -- saved here as part of
        // the ordinary action log, replayed by
        // FIGHT::process_fight_action()'s own XpDebt case. xp_debts no
        // longer needs (or has) a separate persistence path of its own.
        $fight_action = new FightAction($claimed->fightid, $new_sequence, $action_list, $fight->dehydrate());
        $fight_action->save($this->db);

        $claimed->release($this->db, $new_state, $new_sequence, self::fallback_timeout_seconds($fight, $player_states));

        // record_user_history() was never actually wired into live
        // gameplay before this -- confirmed directly (only the
        // backfill script called it). Every real team (teamid > 0,
        // excluding the eparty/"play as the monster side" sentinel of
        // 0 -- matching $pc_teams' own filter above) that reaches
        // Resolved here gets this fight added to its rolling 25-fight
        // history, the same as if it had always been happening.
        if ($new_state === FightStatus::Resolved) {
            foreach (array_unique($pc_teams) as $teamid) {
                FightSave::record_user_history($this->db, $claimed->fightid, $teamid);
            }
            // Monster players' tokens (and any lockout).
            MonsterSide::settle($this->db, $claimed->fightid, $alive);
            // Each winning team's underdog and overkill records.
            self::record_highlights($this->db, $claimed->fightid, $fight, $parties_before, $player_states, $alive, $pxp, $overkill);
            // The fight's length comes off the time of any alliance
            // that fought in it (see Alliance).
            foreach ($player_states as $state) {
                if ($state->teamid > 0) {
                    Alliance::record_fight_time($this->db, $state->playerid,
                        self::own_teamids($this->db, $fight, $state->playerid, $state->teamid, $state->player_party), time() - $fight->created_at);
                }
            }
            // A fight someone's browser reported a problem in while it
            // was live is flagged for review now that it can be.
            if (Anomaly::has_reports_for_fight($this->db, $claimed->fightid)) {
                Anomaly::flag_fight($this->db, $claimed->fightid);
            }
        }

        return [
            'status' => 'processed',
            'fightid' => $claimed->fightid,
            'sequence' => $new_sequence,
            'action_list' => $action_list,
            'timeout' => $claimed->timeout,
            'fight' => $fight,
        ];
    }

    // The simple membership check several callers use get_fight() for
    // today (app.php, pick_team.php, rpg.php) -- they never touch the returned fight data
    // itself, only whether $result was false, so exposing the trust
    // check directly means they don't need to load a fight (or pay
    // FIGHT::load_current()'s replay cost) just to answer a yes/no
    // question.
    public function is_in_active_fight(): bool {
        return $this->find_trusted_context() !== null;
    }

    // The full replacement for FIGHT_STORE::get_fight($playerid):
    // everything a caller needs to render or act on this player's
    // current fight, sourced entirely from the new classes. Returns
    // null where get_fight() returned false -- not currently part of
    // an active fight.
    //
    // 'prefight' -- the state immediately before the round currently
    // in progress, for client-side "animate from X to Y" playback.
    // Deliberately not FIGHT::load_current() replayed to one action
    // short: the previous round's FightAction::$final_state (or, for a
    // fight's very first round, initial_state itself) already *is*
    // exactly that snapshot, recorded once and read back directly here
    // rather than recomputed by replay every time this is called.
    //
    // 'action_list' -- the actions from the most recently completed
    // round (what a client would animate through to arrive at the
    // current, still-in-progress state) -- null if no round has
    // completed yet (sequence 0, nothing to play back).
    // fightid=0 (the default) auto-finds this player's active fight,
    // same as before. A specific, non-zero fightid looks up exactly
    // that fight instead, regardless of whether it's since resolved --
    // a page finishing a fight's playback needs this once the fight is
    // Resolved, a case the fightid=0 path can't reach at all since
    // find_trusted_context() only ever finds an active one.
    // Where the client goes once playback finishes: back to the hub
    // once the fight is over, nowhere while it's still going.
    // Where the fight page goes once the fight is over: the hub, or
    // for a player on the monsters' side, team selection (where they
    // queued from).
    private function compute_redirect(bool $is_resolved, int $teamid): ?string {
        if (!$is_resolved) {
            return null;
        }
        return $teamid === CHARACTER::MONSTER_TEAMID ? 'pick_team.php' : 'app.php';
    }

    public function get_fight_view(int $fightid = 0): ?array {
        $context = $fightid === 0
            ? $this->find_trusted_context()
            : $this->trusted_context_for($fightid);
        if ($context === null) {
            return null;
        }

        $fightid = $context['fightid'];
        $player_state = FightPlayerState::load($this->db, $fightid, $this->playerid);
        $semaphore = FightSemaphore::load($this->db, $fightid);
        $fight = FIGHT::load_current($this->db, $fightid);
        $fight->apply_pending_actions(PendingAction::load_all_by_fightid($this->db, $fightid));

        $is_resolved = $semaphore->state === FightStatus::Resolved;
        // The trust-resolved teamid decides what to_client() reveals:
        // the own-team projection (command/using/target/abilities/
        // equipment/inventory) for this player's team only.
        $client_teamid = self::own_teamids($this->db, $fight, $this->playerid, $context['teamid'], $player_state->player_party);

        if ($semaphore->sequence === 0) {
            $prefight = $fight->initial_state;
            $action_list = [];
        } elseif ($player_state->played_sequence === $semaphore->sequence) {
            // This player's client already reported playing this round
            // back (a refresh, typically): start from the round's end
            // state, straight into command entry, rather than replaying.
            $prefight = FightAction::load($this->db, $fightid, $semaphore->sequence)?->final_state;
            $action_list = [];
        } else {
            $last_action = FightAction::load($this->db, $fightid, $semaphore->sequence);
            $prefight = $semaphore->sequence === 1
                ? $fight->initial_state
                : FightAction::load($this->db, $fightid, $semaphore->sequence - 1)?->final_state;
            // filter_for_client(): strips XpDebt (server-only
            // bookkeeping -- see FightAction's own docblock for why)
            // before this ever reaches a client. This is the sole
            // source of action_list that any php_js/ endpoint actually
            // sends -- process_round()'s own action_list (used to build
            // the FightAction that gets saved) is deliberately left
            // unfiltered, since replay needs every message type intact.
            $action_list = FightAction::filter_for_client($last_action?->action_data ?? [], $client_teamid);
        }

        // The round in words (the combat log panel; screen readers), from
        // the same filtered list the page plays back, named as the round
        // began.
        $round_log = [];
        if ($action_list !== []) {
            require_once INCLUDE_GAME . 'combat_log.php';
            $round_log = CombatLog::turns($action_list, $prefight !== null ? FIGHT::hydrate($prefight) : $fight,
                $player_state->player_party);
        }

        return [
            'fightid' => $fightid,
            'teamid' => $context['teamid'],
            'teamids' => $client_teamid,
            'round_log' => $round_log,
            'player_party' => $player_state->player_party,
            'sequence' => $semaphore->sequence,
            'active' => $semaphore->state,
            'seen' => $player_state->seen,
            'timeout' => $semaphore->seconds_remaining(),
            // See compute_redirect(): the caller plays back whatever
            // action_list came with this response and then checks
            // this, rather than making a second round trip the way
            // finish_fight.php used to require.
            'redirect' => $this->compute_redirect($is_resolved, (int) $context['teamid']),
            'prefight' => $prefight,
            // prefight is a raw, dehydrated array (initial_state or a
            // FightAction's own final_state), not a live FIGHT object --
            // command/using/target were deliberately removed from
            // CHARACTER::DEHYDRATE_PROPERTIES earlier this session (they
            // live in PendingAction now, not in a persisted snapshot),
            // so prefight's own characters never carry target at all,
            // for any team, not just non-owning ones. Hydrating it into
            // a real FIGHT re-applies CHARACTER::hydrate()'s own
            // override (which unconditionally sets a real
            // command/using/target default), so to_client() can then
            // apply the exact same per-team projection fight itself
            // gets. Computed once here, not by every caller
            // individually, the same reasoning client_fight itself is
            // computed here for.
            'client_prefight' => $prefight !== null ? FIGHT::hydrate($prefight)->to_client($client_teamid, $player_state->player_party) : null,
            'action_list' => $action_list,
            'fight' => $fight,
            // The sanitized projection every caller sending fight data
            // to the client actually wants -- see FIGHT::to_client()'s
            // own docblock for what this does and doesn't include for
            // the requesting team vs everyone else, and why. $fight
            // itself stays the raw, live object above: some callers
            // (convert_action_list_to_playlist()) need the real thing,
            // not this projection.
            'client_fight' => $fight->to_client($client_teamid, $player_state->player_party)
                // Monster parties a player is controlling right now, so the
                // fight screen can say so.
                + ['human_controlled' => MonsterSide::present_party_indexes($this->db, $fightid)],
            'players' => FightPlayerState::load_all_by_fightid($this->db, $fightid),
        ];
    }

    // Mirrors FIGHT_STORE::get_fight($playerid, $fightid)'s own dual
    // behavior: with $fightid=0 (the default), finds whichever fight
    // (if any) this player currently has an unresolved round in --
    // same trust derivation every other method here uses. With a
    // specific, non-zero $fightid, looks up exactly that fight
    // instead, regardless of whether it's since resolved -- needed to
    // distinguish a session pointing at a now-finished fight (route to
    // collect rewards) from never having been in one at all (route to
    // pick a team), a distinction is_in_active_fight()/get_fight_view()
    // can't make since they only ever find a still-active one. Either
    // way, still a genuine trust check: only returns something if
    // $this->playerid actually has a FightPlayerState row for the
    // fight in question, the same authenticated-identity-only
    // boundary every method here uses -- $fightid itself is never
    // trusted just because a caller supplied it.
    //
    // Deliberately lighter than get_fight_view(): no FIGHT::load_current()
    // replay, no action log lookups -- just enough to answer "which
    // fight, which team, still active or not," which is all a caller
    // needing only this (rpg.php, for instance -- html_combat() never
    // actually reads the fight object it's handed) should pay for.
    public function get_fight_status(int $fightid = 0): ?array {
        $context = $fightid === 0
            ? $this->find_trusted_context()
            : $this->trusted_context_for($fightid);
        if ($context === null) {
            return null;
        }
        $fightid = $context['fightid'];

        $player_state = FightPlayerState::load($this->db, $fightid, $this->playerid);
        $semaphore = FightSemaphore::load($this->db, $fightid);
        if ($semaphore === null) {
            return null;
        }

        try {
            $fight = FIGHT::load($this->db, $fightid);
        } catch (\Exception $e) {
            $fight = null;
        }
        return [
            'fightid' => $fightid,
            'teamid' => $player_state->teamid,
            'teamids' => $fight === null ? [$player_state->teamid]
                : self::own_teamids($this->db, $fight, $this->playerid, $player_state->teamid, $player_state->player_party),
            'player_party' => $player_state->player_party,
            'active' => $semaphore->state !== FightStatus::Resolved,
        ];
    }

    // The FIGHT_STORE::init_fight() replacement: establishes a brand
    // new fight -- saves $fight itself (id + initial_state), a
    // FightPlayerState row for each participating player, and the
    // FightSemaphore that coordinates it, together. Static, not
    // instance-scoped like every other method here: this isn't one
    // authenticated player acting on their own fight, it's the one
    // operation that brings a fight (and everyone in it) into
    // existence in the first place, for potentially several players
    // at once.
    //
    // $players: same shape FIGHT_STORE::init_fight() always took --
    // ['playerid' => int, 'teamid' => int, 'player_party' => int][].
    // seen starts true (matching the legacy insert's own seen='yes'
    // default): a player who just started the fight has, by
    // definition, already seen its opening state.
    public static function start_fight(Database $db, FIGHT $fight, array $players): int {
        $fight->save($db);
        $player_states = [];
        foreach ($players as $player) {
            $state = new FightPlayerState($fight->id, $player['playerid'], $player['teamid'], $player['player_party'], true, 0);
            $state->save($db);
            $player_states[] = $state;
        }
        FightSemaphore::create($db, $fight->id, self::fallback_timeout_seconds($fight, $player_states), $fight->spectatable);
        return $fight->id;
    }

    // For server-side simulation, with no real players involved at
    // all (see FightSave and the fight-generation tooling built on
    // this): claims and processes one round unconditionally, without
    // needing anyone to confirm first -- the same process_round()
    // every real gameplay entry point eventually reaches. A fight
    // created with no FightPlayerState rows at all (an empty $players
    // list to start_fight()) already makes process_round()'s existing
    // NPC-eligibility check treat every character as NPC-controlled --
    // no teamid can ever be "in" an empty $pc_teams array -- so
    // nothing here needs to separately decide anyone's action; that's
    // already how process_round() behaves once there are no real
    // players to be PC-controlled by. Static, like start_fight():
    // this isn't one authenticated player acting on their own fight
    // either.
    //
    // $playerid is purely for FightSemaphore's locked_by_playerid
    // admin-visibility field; 0 conventionally means "the system," not
    // a real player. Throws if the fight can't be claimed (already
    // Resolved, or -- in a genuinely concurrent context, unlike a
    // single-threaded simulation script -- already claimed by someone
    // else): unlike confirm_round()/check_timeout(), a caller here
    // already knows there's no one else who could legitimately be
    // claiming this fight, so a failed claim means something is
    // actually wrong, not a normal lost race to report as 'waiting'.
    public static function simulate_round(Database $db, int $fightid, int $playerid = 0): array {
        $claimed = FightSemaphore::claim($db, $fightid, $playerid, 30);
        if ($claimed === null) {
            throw new \Exception("Could not claim fightid $fightid for simulation -- already Resolved, or already claimed.");
        }
        $communicator = new FightCommunicator($db, $playerid);
        return $communicator->process_round($claimed);
    }

    // Deletes one fight outright, everywhere -- every table a fight
    // touches, not just the fights row itself. Two callers: regenerating
    // system-generated simulated fights, and Housekeeping, which removes
    // resolved fights no tag keeps any more (only when an admin has
    // turned that on). Never point it at a live fight, or at a fight a
    // team or admin still keeps. Static, like start_fight()/
    // simulate_round(): not one player acting on their own fight.
    public static function delete_fight(Database $db, int $fightid): void {
        FightAction::delete_all_by_fightid($db, $fightid);
        FightPlayerState::delete_all_by_fightid($db, $fightid);
        PendingAction::delete_all_by_fightid($db, $fightid);
        FightSave::delete_all_by_fightid($db, $fightid);
        MonsterSeat::delete_all_by_fightid($db, $fightid);
        FightSemaphore::delete($db, $fightid);
        FIGHT::delete_by_id($db, $fightid);
    }

    // Deletes every fight FightSave currently has tagged for $teamid,
    // regardless of type -- the bulk cleanup a "regenerate this
    // team's simulated fights" admin operation needs before generating
    // a fresh batch. Never call this for a real team; FightSave's own
    // tags for a real team (their 'retain'/'user' history) point at
    // real, team-owned fights this would delete out from under them.
    public static function delete_all_fights_owned_by(Database $db, int $teamid): int {
        $fightids = FightSave::find_all_fightids_by_teamid($db, $teamid);
        foreach ($fightids as $fightid) {
            static::delete_fight($db, $fightid);
        }
        return count($fightids);
    }

    // Sweeps out ephemeral, mid-fight-only state (a character's
    // pending command, a fight's coordination lock/sequence/timeout)
    // for every already-Resolved fight -- see PendingAction::
    // purge_resolved()/FightSemaphore::purge_resolved()'s own
    // docblocks for why this state is ephemeral in the first place and
    // has no place in a database export. Coordinates the two calls in
    // the order they require: PendingAction's own purge depends on
    // fight_semaphore still having its Resolved rows to identify which
    // fights qualify, so it must run before FightSemaphore's purge
    // removes them -- reversing the order would silently leave a
    // Resolved fight's pending-action rows behind, unreachable by any
    // future purge once its semaphore row is gone.
    //
    // Safe for both real, player-owned fights and system-generated
    // simulated ones alike: unlike delete_fight()/
    // delete_all_fights_owned_by(), this never touches FIGHT/
    // FightAction/FightPlayerState/FightSave, only the two ephemeral
    // tables -- a real fight's own permanent history and tags are
    // completely unaffected.
    public static function purge_ephemeral_state(Database $db): array {
        $pending_actions_purged = PendingAction::purge_resolved($db);
        $semaphores_purged = FightSemaphore::purge_resolved($db);
        return ['pending_actions_purged' => $pending_actions_purged, 'semaphores_purged' => $semaphores_purged];
    }
}
