<?php

/**
 * hero.php
 * HERO is a throwaway translation layer: it exists only long enough to
 * convert data between the database and CHARACTER, and is never held onto
 * or returned to any caller. All external access goes through exactly two
 * static functions: load_character() and save_character(), both of which
 * operate on and return CHARACTER instances.
 **/

require_once INCLUDE_GAME . 'character.php';

class HERO implements DbStoreInterface, HardDeleteInterface {
    use DbStoreTrait;
    use HardDeleteTrait;

    protected static string $table_name = 'heroes';
    protected static string $table_key = 'characterid';

    protected static string $drop_sql = "drop table if exists heroes";
    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('characterid');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS heroes (
  $id_column,
  name varchar(64) NOT NULL default '',
  personalityid int NOT NULL default 0,
  jobid int NOT NULL default 0,
  level int NOT NULL default 0,
  experience int NOT NULL default 0,
  need int NOT NULL default 0,
  base JSON NOT NULL,
  current JSON NOT NULL,
  abilities JSON NOT NULL default ('[]'),
  items JSON NOT NULL default ('{}'),
  equipment JSON NOT NULL default ('{}'),
  teamid int NOT NULL default 0
);
QUERY;
    }
    // Order must match translate_to_store()'s return order exactly.
    protected static array $save_columns = [
        'characterid', 'name',
        'personalityid', 'jobid', 'level', 'experience', 'need',
        'base', 'current', 'abilities', 'items', 'equipment',
        'teamid',
    ];

    public ?int $characterid = null;

    # Request-scoped: charid -> CHARACTER. Populated by load_character() and
    # save_character(), never by anything else. This is what actually closes
    # the "same charid, different object" gap -- caching HERO alone (an
    # earlier draft) didn't reach far enough, since every real caller wants
    # a CHARACTER, not a HERO.
    private static array $character_cache = [];

    private function __construct(
        public string $name,
        public int $personalityid,
        public int $jobid,
        public int $level,
        public int $experience,
        public int $need,
        public array $base,
        public array $current,
        public array $abilities,
        public array $items,
        public array $equipment,
        public int $teamid,
    ) {
    }

    private function make_character(): CHARACTER {
        $character = new CHARACTER;
        $character->make_hero(
            $this->characterid ?? 0,
            $this->name,
            $this->jobid,
            $this->level,
            $this->experience,
            $this->need,
            $this->base,
            $this->current,
            $this->abilities,
            $this->items,
            $this->equipment,
            $this->personalityid,
            $this->teamid,
        );
        return $character;
    }

    private static function from_character(CHARACTER $character): self {
        $hero = new self(
            $character->name,
            $character->personalityid,
            $character->jobid,
            $character->level,
            $character->exp,
            $character->need,
            $character->base,
            $character->current,
            $character->abilities,
            $character->inventory,
            $character->equipment,
            $character->teamid,
        );
        // HERO's own charid stays the standard null-means-new convention,
        // shared with every other DbStoreTrait entity -- 0-means-new is
        // CHARACTER's own safeguard against accidentally saving a monster,
        // not something the trait's save() should ever see.
        $hero->characterid = ($character->charid === 0) ? null : $character->charid;
        return $hero;
    }

    public static function load_character(Database $db, int $charid): CHARACTER {
        if (!array_key_exists($charid, static::$character_cache)) {
            $hero = static::load($db, $charid);
            $character = $hero->make_character();
            static::$character_cache[$charid] = $character;
        }
        return static::$character_cache[$charid];
    }

    // Monster-spawned CHARACTERs always have charid === null (confirmed:
    // make_monster() sets this explicitly). 0 is never a value that arises
    // naturally -- only ever set deliberately, by something that means to
    // create a brand-new hero. Rejecting null here is the actual safeguard.
    public static function save_character(Database $db, CHARACTER $character): void {
        if ($character->charid === null) {
            throw new Exception("Refusing to save a CHARACTER with charid === null -- this looks like a monster instance, not a hero. New heroes must have charid explicitly set to 0.");
        }
        $hero = static::from_character($character);
        $hero->save($db);
        if ($character->charid !== $hero->characterid) {
            $character->charid = $hero->characterid;
        }
        static::$character_cache[$character->charid] = $character;
    }

    public static function load_all_by_teamid(Database $db, int $teamid): array {
        $rows = $db->get_all_where(static::$table_name, 'teamid', $teamid);
        usort($rows, fn($a, $b) => (int) $a[static::$table_key] <=> (int) $b[static::$table_key]);
        // Goes through the same per-request cache as load_character(),
        // so a TEAM and the session PARTY share one CHARACTER object per
        // hero. Before this, TEAM::load() built its own separate copies:
        // a page that changed the party's copy (e.g. a store purchase)
        // and then saved the team's copy wrote the stale data back and
        // silently lost the change.
        $characters = [];
        foreach ($rows as $row) {
            $charid = (int) $row[static::$table_key];
            if (!array_key_exists($charid, static::$character_cache)) {
                static::$character_cache[$charid] = static::translate_and_build($row)->make_character();
            }
            $characters[$charid] = static::$character_cache[$charid];
        }
        return $characters;
    }

    // HERO has no `state` column at all -- passing true isn't "include
    // inactive heroes" the way it is for other entities, it's just how
    // get_all() skips its WHERE clause, giving every row unconditionally.
    public static function load_all(Database $db): array {
        $rows = $db->get_all(static::$table_name, true);
        $characters = [];
        foreach ($rows as $row) {
            $hero = static::translate_and_build($row);
            $character = $hero->make_character();
            static::$character_cache[$character->charid] = $character;
            $characters[$character->charid] = $character;
        }
        return $characters;
    }

    public static function delete_all_by_teamid(Database $db, int $teamid): void {
        $db->delete_where(static::$table_name, 'teamid', $teamid);
    }

    protected static function translate_and_build(array $data): HERO {
        $charid = $data['characterid'];
        unset($data['characterid']);
        $data['base'] = json_decode($data['base'], true);
        $data['current'] = json_decode($data['current'], true);
        $data['abilities'] = json_decode($data['abilities'], true);
        $data['items'] = json_decode($data['items'], true);
        $data['equipment'] = json_decode($data['equipment'], true);
        $hero = new HERO(...$data);
        $hero->characterid = $charid;
        return $hero;
    }

    public function translate_to_store(): array {
        return [
            $this->characterid,
            $this->name,
            $this->personalityid,
            $this->jobid,
            $this->level,
            $this->experience,
            $this->need,
            json_encode($this->base),
            json_encode($this->current),
            json_encode($this->abilities),
            json_encode($this->items),
            json_encode($this->equipment),
            $this->teamid,
        ];
    }
}