<?php

/**
 * team.php
 * defines teams and creates team functionality
 * @version 0.1.0
 * @copyright 2003 Mike Wilmes
 **/

require_once INCLUDE_GAME . 'character.php';
require_once INCLUDE_GAME . 'hero.php';

enum RelationState: int {
    case ALLY = 0;
    case NEUTRAL = 1;
    case ENEMY = 2;
}

class TEAM implements Countable, Iterator, ArrayAccess, DbStoreInterface, HardDeleteInterface {
    use DbStoreTrait {
        save as private save_row;
        change_object_state as private change_object_state_base;
    }
    use HardDeleteTrait {
        delete as private delete_row;
    }

    const MAX_TEAMS = 3;
    const MAX_COUNT = 4;

    protected static string $table_name = 'teams';
    protected static string $table_key = 'teamid';

    protected static string $drop_sql = "drop table if exists teams";
    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('teamid');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS teams (
  $id_column,
  playerid int NOT NULL default 0,
  name varchar(64) NOT NULL default '',
  gold int NOT NULL default 0,
  quests JSON NOT NULL default ('[]'),
  state int NOT NULL default 0,
  deactivated_at TIMESTAMP NULL,
  guid varchar(32) NOT NULL default ''
);
QUERY;
    }
    // Order must match translate_to_store()'s return order exactly.
    protected static array $save_columns = ['teamid', 'playerid', 'name', 'gold', 'quests', 'state', 'deactivated_at', 'guid'];

    // team_relations stays a separate, relational table -- not JSON like
    // quests -- specifically because deleting a team needs to cascade and
    // strip every OTHER team's relation pointing at it, which wants an
    // index, not a full scan across every team's JSON blob. Not covered by
    // DbStoreTrait::ensure_table_exists(), which is scoped to one table
    // per class (teams) -- managed explicitly instead.
    private static bool $relations_table_verified = false;
    private static string $relations_drop_sql = "drop table if exists team_relations";
    private static string $relations_create_query = <<<QUERY
CREATE TABLE IF NOT EXISTS team_relations (
  teamid int NOT NULL default 0,
  otherteamid int NOT NULL default 0,
  state int NOT NULL default 1,
  PRIMARY KEY (teamid, otherteamid)
);
QUERY;

    public static function ensure_relations_table_exists(Database $db, bool $reset = false): void {
        if (static::$relations_table_verified) {
            return;
        }
        $db->ensure_table_exists(
            static::$relations_drop_sql,
            static::$relations_create_query,
            $reset
        );
        static::$relations_table_verified = true;
    }

    private function __construct(
        public ?int $teamid,
        public int $playerid,
        public string $name,
        public int $gold,
        public array $characters,
        public array $quests,
        public array $relations,
        public ObjectState $state,
        public ?DateTimeImmutable $deactivated_at,
        public string $guid,
    ) {
    }

    // Pure persistence -- insert-or-update is handled transparently by the
    // underlying trait based on teamid's nullness, same as ever. No policy
    // branching here at all: the private constructor already guarantees no
    // external caller can ever construct a teamid===null TEAM and call this
    // directly, bypassing create()'s room-check/eviction gate. The only
    // place a teamid===null TEAM exists is briefly inside create() itself,
    // right before this same method assigns it a real one.
    public function save(Database $db): void {
        $this->save_row($db);

        $db->delete_where('team_relations', 'teamid', $this->teamid);
        foreach ($this->relations as $otherteamid => $state) {
            $db->replace_row('team_relations', [
                'teamid' => $this->teamid,
                'otherteamid' => $otherteamid,
                'state' => $state->value,
            ], ['teamid', 'otherteamid']);
        }
    }

    // The only sanctioned way to create a brand-new team. Enforces the
    // MAX_TEAMS policy up front, then builds and persists the team and
    // every character atomically -- the object returned already has a real
    // teamid and fully-populated characters, ready for immediate use.
    public static function create(Database $db, int $playerid, string $name, array $character_specs): TEAM {
        if (!static::has_room_for_new_team($db, $playerid)) {
            throw new Exception("Player {$playerid} already has the maximum number of active teams.");
        }

        $db->begin_transaction();
        try {
            $existing = static::load_all_by_playerid($db, $playerid);
            $inactive = array_filter($existing, fn($t) => $t->state === ObjectState::INACTIVE);
            uasort($inactive, fn($a, $b) => $a->deactivated_at <=> $b->deactivated_at);
            while (count($existing) >= static::MAX_TEAMS) {
                $oldest = array_shift($inactive);
                $oldest->delete($db);
                unset($existing[$oldest->teamid]);
            }

            $team = new TEAM(
                null,
                $playerid,
                $name,
                0,
                [],
                [],
                [],
                ObjectState::ACTIVE,
                null,
                bin2hex(random_bytes(16)),
            );
            $team->save($db);

            foreach ($character_specs as $spec) {
                $character = CHARACTER::create_hero($spec['name'], $spec['jobid'], 1, $spec['personalityid']);
                $character->teamid = $team->teamid;
                HERO::save_character($db, $character);
                $team->characters[] = $character;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return $team;
    }

    // deactivated_at is pure ordering data for eviction, never a status
    // shortcut -- state alone is the source of truth for active/inactive.
    // Stamped only on the actual transition into INACTIVE, so reactivating
    // and later re-deactivating correctly overwrites to the newer time.
    public function change_object_state(Database $db, ObjectState $state): void {
        if ($state === ObjectState::INACTIVE && $this->state !== ObjectState::INACTIVE) {
            $this->deactivated_at = new DateTimeImmutable();
        }
        $this->change_object_state_base($db, $state);
    }

    // Cascades to team_relations in both directions: this team's own
    // outgoing relations (teamid = X, meaningless once X is gone) and every
    // other team's relation pointing at this one (otherteamid = X, the
    // actual "permanently filter out deleted otherteamids" requirement).
    public function delete(Database $db): void {
        $db->delete_where('team_relations', 'teamid', $this->teamid);
        $db->delete_where('team_relations', 'otherteamid', $this->teamid);
        $this->delete_row($db);
    }

    public static function has_room_for_new_team(Database $db, int $playerid): bool {
        $teams = static::load_all_by_playerid($db, $playerid);
        $active_count = count(array_filter($teams, fn($t) => $t->state === ObjectState::ACTIVE));
        return $active_count < static::MAX_TEAMS;
    }

    public static function load_all_by_playerid(Database $db, int $playerid): array {
        $rows = $db->get_all_where(static::$table_name, 'playerid', $playerid);
        $teams = array_combine(
            array_column($rows, static::$table_key),
            array_map(fn($row) => static::translate_and_build($row), $rows)
        );
        foreach ($teams as $team) {
            static::load_related($db, $team);
        }
        return $teams;
    }

    // Attaches everything that does NOT live in the teams row itself:
    // characters (from HERO, via heroes.teamid) and relations (from
    // team_relations). translate_and_build() only ever reformats the row
    // data it's handed -- no querying elsewhere. This is that querying,
    // applied to an already-built TEAM. Called automatically by the
    // trait's own load() and load_all_from_database(), and explicitly here
    // by load_all_by_playerid() too, so all three ways of getting a fully-
    // populated TEAM share this one implementation rather than each
    // duplicating it.
    protected static function load_related(Database $db, self $object): void {
        $object->characters = array_values(HERO::load_all_by_teamid($db, $object->teamid));
        $object->relations = static::load_relations($db, $object->teamid);
    }

    // Wraps load(), then destructively scrubs relations pointing at
    // inactive teams -- the object returned is ready for gameplay use as-is.
    // Admin code should call load() directly instead; the two paths are
    // kept fully separate, never touching the same instance.
    public static function load_for_gameplay(Database $db, int $id): static {
        $team = static::load($db, $id);        
        if (count($team->relations) > 0) {
            $active_otherteamids = static::filter_active_teamids($db, array_keys($team->relations));
            $team->relations = array_intersect_key($team->relations, array_flip($active_otherteamids));
        }
        return $team;
    }

    // Lightweight batch check: which of these teamids are currently
    // ACTIVE, without loading each one's full data (characters, relations,
    // etc) just to read a single field.
    public static function filter_active_teamids(Database $db, array $teamids): array {
        $rows = $db->get_all_where_in(static::$table_name, static::$table_key, $teamids);
        return array_values(array_map(
            fn($row) => (int) $row[static::$table_key],
            array_filter($rows, fn($row) => (int) $row['state'] === ObjectState::ACTIVE->value)
        ));
    }

    private static function load_relations(Database $db, int $teamid): array {
        $rows = $db->get_all_where('team_relations', 'teamid', $teamid);
        $relations = [];
        foreach ($rows as $row) {
            $relations[(int) $row['otherteamid']] = RelationState::from((int) $row['state']);
        }
        return $relations;
    }

    public function get_relations(): array {
        return $this->relations;
    }

    // Pure reformatting: takes the teams row data this entity's own table
    // already returned, and reshapes it into a TEAM. No querying anywhere
    // else -- characters/relations start empty here (they don't live in
    // this row at all) and get attached afterward, separately, by
    // load_related(). Keeping that fetching entirely out of this method is
    // deliberate: translate_and_build()'s job is "reformat what I was
    // handed," never "go get more data."
    protected static function translate_and_build(array $data): TEAM {        
        return new TEAM(
            (int) $data['teamid'],
            (int) $data['playerid'],
            $data['name'],
            (int) $data['gold'],
            [],
            json_decode($data['quests'], true),
            [],
            ObjectState::from((int) $data['state']),
            $data['deactivated_at'] !== null ? new DateTimeImmutable($data['deactivated_at']) : null,
            $data['guid'],
        );
    }

    public function translate_to_store(): array {
        return [
            $this->teamid,
            $this->playerid,
            $this->name,
            $this->gold,
            json_encode($this->quests),
            $this->state->value,
            $this->deactivated_at?->format('Y-m-d H:i:s'),
            $this->guid,
        ];
    }

    // Public share-link lookup: resolves a team's guid back to the
    // real TEAM, or null if the guid is unrecognized -- a guessed or
    // stale guid is a normal, expected case here, not an error.
    public static function get_team_from_guid(Database $db, string $guid): ?TEAM {
        $stmt = $db->prepare("SELECT teamid FROM teams WHERE guid = ?");
        $stmt->execute([$guid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::load($db, (int) $row['teamid']);
    }

    function get_character(int $index) :?CHARACTER {
        if (!isset($this->characters[$index])) {
            log_error("Team -> character:Invalid index");
            return null;
        }            
        return $this->characters[$index];
    }

    function get_character_position(CHARACTER $character) : ?array {
        foreach (array_values($this->characters) as $index=>$char)
            if ($char === $character)
                return [$index];
        return null;
    }

    function get_character_position_by_id(int $charid) : ?array {
        foreach (array_values($this->characters) as $index=>$char)
            if ($char->charid === $charid){
                return [$index];
            }
        return null;
    }

    function get_team_id(): ?int {
        return $this->teamid;
    }

    function count(): int {
        return count($this->characters);
    }

    function rewind() : void {
        reset($this->characters);
    }

    function valid() : bool {
        return key($this->characters) !== null;
    }

    function next() : void {
        next($this->characters);
    }    

    function current() : mixed {
        return current($this->characters);
    }

    function key() : mixed {
        return key($this->characters);
    }

    function to_js(): string|false {
        return json_encode(get_object_vars($this));
    }

    function get_pxp(): int {
        $pxp = 0;
        foreach (array_keys($this->characters) as $character)
            $pxp += $this->characters[$character]->pxp;
        return $pxp;
    }

    function charid_in_team(int $charid) : int|bool {
        foreach (array_keys($this->characters) as $index)
            if ($this->characters[$index]->charid == $charid)
                return $index;
        return false;
    }

    function refresh_team(Database $db): void {
        foreach (array_keys($this->characters) as $index)
            $this->characters[$index] = HERO::load_character($db, $this->characters[$index]->charid);
    }

    function store_team(Database $db):void {
        foreach ($this->characters as $character){
            HERO::save_character($db, $character);
        }
        $this->save($db);
    }

    public function offsetExists(mixed $offset): bool {
        return array_key_exists($offset, $this->characters);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->get_character($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        throw new Exception("Team->offsetSet: Invalid operation. Use Team->add() methods to add a character.");
    }

    public function offsetUnset(mixed $offset): void {
        throw new Exception("Team->offsetUnset: Invalid operation. Teams can never lose characters.");
    }
}