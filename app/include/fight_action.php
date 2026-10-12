<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'party.php';

/**
 * FightAction wraps one row of the fight_actions table: one resolved
 * turn's action list for one fight, plus an optional dehydrated
 * final_state for validation. Exclusively this table -- fights.
 * initial_state (FIGHT's own dehydrated base state, written once at
 * creation) is FIGHT's own concern, not this class's; see FIGHT's
 * own comments for that half.
 *
 * Deliberately doesn't touch fight_actions' existing prefight_data
 * column at all -- that's the legacy, full-snapshot-per-turn approach
 * this object is specifically not reproducing (the whole point of
 * initial_state + an action log is that a full snapshot per turn is
 * redundant once reintegration exists). fight_store.php's own,
 * existing writes still set it as they always have; this class simply
 * never reads or writes it.
 *
 * action_data (this table's original, legacy column) and action_json
 * (new, temporary) both hold the same logical action list in two
 * formats: action_data as PHP serialize() -- the format fight_store.php's
 * own insert already writes, and every existing row already contains --
 * and action_json as json_encode(), for this class's own use. save()
 * writes both (action_data redundantly matches what fight_store.php's
 * own insert just wrote moments earlier for the same row; action_json
 * is this class's only new contribution). Reading back prefers
 * action_json, falling back to unserialize(action_data) for rows
 * written before action_json existed. Temporary: once reintegration's
 * actual data-format needs are known, action_data/action_json collapse
 * back into one column.
 *
 * Not a DbStoreTrait entity: fight_actions' primary key is the
 * composite (fightid, sequence), not a single auto-incrementing
 * column, which that trait doesn't support -- same situation as
 * TEAM's team_relations table, handled the same way (manual
 * persistence via Database::upsert_query()/replace_row(), not the
 * trait).
 *
 * Reintegration (replaying an action list to advance a hydrated FIGHT
 * by one turn) is deliberately not part of this class yet -- staged
 * for later, once the persistence/validation plumbing here is proven
 * against real gameplay data.
 */
class FightAction {
    private static bool $table_verified = false;

    // Message types recorded in action_data purely for server-side
    // bookkeeping, never meant for the client -- XpDebt exists solely
    // so xp_debts survives a FIGHT reconstruction (see
    // CHARACTER::add_debt_to_xp_tree()/FIGHT::process_fight_action()'s
    // own XpDebt case); sending it to the client would reveal exact
    // kill-credit/contribution math the game has never displayed.
    private const SERVER_ONLY_MESSAGE_TYPES = ['XpDebt'];

    // Strips server-only message types from an action_data list, and
    // sanitizes a NewParty message's party payload for the requesting
    // client -- preserving order and re-indexing (array_filter()
    // alone leaves gaps, which would round-trip through json_encode()
    // as a JSON object instead of an array). Applied once, upstream,
    // wherever an action_data list is about to reach a client (see
    // FightCommunicator::get_fight_view()) -- not applied to what
    // gets saved via save() below, which must keep every message type
    // intact (and NewParty's payload raw/unsanitized) for
    // FIGHT::process_fight_action()'s own server-side reconstruction
    // to work.
    //
    // $client_teamid, not the real trust-resolved teamid: matches
    // every other client-projection call site this session (see
    // FightCommunicator::get_fight_view()'s own $client_teamid, null
    // for a guid-viewer) -- a NewParty message's raw payload is a
    // PARTY::dehydrate() (the same shape initial_state/final_state
    // use), and sending that as-is would leak the joining party's own
    // abilities/equipment/inventory/command/using/target to every
    // viewer regardless of which team it actually belongs to, the
    // same leak class already fixed elsewhere for the rest of a
    // fight's data.
    public static function filter_for_client(array $action_data, int|array|null $client_teamid): array {
        return array_values(array_map(
            fn($message) => $message[0] === 'NewParty'
                ? ['NewParty', [PARTY::hydrate($message[1][0])->to_client($client_teamid)]]
                : $message,
            array_filter(
                $action_data,
                fn($message) => !in_array($message[0], self::SERVER_ONLY_MESSAGE_TYPES, true)
            )
        ));
    }

    // Overkill each party took -- damage past what it took to kill its
    // members -- from a fight's messages: [party index => total]. Deaths
    // recorded before overkill was (a Died message's fifth field) count 0.
    public static function overkill_taken(array $messages): array {
        $taken = [];
        foreach ($messages as $message) {
            if (($message[0] ?? null) === 'Died') {
                $party = (int) $message[1][0];
                $taken[$party] = ($taken[$party] ?? 0) + max(0, (int) ($message[1][4] ?? 0));
            }
        }
        return $taken;
    }

    public int $fightid;
    public int $sequence;
    public array $action_data;
    public ?array $final_state;

    public function __construct(int $fightid, int $sequence, array $action_data, ?array $final_state = null) {
        $this->fightid = $fightid;
        $this->sequence = $sequence;
        $this->action_data = $action_data;
        $this->final_state = $final_state;
    }

    // $prefight_data_default_sql is MySQL-specific: fight_actions
    // already exists there with prefight_data NOT NULL (relaxed to
    // nullable by fight-schema-v2.php, but a fresh SQLite/Postgres
    // deployment -- which fight_store.php's own mysqli-based DDL can
    // never reach at all -- needs this table fully self-sufficient
    // from this class alone, prefight_data included, so it stays
    // NULL-able here too rather than assuming the migration already ran).
    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_actions",
            <<<QUERY
CREATE TABLE IF NOT EXISTS fight_actions (
  fightid int NOT NULL,
  sequence int NOT NULL,
  prefight_data text NULL,
  action_data text NOT NULL,
  action_json text NULL,
  final_state text NULL,
  PRIMARY KEY (fightid, sequence)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public function save(Database $db): void {
        static::ensure_table_exists($db);
        $columns = ['fightid', 'sequence', 'action_data', 'action_json', 'final_state'];
        $query = $db->upsert_query('fight_actions', $columns, ['fightid', 'sequence']);
        $stmt = $db->prepare($query);
        $stmt->execute([
            $this->fightid,
            $this->sequence,
            serialize($this->action_data),
            json_encode($this->action_data),
            $this->final_state === null ? null : json_encode($this->final_state),
        ]);
    }

    public static function load(Database $db, int $fightid, int $sequence): ?FightAction {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_actions WHERE fightid = ? AND sequence = ?");
        $stmt->execute([$fightid, $sequence]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::from_row($row);
    }

    // Ordered by sequence -- callers advancing a FIGHT forward need
    // these applied in turn order, not whatever order the database
    // happens to return them in.
    public static function load_all_by_fightid(Database $db, int $fightid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_actions WHERE fightid = ? ORDER BY sequence");
        $stmt->execute([$fightid]);
        return array_map(
            fn($row) => static::from_row($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    // Every recorded action, across every fight -- for the database
    // export. No include_inactive-style flag: unlike ABILITY/ITEM/JOB
    // and friends, FightAction has no active/inactive concept at all,
    // so there's nothing to filter by. Plain, unindexed list, not
    // fightid => object like TEAM::load_all_from_database(): a fight
    // has many actions (one per resolved round), so fightid alone
    // could never be a unique key here the way it is for those
    // single-row-per-entity classes.
    public static function load_all_from_database(Database $db): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_actions ORDER BY fightid, sequence");
        $stmt->execute();
        return array_map(
            fn($row) => static::from_row($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private static function from_row(array $row): FightAction {
        // Prefer action_json (this class's own column); rows written
        // before it existed only have the legacy serialize()d
        // action_data, so fall back to that rather than losing them.
        $action_data = $row['action_json'] !== null
            ? json_decode($row['action_json'], true)
            : unserialize($row['action_data']);
        return new FightAction(
            (int) $row['fightid'],
            (int) $row['sequence'],
            $action_data,
            $row['final_state'] === null ? null : json_decode($row['final_state'], true),
        );
    }

    // Every recorded action for one fight, gone -- for genuinely
    // deleting a fight outright (as opposed to change_object_state()-
    // style retirement, which every real, player-owned fight uses
    // instead), a need this class hasn't had until system-generated
    // simulated fights, which really do just get thrown away and
    // regenerated. See FightCommunicator::delete_fight(), which
    // coordinates this alongside every other table a fight touches.
    public static function delete_all_by_fightid(Database $db, int $fightid): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM fight_actions WHERE fightid = ?");
        $stmt->execute([$fightid]);
    }
}
