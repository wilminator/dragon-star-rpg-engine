<?php
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'monster.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'user_account.php';
require_once INCLUDE_GAME . 'paths.php';

/**
 * Playing the monsters. A player joins the queue (MonsterQueue); the
 * next fight anyone starts seats the longest-waiting of them as its
 * monster party's controller (MonsterSide::seat()). With no fight to
 * join after FALLBACK_SECONDS, they get a practice fight instead
 * (PracticeFight). Tokens for playing and winning are settled when the
 * fight ends (MonsterSide::settle()); see MonsterToken.
 *
 * A seat (monster_seats) tracks each monster controller in a fight:
 *   present           in control. A round in which they neither changed
 *                     a command nor committed marks them absent: that
 *                     round uses their last commands, then the AI plays
 *                     the monsters until they come back (rejoin()).
 *   abandoned_rounds  how many rounds they missed; any loses the token
 *                     for playing.
 *   admin_used        an admin power was used (a takeover): no tokens.
 *
 * Someone who abandoned rounds, wasn't back in control at the end, and
 * whose monsters lost can't queue again for LOCKOUT_SECONDS -- unless
 * they hold PLAY_AGAINST (admins).
 */
final class MonsterQueue {
    private static bool $table_verified = false;

    const FALLBACK_SECONDS = 30;
    // Queued players' pages check in every couple of seconds; one that
    // hasn't for this long has gone.
    const STALE_SECONDS = 10;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS monster_queue",
            <<<QUERY
CREATE TABLE IF NOT EXISTS monster_queue (
  userid int NOT NULL PRIMARY KEY,
  queued_at int NOT NULL,
  last_seen int NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Null when queued (or already queued), otherwise why not.
    public static function join(Database $db, int $userid, ?int $now = null): ?string {
        $now ??= time();
        $until = MonsterLockout::until($db, $userid);
        if ($until > $now) {
            return 'You left a fight your monsters lost. You can play the monsters again in '
                . (int) ceil(($until - $now) / 60) . ' minutes.';
        }
        if (FightPlayerState::find_active_fightid_for_player($db, $userid) !== null) {
            return 'Finish your fight first.';
        }
        if (PracticeFight::team_pool($db, $userid) === []) {
            return 'You need a team first.';
        }
        static::ensure_table_exists($db);
        if (self::row($db, $userid) === null) {
            $db->prepare($db->insert_query('monster_queue', ['userid', 'queued_at', 'last_seen']))->execute([$userid, $now, $now]);
        }
        return null;
    }

    public static function leave(Database $db, int $userid): void {
        static::ensure_table_exists($db);
        $db->prepare("DELETE FROM monster_queue WHERE userid = ?")->execute([$userid]);
    }

    public static function is_queued(Database $db, int $userid): bool {
        return self::row($db, $userid) !== null;
    }

    // A queued player's page checking in:
    //   ['state' => 'fight']                  seated in a fight: go to it
    //   ['state' => 'waiting', 'seconds_left' => n]
    //   ['state' => 'idle']                   not queued
    //   ['state' => 'idle', 'problem' => p]   the practice fight couldn't
    //                                         start: 'no_teams' or 'failed'
    // Past FALLBACK_SECONDS, starts their practice fight.
    public static function poll(Database $db, int $userid, ?int $now = null): array {
        $now ??= time();
        if (FightPlayerState::find_active_fightid_for_player($db, $userid) !== null) {
            self::leave($db, $userid);
            return ['state' => 'fight'];
        }
        $row = self::row($db, $userid);
        if ($row === null) {
            return ['state' => 'idle'];
        }
        $db->prepare("UPDATE monster_queue SET last_seen = ? WHERE userid = ?")->execute([$now, $userid]);
        $waited = $now - (int) $row['queued_at'];
        if ($waited < self::FALLBACK_SECONDS) {
            return ['state' => 'waiting', 'seconds_left' => self::FALLBACK_SECONDS - $waited];
        }
        // Take ourselves out first, so a fight starting now can't also seat us.
        if (!self::take($db, $userid)) {
            return self::poll($db, $userid, $now);
        }
        try {
            $started = PracticeFight::start($db, $userid);
        } catch (\Throwable $e) {
            log_error($e, null, 'server_error');
            return ['state' => 'idle', 'problem' => 'failed'];
        }
        return $started === null ? ['state' => 'idle', 'problem' => 'no_teams'] : ['state' => 'fight'];
    }

    // The longest-waiting queued player still checking in, other than
    // $excluding (the fight's own player), taken off the queue. Null if
    // no one is waiting.
    public static function claim_next(Database $db, int $excluding, ?int $now = null): ?int {
        $now ??= time();
        static::ensure_table_exists($db);
        $db->prepare("DELETE FROM monster_queue WHERE last_seen < ?")->execute([$now - self::STALE_SECONDS]);
        $stmt = $db->prepare("SELECT userid FROM monster_queue WHERE userid <> ? ORDER BY queued_at, userid");
        $stmt->execute([$excluding]);
        foreach (array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'userid') as $userid) {
            $userid = (int) $userid;
            if (FightPlayerState::find_active_fightid_for_player($db, $userid) !== null) {
                self::leave($db, $userid);
                continue;
            }
            if (self::take($db, $userid)) {
                return $userid;
            }
        }
        return null;
    }

    // Removes the row; true only for the request that removed it.
    private static function take(Database $db, int $userid): bool {
        $stmt = $db->prepare("DELETE FROM monster_queue WHERE userid = ?");
        $stmt->execute([$userid]);
        return $stmt->rowCount() === 1;
    }

    private static function row(Database $db, int $userid): ?array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM monster_queue WHERE userid = ?");
        $stmt->execute([$userid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

// When a player may queue for the monsters again (see MonsterSide::settle()).
final class MonsterLockout {
    private static bool $table_verified = false;

    const SECONDS = 3600;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS monster_lockouts",
            "CREATE TABLE IF NOT EXISTS monster_lockouts (\n  userid int NOT NULL PRIMARY KEY,\n  locked_until int NOT NULL\n);",
            $reset
        );
        static::$table_verified = true;
    }

    // A Unix time; 0 if never locked.
    public static function until(Database $db, int $userid): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT locked_until FROM monster_lockouts WHERE userid = ?");
        $stmt->execute([$userid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? 0 : (int) $row['locked_until'];
    }

    public static function lock(Database $db, int $userid, int $until): void {
        static::ensure_table_exists($db);
        $db->prepare($db->upsert_query('monster_lockouts', ['userid', 'locked_until'], 'userid'))->execute([$userid, $until]);
    }
}

// One monster controller in one fight. See MonsterSide.
final class MonsterSeat {
    private static bool $table_verified = false;

    public function __construct(
        public int $fightid,
        public int $playerid,
        public int $party_index,
        public bool $present = true,
        public int $abandoned_rounds = 0,
        public int $last_acted_sequence = -1,
        public bool $admin_used = false,
    ) {}

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS monster_seats",
            <<<QUERY
CREATE TABLE IF NOT EXISTS monster_seats (
  fightid int NOT NULL,
  playerid int NOT NULL,
  party_index int NOT NULL,
  present int NOT NULL,
  abandoned_rounds int NOT NULL,
  last_acted_sequence int NOT NULL,
  admin_used int NOT NULL,
  PRIMARY KEY (fightid, playerid)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public function save(Database $db): void {
        static::ensure_table_exists($db);
        $columns = ['fightid', 'playerid', 'party_index', 'present', 'abandoned_rounds', 'last_acted_sequence', 'admin_used'];
        $db->prepare($db->upsert_query('monster_seats', $columns, ['fightid', 'playerid']))->execute([
            $this->fightid, $this->playerid, $this->party_index, (int) $this->present, $this->abandoned_rounds,
            $this->last_acted_sequence, (int) $this->admin_used,
        ]);
    }

    public static function load(Database $db, int $fightid, int $playerid): ?MonsterSeat {
        return self::load_all($db, $fightid)[$playerid] ?? null;
    }

    // [playerid => MonsterSeat]
    public static function load_all(Database $db, int $fightid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM monster_seats WHERE fightid = ?");
        $stmt->execute([$fightid]);
        $seats = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $seats[(int) $row['playerid']] = new MonsterSeat((int) $row['fightid'], (int) $row['playerid'], (int) $row['party_index'],
                (bool) $row['present'], (int) $row['abandoned_rounds'], (int) $row['last_acted_sequence'], (bool) $row['admin_used']);
        }
        return $seats;
    }

    public static function delete(Database $db, int $fightid, int $playerid): void {
        static::ensure_table_exists($db);
        $db->prepare("DELETE FROM monster_seats WHERE fightid = ? AND playerid = ?")->execute([$fightid, $playerid]);
    }

    public static function delete_all_by_fightid(Database $db, int $fightid): void {
        static::ensure_table_exists($db);
        $db->prepare("DELETE FROM monster_seats WHERE fightid = ?")->execute([$fightid]);
    }
}

final class MonsterSide {

    // Seats $playerid (already given a FightPlayerState with
    // MONSTER_TEAMID) as the controller of monster party $party_index.
    // Their commands start as the AI's choices, so a round they miss
    // still has something to replay.
    public static function seat(Database $db, int $fightid, int $playerid, int $party_index, bool $admin_used = false): void {
        $fight = FIGHT::load_current($db, $fightid);
        $party = $fight->parties[$party_index] ?? null;
        if ($party !== null) {
            foreach ($party->groups as $g => $group) {
                foreach ($group->characters as $c => $character) {
                    if ($character->is_dead()) {
                        continue;
                    }
                    $fight->set_npc_action($party_index, $g, $c);
                    (new PendingAction($fightid, $party_index, $g, $c, $playerid,
                        $character->command, $character->using, $character->target))->save($db);
                }
            }
        }
        (new MonsterSeat($fightid, $playerid, $party_index, admin_used: $admin_used))->save($db);
    }

    // The index of the monster party in $fight: the first with no teams.
    public static function monster_party_index(FIGHT $fight): ?int {
        foreach ($fight->parties as $index => $party) {
            if (count($party->teams) === 0) {
                return $index;
            }
        }
        return null;
    }

    // A monster controller changed a command or committed in round
    // $sequence. Acting brings an absent player back into control.
    public static function note_acted(Database $db, int $fightid, int $playerid, int $sequence): void {
        $seat = MonsterSeat::load($db, $fightid, $playerid);
        if ($seat !== null && ($seat->last_acted_sequence < $sequence || !$seat->present)) {
            $seat->last_acted_sequence = max($seat->last_acted_sequence, $sequence);
            $seat->present = true;
            $seat->save($db);
        }
    }

    // Back in control after being marked absent: they came back to the
    // fight (loaded its page). A tab left open fetching each round's
    // results doesn't count. True if they were absent.
    public static function rejoin(Database $db, int $fightid, int $playerid): bool {
        $seat = MonsterSeat::load($db, $fightid, $playerid);
        if ($seat === null || $seat->present) {
            return false;
        }
        $seat->present = true;
        $seat->save($db);
        return true;
    }

    // Monster parties a player is controlling now (present seats).
    public static function present_party_indexes(Database $db, int $fightid): array {
        $indexes = [];
        foreach (MonsterSeat::load_all($db, $fightid) as $seat) {
            if ($seat->present) {
                $indexes[] = $seat->party_index;
            }
        }
        return array_values(array_unique($indexes));
    }

    // Monster parties any player controlled at any point in the fight:
    // every seat, present or not, is kept until the fight ends.
    public static function touched_party_indexes(Database $db, int $fightid): array {
        return array_values(array_unique(array_map(fn($seat) => $seat->party_index, MonsterSeat::load_all($db, $fightid))));
    }

    // Players whose seat is absent: they don't hold up a round.
    public static function absent_playerids(Database $db, int $fightid): array {
        return array_keys(array_filter(MonsterSeat::load_all($db, $fightid), fn($seat) => !$seat->present));
    }

    // At the start of processing round $sequence: the monster parties a
    // human still controls this round. A present controller who didn't
    // act in it is marked absent -- this round still uses their last
    // commands; from the next, the AI plays.
    public static function begin_round(Database $db, int $fightid, array $player_states, int $sequence): array {
        $seats = MonsterSeat::load_all($db, $fightid);
        $controlled = [];
        foreach ($player_states as $state) {
            if ($state->teamid !== CHARACTER::MONSTER_TEAMID) {
                continue;
            }
            $seat = $seats[$state->playerid] ?? null;
            if ($seat === null) {
                $controlled[] = $state->player_party; // seated before seats existed
                continue;
            }
            if (!$seat->present) {
                continue;
            }
            $controlled[] = $state->player_party;
            $acted = $seat->last_acted_sequence >= $sequence || $state->sequence > $sequence;
            if (!$acted) {
                $seat->present = false;
                $seat->abandoned_rounds++;
                $seat->save($db);
            }
        }
        return array_values(array_unique($controlled));
    }

    // When the fight has resolved. $alive: [party index => bool], taken
    // before the rewards revive anyone. Gives each controller their
    // tokens, and locks out a controller who abandoned a fight their
    // monsters lost and wasn't back at the end. An admin power used
    // voids tokens; admins (PLAY_AGAINST) are never locked out.
    // Returns [playerid => tokens given].
    public static function settle(Database $db, int $fightid, array $alive, ?int $now = null): array {
        $now ??= time();
        $given = [];
        foreach (MonsterSeat::load_all($db, $fightid) as $playerid => $seat) {
            $won = (bool) ($alive[$seat->party_index] ?? false);
            $given[$playerid] = $seat->admin_used ? 0
                : MonsterToken::award($db, $playerid, $seat->abandoned_rounds === 0, $won && $seat->present, $fightid, $now);
            if ($seat->abandoned_rounds > 0 && !$won && !$seat->present
                && !PermissionGrant::has($db, $playerid, 'PLAY_AGAINST')) {
                MonsterLockout::lock($db, $playerid, $now + MonsterLockout::SECONDS);
            }
        }
        return $given;
    }

    // --- admins ---

    // An admin takes control of a live fight's monsters. Null when done,
    // otherwise why not. They earn nothing from it.
    public static function take_over(Database $db, int $fightid, int $admin_playerid): ?string {
        $semaphore = FightSemaphore::load($db, $fightid);
        if ($semaphore === null || $semaphore->state === FightStatus::Resolved) {
            return 'That fight is over.';
        }
        if (FightPlayerState::find_active_fightid_for_player($db, $admin_playerid) !== null) {
            return "You're already in a fight.";
        }
        $fight = FIGHT::load_current($db, $fightid);
        $party_index = self::monster_party_index($fight);
        if ($party_index === null) {
            return 'That fight has no monsters.';
        }
        foreach (MonsterSeat::load_all($db, $fightid) as $seat) {
            if ($seat->party_index === $party_index && $seat->present) {
                return 'A player is controlling those monsters.';
            }
        }
        (new FightPlayerState($fightid, $admin_playerid, CHARACTER::MONSTER_TEAMID, $party_index, true, $semaphore->sequence))->save($db);
        self::seat($db, $fightid, $admin_playerid, $party_index, admin_used: true);
        return null;
    }

    // An admin hands the monsters back to the AI and goes back to watching.
    public static function leave_to_spectate(Database $db, int $fightid, int $playerid): void {
        $state = FightPlayerState::load($db, $fightid, $playerid);
        if ($state === null || $state->teamid !== CHARACTER::MONSTER_TEAMID) {
            return;
        }
        FightPlayerState::delete_one($db, $fightid, $playerid);
        // The seat stays, marked absent, as the record that a player
        // controlled these monsters (the heroes' bonus: see
        // touched_party_indexes()).
        $seat = MonsterSeat::load($db, $fightid, $playerid);
        if ($seat !== null) {
            $seat->present = false;
            $seat->admin_used = true;
            $seat->save($db);
        }
    }
}

/**
 * The fight a queued player gets when no one starts one to join: a
 * random party of one to three teams from their own teams and their
 * teams' allies, cloned and played by the AI, against monsters the
 * player controls. The clones start with random HP and MP, the way
 * monsters do, and nothing about them is saved: their teams get no XP
 * or gold, and the fight isn't in their history (FIGHT::$practice).
 */
final class PracticeFight {

    // Teams to draw from: the player's active teams and their allies.
    public static function team_pool(Database $db, int $userid): array {
        $pool = [];
        foreach (TEAM::load_all_by_playerid($db, $userid) as $team) {
            if ($team->state !== ObjectState::ACTIVE) {
                continue;
            }
            $pool[$team->teamid] = true;
            foreach ($team->get_relations() as $other => $relation) {
                if ($relation === RelationState::ALLY) {
                    $pool[(int) $other] = true;
                }
            }
        }
        return array_map('intval', array_keys($pool));
    }

    public static function build(Database $db, int $userid): ?FIGHT {
        $pool = self::team_pool($db, $userid);
        shuffle($pool);
        $heroes = new PARTY();
        $wanted = mt_rand(1, min(3, max(1, count($pool))));
        foreach ($pool as $teamid) {
            if (count($heroes->teams) >= $wanted) {
                break;
            }
            try {
                $team = TEAM::load_for_gameplay($db, $teamid);
            } catch (\Exception $e) {
                continue; // gone, or not active
            }
            if ($team->state !== ObjectState::ACTIVE || count($team->characters) === 0) {
                continue;
            }
            foreach ($team->characters as $character) {
                $character->current['HP'] = ceil($character->base['HP'] * MONSTER::random_hp_ratio());
                $character->current['MP'] = ceil($character->base['MP'] * MONSTER::random_mp_ratio());
            }
            $heroes->add_team($team);
        }
        if (count($heroes->teams) === 0) {
            return null;
        }
        $fight = new FIGHT('forest.png', FightSetup::battle_music());
        $fight->practice = true;
        $fight->add($heroes);
        $fight->add(MONSTER::encounter_for($heroes));
        FightSetup::link_as_enemies($fight);
        return $fight;
    }

    // Builds and starts one with $userid on the monsters. The fight id,
    // or null if they have no teams to draw from.
    public static function start(Database $db, int $userid): ?int {
        $fight = self::build($db, $userid);
        if ($fight === null) {
            return null;
        }
        $players = [['playerid' => $userid, 'teamid' => CHARACTER::MONSTER_TEAMID, 'player_party' => 1]];
        FightSetup::apply_time_settings($db, $fight, $players);
        FightSetup::apply_hero_ai($fight);
        $fightid = FightCommunicator::start_fight($db, $fight, $players);
        MonsterSide::seat($db, $fightid, $userid, 1);
        return $fightid;
    }
}

// Pieces of starting a fight that several places share.
final class FightSetup {
    const BATTLE_MUSIC = ['anger.mp3'];

    // One of the battle tracks present in MUSIC_DIR.
    public static function battle_music(): string {
        $present = array_values(array_filter(self::BATTLE_MUSIC, fn($file) => is_file(MUSIC_DIR . $file)));
        return $present === [] ? self::BATTLE_MUSIC[0] : $present[mt_rand(0, count($present) - 1)];
    }

    // The round-time settings of the fight's human players ($players, as
    // start_fight() takes them), before it starts. The fight gets the
    // highest multiplier anyone asked for. A hero player's longer rounds
    // cost the monsters' side extra max HP (5% or 10%), and so extra
    // PXP -- even when a live player controls those monsters. A monster
    // player's longer rounds cost nothing: the heroes they face are in
    // the middle of their own campaign.
    public static function apply_time_settings(Database $db, FIGHT $fight, array $players): void {
        require_once INCLUDE_GAME . 'player_settings.php';
        $hero_side = 1.0;
        $monster_side = 1.0;
        $hero_parties = [];
        foreach ($players as $player) {
            $multiplier = (float) PlayerSettings::load($db, (int) $player['playerid'])['time_multiplier'];
            if ((int) $player['teamid'] === CHARACTER::MONSTER_TEAMID) {
                $monster_side = max($monster_side, $multiplier);
            } else {
                $hero_side = max($hero_side, $multiplier);
                $hero_parties[(int) $player['player_party']] = true;
            }
        }
        $fight->time_multiplier = max($hero_side, $monster_side);
        $bonus = PlayerSettings::hp_bonus($hero_side);
        if ($bonus <= 0) {
            return;
        }
        foreach ($fight->parties as $index => $party) {
            if (isset($hero_parties[$index]) || !$party->monster) {
                continue;
            }
            foreach ($party->groups as $group) {
                foreach ($group->characters as $monster) {
                    // Whole-number percent math, rounding up: 200 * 1.1 in
                    // floating point comes out a hair over 220.
                    $percent = 100 + (int) round($bonus * 100);
                    $base = (int) $monster->base['HP'];
                    $boosted = intdiv($base * $percent + 99, 100);
                    // The same share of the new max as it had of the old.
                    $current = (int) $monster->current['HP'];
                    $monster->current['HP'] = $base > 0 ? intdiv($current * $boosted + $base - 1, $base) : $boosted;
                    $monster->base['HP'] = $boosted;
                    $monster->calculate_pxp();
                }
            }
        }
    }

    // How well the computer plays each player's hero this fight, should
    // it have to (the player away, or out of time): from the hero's
    // level and abilities, give or take a few levels (AiProfile).
    public static function apply_hero_ai(FIGHT $fight): void {
        require_once INCLUDE_GAME . 'ai_profile.php';
        foreach ($fight->parties as $party) {
            if ($party->monster) {
                continue;
            }
            foreach ($party->groups as $group) {
                foreach ($group->characters as $hero) {
                    AiProfile::apply($hero, true);
                }
            }
        }
    }

    // Parties 0 and 1 against each other.
    public static function link_as_enemies(FIGHT $fight): void {
        $fight->parties[0]->enemies[] = 1;
        $fight->parties[1]->enemies[] = 0;
        $fight->parties[0]->allies[] = 0;
        $fight->parties[1]->allies[] = 1;
    }

    // Seats the next queued monster player, if any, in the fight
    // $hero_playerid is about to start: adds them to $players, so the
    // first round's deadline allows for them. Returns their id; once the
    // fight exists, MonsterSide::seat() them in party 1.
    public static function claim_monster_player(Database $db, int $hero_playerid, array &$players): ?int {
        $monster_playerid = MonsterQueue::claim_next($db, $hero_playerid);
        if ($monster_playerid !== null) {
            $players[] = ['playerid' => $monster_playerid, 'teamid' => CHARACTER::MONSTER_TEAMID, 'player_party' => 1];
        }
        return $monster_playerid;
    }
}
