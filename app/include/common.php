<?php
require_once 'database.php';

enum ObjectState: int {
    case ACTIVE = 0;
    case INACTIVE = 1;
}

interface DbStoreInterface {
    static function load(Database $db, int $id): static;
    function save(Database $db): void;                          // insert if id is null, else update
    function change_object_state(Database $db, ObjectState $state): void;  // sets state, calls write_cache_file()    
    static function load_all_from_database(Database $db, bool $include_inactive = false): array;         // DB read
}

interface FileCacheInterface extends DbStoreInterface {    
    static function get(int $id): static;
    static function load_all_from_cache(): array;         // cache read
    static function filter_active(array $ids): array;                  // batch check, for CHARACTER::load()    
    static function write_cache_file(Database $db): void;                      // regenerates the flat file
}

// Deliberately separate from DbStoreInterface -- most entities (ABILITY,
// ITEM, JOB, PERSONALITY, MONSTER) only ever retire via change_object_state(),
// never a real delete. Only classes that opt into this interface (and its
// matching trait) get delete() at all.
interface HardDeleteInterface {
    function delete(Database $db): void;
}

// For classes needing a full, lossless state round-trip -- database
// storage of live/mid-fight state, not persistent-record storage
// (that's DbStoreInterface's job). dehydrate()/hydrate() are meant to
// capture everything needed to fully reconstitute an object, including
// transient state a DbStoreInterface row would never carry.
interface DehydrateInterface {
    function dehydrate(): array;
    static function hydrate(array $data): static;
}

trait DbStoreTrait {
    private static bool $table_verified = false;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified) {
            return;
        }
        if (is_null(static::$drop_sql)) {
            throw new Exception("Table SQL statements not defined for " . static::class);
        }
        $db->ensure_table_exists(
            static::$drop_sql,
            static::create_query($db),
            $reset
        );
        static::$table_verified = true;
    }

    public function change_object_state(Database $db, ObjectState $state): void {  // sets state, saves the object
        $this->state = $state;
        $this->save($db);        
    }

    // translate_and_build()'s job is pure reformatting: take the row data
    // this entity's own table already handed back, and reshape it into an
    // object. No I/O, no querying anywhere else -- every entity implements
    // this themselves, working only with what's already in $data.
    //
    // load_related() is the opposite: fetching data that does NOT live in
    // this entity's own table (a related table, another entity's data via
    // its own load method, etc) and attaching it to an already-built
    // object. Default here is a no-op, since most entities (ABILITY, ITEM,
    // JOB, PERSONALITY, MONSTER, HERO) are fully described by their own
    // row and need nothing further. TEAM overrides this to attach
    // characters (from HERO, via heroes.teamid) and relations (from
    // team_relations) -- neither of which live in the teams row itself.
    //
    // Both load() and load_all_from_database() call load_related() after
    // translate_and_build(), on the object(s) already built -- never by
    // re-querying the base row. That matters for load_all_from_database()
    // specifically: it already has every row from one bulk get_all() call,
    // so load_related() enriches each already-built object directly,
    // rather than the alternative of calling load() per row, which would
    // silently turn one bulk query into N redundant, wasteful re-fetches
    // of data already in hand.
    protected static function load_related(Database $db, self $object): void {
    }

    public static function load(Database $db, int $id): static {
        $data = $db->get_by_id(static::$table_name, static::$table_key, $id);
        if ($data === null) {
            throw new Exception(static::class . " with ID $id not found in database.");
        }
        $object = static::translate_and_build($data);
        static::load_related($db, $object);
        return $object;
    }

    public static function load_all_from_database(Database $db, bool $include_inactive = false): array {         // DB read
        $rows = $db->get_all(static::$table_name, $include_inactive);
        $objects = array_combine(
            array_column($rows, static::$table_key),
            array_map(fn($row) => static::translate_and_build($row), $rows)
        );
        foreach ($objects as $object) {
            static::load_related($db, $object);
        }
        return $objects;
    }

    public function save(Database $db): void { // insert if id is null, else update
        $id_name = static::$table_key;
        $is_new = $this->$id_name === null;
        $bind_args = $this->translate_to_store();

        if ($is_new) {
            // The primary key column is left out of the statement
            // entirely here, not just bound as null -- Postgres's
            // identity columns only auto-assign when the column is
            // omitted outright (see Database::insert_query()'s comment).
            $key_index = array_search(static::$table_key, static::$save_columns);
            $columns = static::$save_columns;
            unset($columns[$key_index]);
            unset($bind_args[$key_index]);
            $query = $db->insert_query(static::$table_name, array_values($columns));
            $bind_args = array_values($bind_args);
        } else {
            $query = $db->upsert_query(static::$table_name, static::$save_columns, static::$table_key);
        }

        $stmt = $db->prepare($query);
        // PDO binds positionally from a plain value array -- no
        // mysqli-style "i"/"s" type-string needed.
        $stmt->execute($bind_args);
        if ($is_new) {
            $this->$id_name = $db->mysql_do_insert_id();
        }
        # Save shouldn't trigger a cache file rewrite. The db is committed to cache through the admin panel.
    }
}


// Depends on the using class also having DbStoreTrait -- reuses its
// ensure_table_exists()/$table_name/$table_key rather than duplicating them.
trait HardDeleteTrait {
    public function delete(Database $db): void {
        static::ensure_table_exists($db);
        $id_name = static::$table_key;
        $db->delete_by_id(static::$table_name, static::$table_key, $this->$id_name);
    }
}

// Depends on the using class defining DEHYDRATE_VERSION and
// DEHYDRATE_PROPERTIES (the flat, direct-projection properties --
// scalars and plain arrays, not nested objects).
trait DehydrateTrait {
    // Builds $property_name => $this->$property_name for each name
    // given -- the direct 1:1 projections that make up most of
    // dehydrate()'s work. dehydrate_nested() handles what this can't:
    // properties that are themselves objects (or arrays of objects)
    // needing their own recursive dehydrate() call rather than a bare
    // assignment.
    private function project_properties(array $property_names): array {
        $data = [];
        foreach ($property_names as $property_name)
            $data[$property_name] = $this->$property_name;
        return $data;
    }

    // The inverse of project_properties(): assigns $data[$property_name]
    // onto $this->$property_name for each name given.
    private function assign_properties(array $data, array $property_names): void {
        foreach ($property_names as $property_name)
            $this->$property_name = $data[$property_name];
    }

    // Override for any property that's an object (or array of
    // objects) rather than a scalar/plain array -- return
    // $property_name => already-dehydrated value for each. Default is
    // no properties need this, matching DbStoreTrait's load_related()
    // default no-op for classes that don't need it.
    protected function dehydrate_nested(): array {
        return [];
    }

    // The inverse of dehydrate_nested(): assign nested, still-hydrated
    // objects onto $object from $data. Called after assign_properties(),
    // so $object's flat properties are already set by the time this runs.
    protected static function hydrate_nested(self $object, array $data): void {
    }

    function dehydrate(): array {
        $data = $this->project_properties(static::DEHYDRATE_PROPERTIES);
        $data['version'] = static::DEHYDRATE_VERSION;
        return array_merge($data, $this->dehydrate_nested());
    }

    // No version migration exists yet -- there's only ever been
    // version 1 for any class using this trait so far -- but
    // $data['version'] is where a migration chain would branch from,
    // once dehydrate()'s shape for some class actually changes.
    static function hydrate(array $data): static {
        // Bypasses the constructor: hydrate() restores previously-saved
        // state, it doesn't create a fresh object, so the constructor's
        // own initialization is both unnecessary (assign_properties()
        // below immediately overwrites everything it would set) and,
        // for a class like FIGHT with required constructor parameters,
        // impossible without synthesizing fake arguments.
        $object = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $object->assign_properties($data, static::DEHYDRATE_PROPERTIES);
        static::hydrate_nested($object, $data);
        return $object;
    }
}

trait FileCacheTrait {
    private static ?array $cache = null;

    public static function load_all_from_cache(): array {
        $rows = include static::$cache_path;
        static::$cache = array_combine(
            array_column($rows, static::$table_key),
            array_map(fn($row) => static::translate_and_build($row), $rows)
        );
        return array_slice(static::$cache, 0, preserve_keys: true);
    }

    public static function get(int $id): static {
        if (static::$cache === null) {
            static::load_all_from_cache();
        }
        if (array_key_exists($id, static::$cache)) {
            return static::$cache[$id];
        }
        throw new Exception(static::class . " with ID $id not found in cache.");
    }

    public static function filter_active(array $ids): array {
        if (static::$cache === null) {
            static::load_all_from_cache();
        }
        return array_filter($ids, fn($id) =>
            isset(static::$cache[$id]) && static::$cache[$id]->state === ObjectState::ACTIVE);
    }

    public static function write_cache_file(Database $db): void {
        file_put_contents(static::$cache_path, '<?php return ' . var_export($db->get_all(static::$table_name, static::$cache_includes_inactive), true) . ';');
    }
   
    // Default: no special wire-shape bridging needed. Override this (not
    // to_js()/all_to_js()) in a class that needs one -- see ABILITY.
    public function to_client_js_structure(): array {
        return get_object_vars($this);
    }

    public function to_js(): string {
        return json_encode($this->to_client_js_structure());
    }

    public static function all_to_js(): string {
        return json_encode(array_map(
            fn($obj) => $obj->to_client_js_structure(),
            static::load_all_from_cache()
        ));
    }
}

if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', INCLUDE_GAME . 'cache/');
}

// Ensures every DB-backed entity's table exists. Meant to be called
// exactly once, from login.php specifically: AUTH_LOGIN/
// authenticate_permission_access() route any unauthenticated request
// to any protected page (gameplay or admin alike) through login.php
// first, and on a fresh deployment nobody could possibly be logged in
// yet -- so this is guaranteed to run before any other request-
// handling code (including anything wrapped in an application-level
// transaction) ever could.
//
// This replaces the lazy, per-call ensure_table_exists() checks that
// used to live inside DbStoreTrait::save()/load()/load_all_from_database()
// and various per-entity custom query methods. Those were the actual
// root cause of a real bug: MySQL performs an implicit commit on any
// DDL statement (unlike Postgres/SQLite, which support transactional
// DDL) -- so the first-ever CREATE TABLE for a given entity, if it
// happened to run lazily inside an application-opened transaction
// (e.g. TEAM::create()'s), would silently end that transaction early,
// and a later explicit commit() would fail. Centralizing every table's
// setup here, outside any transaction, removes that risk entirely
// rather than requiring every future transaction-wrapped method to
// remember to guard against it by hand.
//
// Requires every listed entity's class to already be loaded (each
// entity file requires common.php, not the other way around, to avoid
// a circular require) -- the caller must require_once each one first.
//
// Doesn't cover MAP_STORE/FIGHT_STORE: those are still on
// the separate, legacy mysqli-based mysql.php layer, not this
// Database/DbStoreTrait mechanism, and manage their own table setup
// independently -- fights/fight_players/fight_actions' own
// pre-existing columns included. FightAction, PendingAction,
// FightSemaphore, FightPlayerState, and FightSave are each a
// different case, despite living alongside FIGHT_STORE conceptually
// and none being a DbStoreTrait entity itself (see their own files
// for why): all five
// use this same Database/PDO mechanism, not mysqli, and each already exposes the same static
// ensure_table_exists(Database $db) every entity above does -- so
// there's no reason for any of them to keep managing its own lazy,
// per-call setup instead, and every reason not to (see FIGHT_STORE::
// update_fight()'s FightAction::save() call for a concrete case where
// that laziness could otherwise fail outright on a fresh deployment:
// CREATE TABLE for a genuinely new table while a different table is
// already LOCK TABLES-locked in the same session is a hard MySQL
// error, not merely an implicit-commit risk).
function ensure_all_databases_exist(Database $db): void {
    foreach ([
        ABILITY::class,
        FIGHT::class,
        FightAction::class,
        PendingAction::class,
        FightSemaphore::class,
        FightPlayerState::class,
        FightSave::class,
        HERO::class,
        ITEM::class,
        JOB::class,
        MONSTER::class,
        PERSONALITY::class,
        TEAM::class,
        UserAccount::class,
        PermissionGrant::class,
        AppSetting::class,
        AccountToken::class,
        RateLimit::class,
        RecoveryCode::class,
        SecurityKey::class,
        GameLog::class,
        MonsterToken::class,
        Alliance::class,
        MonsterQueue::class,
        MonsterLockout::class,
        MonsterSeat::class,
        PlayerProfile::class,
    ] as $class) {
        $class::ensure_table_exists($db);
    }
    // team_relations is a second table TEAM manages internally,
    // alongside its own -- not exposed via the standard
    // DbStoreInterface::ensure_table_exists() contract every other
    // entity uses, so it needs its own explicit call here too.
    TEAM::ensure_relations_table_exists($db);
}