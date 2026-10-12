<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight_semaphore.php';

/**
 * PendingAction wraps one row of the fight_pending_actions table: one
 * character's most recently submitted command/using/target, plus which
 * player submitted it.
 *
 * Replaces relying on fights.fight_data to carry this -- a player's
 * submission previously just mutated the character in-memory and got
 * persisted as a side effect of saving the whole fight blob
 * (FIGHT_STORE::update_fight_action()). As fight_data is phased out in
 * favor of initial_state + FightAction-based reintegration, this data
 * needs its own home: it isn't a turn *outcome* (nothing in the action
 * log represents it, nor should it -- it's the opposite, a not-yet-
 * resolved input), so it doesn't belong in FightAction either.
 *
 * No sequence column, deliberately: existing gameplay already treats a
 * character's prior command as still in effect if the player doesn't
 * resubmit before the round resolves, so this is a plain per-character
 * upsert -- the latest submission simply overwrites the previous one,
 * for as long as the fight lasts. Rows aren't cleared between rounds;
 * only once the fight itself concludes would there be any reason to
 * remove them, and nothing currently does even that.
 *
 * Not a DbStoreTrait entity: the primary key is the composite
 * (fightid, party_index, group_index, char_index), which that trait
 * doesn't support -- same situation as FightAction and TEAM's
 * team_relations table, handled the same way (manual persistence via
 * Database::upsert_query()).
 */
class PendingAction {
    private static bool $table_verified = false;

    public int $fightid;
    public int $party_index;
    public int $group_index;
    public int $char_index;
    public int $playerid;
    public int $command;
    public int $using;
    public array $target;

    public function __construct(
        int $fightid,
        int $party_index,
        int $group_index,
        int $char_index,
        int $playerid,
        int $command,
        int $using,
        array $target
    ) {
        $this->fightid = $fightid;
        $this->party_index = $party_index;
        $this->group_index = $group_index;
        $this->char_index = $char_index;
        $this->playerid = $playerid;
        $this->command = $command;
        $this->using = $using;
        $this->target = $target;
    }

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_pending_actions",
            <<<QUERY
CREATE TABLE IF NOT EXISTS fight_pending_actions (
  fightid int NOT NULL,
  party_index int NOT NULL,
  group_index int NOT NULL,
  char_index int NOT NULL,
  playerid int NOT NULL,
  command int NOT NULL,
  using_slot int NOT NULL,
  target_json text NOT NULL,
  PRIMARY KEY (fightid, party_index, group_index, char_index)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public function save(Database $db): void {
        static::ensure_table_exists($db);
        $columns = ['fightid', 'party_index', 'group_index', 'char_index', 'playerid', 'command', 'using_slot', 'target_json'];
        $query = $db->upsert_query('fight_pending_actions', $columns, ['fightid', 'party_index', 'group_index', 'char_index']);
        $stmt = $db->prepare($query);
        $stmt->execute([
            $this->fightid,
            $this->party_index,
            $this->group_index,
            $this->char_index,
            $this->playerid,
            $this->command,
            $this->using,
            json_encode($this->target),
        ]);
    }

    public static function load(Database $db, int $fightid, int $party_index, int $group_index, int $char_index): ?PendingAction {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_pending_actions WHERE fightid = ? AND party_index = ? AND group_index = ? AND char_index = ?");
        $stmt->execute([$fightid, $party_index, $group_index, $char_index]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::from_row($row);
    }

    // Order is never meaningful here (unlike FightAction, there's no
    // sequence to preserve) -- callers apply each row to its own
    // character independently.
    public static function load_all_by_fightid(Database $db, int $fightid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_pending_actions WHERE fightid = ?");
        $stmt->execute([$fightid]);
        return array_map(
            fn($row) => static::from_row($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private static function from_row(array $row): PendingAction {
        return new PendingAction(
            (int) $row['fightid'],
            (int) $row['party_index'],
            (int) $row['group_index'],
            (int) $row['char_index'],
            (int) $row['playerid'],
            (int) $row['command'],
            (int) $row['using_slot'],
            json_decode($row['target_json'], true),
        );
    }

    // Every pending command for one fight, gone -- see
    // FightAction::delete_all_by_fightid()'s own docblock for why this
    // exists at all.
    public static function delete_all_by_fightid(Database $db, int $fightid): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM fight_pending_actions WHERE fightid = ?");
        $stmt->execute([$fightid]);
    }

    // A pending command only means anything while its fight is still
    // being played -- "what will this character do next round" has no
    // meaning once the fight is Resolved and there is no next round.
    // Unlike FightAction (the permanent record of what actually
    // happened) or FightSave (a permanent tag), this table is
    // ephemeral: nothing here is worth keeping once a fight is done,
    // and it has no place in a database export at all. This is the
    // maintenance sweep that reflects that -- deletes every row
    // belonging to an already-Resolved fight, leaving active fights'
    // rows untouched. Returns the number of rows removed.
    //
    // Must run BEFORE FightSemaphore::purge_resolved(), not after: the
    // subquery below identifies which fights are Resolved by querying
    // fight_semaphore, so once that table's own Resolved rows are
    // purged, there's no way left to tell whether an orphaned pending-
    // action row belonged to a Resolved fight or an active one. See
    // FightCommunicator::purge_ephemeral_state(), which runs both in
    // the correct order.
    public static function purge_resolved(Database $db): int {
        static::ensure_table_exists($db);
        FightSemaphore::ensure_table_exists($db);
        $stmt = $db->prepare(
            "DELETE FROM fight_pending_actions WHERE fightid IN (
                SELECT fightid FROM fight_semaphore WHERE state = ?
            )"
        );
        $stmt->execute([FightStatus::Resolved->value]);
        return $stmt->rowCount();
    }
}
