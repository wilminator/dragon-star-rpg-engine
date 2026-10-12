<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight_semaphore.php';

/**
 * FightPlayerState wraps one row of the fight_players table: one
 * player's confirmation status for the round of a fight currently in
 * progress.
 *
 * Distinct from PendingAction, which tracks *what* a character's
 * command currently is -- content, no round-confirmation concept,
 * deliberately persisting as-is across rounds if unchanged.
 * FightPlayerState tracks *whether this specific player has actively
 * confirmed* for the current round: a stale, unresubmitted command is
 * still valid to reuse (per PendingAction's own design), but the round
 * still won't process until every player has explicitly confirmed,
 * even just to repeat it. Two different questions about two different
 * things, kept as two different classes.
 *
 * Purely a data construct: no "has everyone confirmed" logic lives
 * here. Counting/deciding across a fight's full set of rows is the
 * calling/orchestrating layer's job, not something this class exposes
 * itself -- same division FightAction/PendingAction/FightSemaphore
 * already draw between "persist one fact" and "decide what it means."
 *
 * Wraps the same fight_players table FIGHT_STORE's own legacy queries
 * still use directly -- not a new table. Its DDL here is the portable
 * version of the same columns FIGHT_STORE's own DDL declares (int/
 * varchar in place of MySQL-specific unsigned/tinyint/enum, the same
 * simplification already applied to fights/fight_actions this
 * session), so whichever of the two DDLs happens to create the table
 * first on a fresh deployment, the result stays fully compatible with
 * what FIGHT_STORE's own raw SQL still expects (seen as a plain
 * 'yes'/'no' string included) -- avoiding a repeat of the FIGHT-vs-
 * FIGHT_STORE schema collision already found once, for "fights"
 * itself.
 *
 * Not a DbStoreTrait entity: composite primary key (fightid,
 * playerid), same situation as FightAction/PendingAction.
 */
class FightPlayerState {
    private static bool $table_verified = false;

    public int $fightid;
    public int $playerid;
    public int $teamid;
    public int $player_party;
    public bool $seen;
    public int $sequence;
    // The last round this player's client reported as fully played
    // back (see FightCommunicator::acknowledge_playback()); -1 before
    // any. Distinct from $sequence, which tracks confirmed commands.
    public int $played_sequence;

    public function __construct(
        int $fightid,
        int $playerid,
        int $teamid,
        int $player_party,
        bool $seen,
        int $sequence,
        int $played_sequence = -1
    ) {
        $this->fightid = $fightid;
        $this->playerid = $playerid;
        $this->teamid = $teamid;
        $this->player_party = $player_party;
        $this->seen = $seen;
        $this->sequence = $sequence;
        $this->played_sequence = $played_sequence;
    }

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_players",
            <<<QUERY
CREATE TABLE IF NOT EXISTS fight_players (
  fightid int NOT NULL,
  playerid int NOT NULL,
  teamid int NOT NULL,
  player_party int NOT NULL,
  seen varchar(3) NOT NULL,
  sequence int NOT NULL,
  played_sequence int NOT NULL DEFAULT -1,
  PRIMARY KEY (fightid, playerid)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public function save(Database $db): void {
        static::ensure_table_exists($db);
        $columns = ['fightid', 'playerid', 'teamid', 'player_party', 'seen', 'sequence', 'played_sequence'];
        $query = $db->upsert_query('fight_players', $columns, ['fightid', 'playerid']);
        $stmt = $db->prepare($query);
        $stmt->execute([
            $this->fightid,
            $this->playerid,
            $this->teamid,
            $this->player_party,
            $this->seen ? 'yes' : 'no',
            $this->sequence,
            $this->played_sequence,
        ]);
    }

    public static function load(Database $db, int $fightid, int $playerid): ?FightPlayerState {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_players WHERE fightid = ? AND playerid = ?");
        $stmt->execute([$fightid, $playerid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::from_row($row);
    }

    // Order isn't meaningful (unlike FightAction's sequence-ordered
    // load_all_by_fightid()) -- callers doing an "everyone confirmed"
    // count need the full set, not any particular order.
    public static function load_all_by_fightid(Database $db, int $fightid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_players WHERE fightid = ?");
        $stmt->execute([$fightid]);
        return array_map(
            fn($row) => static::from_row($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    // Finds which fight (if any) a player currently has an
    // unresolved-round row for -- the lookup a caller needs before it
    // can load()/save() anything, when it only knows the playerid.
    // Mirrors FIGHT_STORE::get_fight()'s own
    // "WHERE state<>Resolved AND playerid=?" lookup, joined against
    // FightSemaphore instead of the legacy fights.state column.
    //
    // Simplification versus that legacy method, flagged rather than
    // silently reproduced: get_fight() self-heals when a player somehow
    // has more than one non-Resolved fight (logs it, force-resolves all
    // but the newest). This just returns the first match and leaves
    // that scenario for the calling layer to notice and decide about,
    // rather than silently taking an action on the caller's behalf.
    public static function find_active_fightid_for_player(Database $db, int $playerid): ?int {
        static::ensure_table_exists($db);
        FightSemaphore::ensure_table_exists($db);
        $stmt = $db->prepare("
            SELECT fight_players.fightid
            FROM fight_players
            JOIN fight_semaphore ON fight_semaphore.fightid = fight_players.fightid
            WHERE fight_players.playerid = ? AND fight_semaphore.state != ?
        ");
        $stmt->execute([$playerid, FightStatus::Resolved->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : (int) $row['fightid'];
    }

    private static function from_row(array $row): FightPlayerState {
        return new FightPlayerState(
            (int) $row['fightid'],
            (int) $row['playerid'],
            (int) $row['teamid'],
            (int) $row['player_party'],
            $row['seen'] === 'yes',
            (int) $row['sequence'],
            (int) ($row['played_sequence'] ?? -1),
        );
    }

    // Every player's state for one fight, gone -- see
    // FightAction::delete_all_by_fightid()'s own docblock for why this
    // exists at all.
    // One player out of a fight (an admin handing the monsters back to
    // the AI -- see MonsterSide::leave_to_spectate()).
    public static function delete_one(Database $db, int $fightid, int $playerid): void {
        static::ensure_table_exists($db);
        $db->prepare("DELETE FROM fight_players WHERE fightid = ? AND playerid = ?")->execute([$fightid, $playerid]);
    }

    public static function delete_all_by_fightid(Database $db, int $fightid): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM fight_players WHERE fightid = ?");
        $stmt->execute([$fightid]);
    }
}
