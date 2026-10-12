<?php

/**
 * monster.php
 * monsters/classes object
 * @version 0.1.0
 * @copyright 2003 Mike Wilmes
 **/

require_once INCLUDE_GAME . 'character.php';
require_once INCLUDE_GAME . 'group.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'item.php';

class MONSTER implements DbStoreInterface, FileCacheInterface {
    use DbStoreTrait;
    use FileCacheTrait;

    protected static string $table_name = 'monsters';
    protected static string $table_key = 'monsterid';
    protected static string $cache_path = CACHE_DIR . 'monsters.php';
    protected static bool $cache_includes_inactive = true;

    protected static string $drop_sql = "drop table if exists monsters";
    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('monsterid');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS monsters (
  $id_column,
  name varchar(64) NOT NULL default '',
  personalityid int NOT NULL default 0,
  pxp int NOT NULL default 0,
  gold int NOT NULL default 0,
  stats JSON NOT NULL default ('{"HP":0,"MP":0,"Speed":0,"Accuracy":0,"Strength":0,"Dodge":0,"Block":0,"Power":0,"Resistance":0,"Focus":0}'),
  abilities JSON NOT NULL default ('[]'),
  items JSON NOT NULL default ('[]'),
  equipment JSON NOT NULL default ('[]'),
  ai_action int NOT NULL default 0,
  ai_goal int NOT NULL default 0,
  ai_target int NOT NULL default 0,
  ai_experience int NOT NULL default 0,
  state int NOT NULL default 0
);
QUERY;
    }
    // Order must match translate_to_store()'s return order exactly.
    protected static array $save_columns = [
        'monsterid', 'name',
        'personalityid', 'pxp', 'gold',
        'stats', 'abilities', 'items', 'equipment',
        'ai_action', 'ai_goal', 'ai_target', 'ai_experience',
        'state',
    ];

    public ?int $monsterid = null;
    public ObjectState $state;
    public string $name;
    public int $personalityid;
    public int $gold;
    public array $stats;
    public array $abilities;
    public array $items;
    public array $equipment;
    public int $ai_action;
    public int $ai_goal;
    public int $ai_target;
    public int $ai_experience;

    # Calculated upon creation
    public int $pxp;

    public function __construct(
        string $name,
        int $personalityid,
        int $gold,
        array $stats,
        array $abilities,
        array $items,
        array $equipment,
        int $ai_action,
        int $ai_goal,
        int $ai_target,
        int $ai_experience
    ) {
        $this->name = $name;
        $this->stats = $stats;
        $this->abilities = $abilities;
        $this->items = $items;
        $this->equipment = $equipment;
        $this->gold = $gold;
        $this->ai_action = $ai_action;
        $this->ai_goal = $ai_goal;
        $this->ai_target = $ai_target;
        $this->ai_experience = $ai_experience;
        $this->personalityid = $personalityid;

        $character = $this->make_monster();
        $this->pxp = $character->calculate_pxp();
    }

    public function make_monster(
        string $suffix = '',
        float $HPratio = 1.0,
        float $MPratio = 1.0
    ): CHARACTER {
        $character = new CHARACTER;
        $current = $this->stats;
        $current['HP'] = ceil($current['HP'] * $HPratio);
        $current['MP'] = ceil($current['MP'] * $MPratio);
        $character->make_monster($this->name . $suffix, $this->stats, $current, $this->abilities, $this->personalityid, $this->gold, $this->ai_action, $this->ai_goal, $this->ai_target, $this->ai_experience);
        foreach ($this->items as $item)
            $character->add_item($item['item'], $item['qty']);
        foreach ($this->equipment as $equipment)
            $character->equip_item($equipment['slot'], $equipment['side']);
        $character->gold = $this->gold;
        return $character;
    }

    public function describe_stats(): string {
        $response = array("<b>PXP {$this->pxp}</b>", "Gold {$this->gold}");
        foreach ($this->stats as $index => $stat)
            if ($stat != 0)
                $response[] = "$index $stat";
        return implode(' ', $response);
    }

    public static function get_monsters_in_range(int $target_pxp, float $spread = 0): array {
        $min_pxp = floor($target_pxp * (1.0 - ($spread * 2.0 / 3)));
        $max_pxp = floor($target_pxp * (1.0 + ($spread * 1.0 / 3)));
        return array_filter(MONSTER::load_all_from_cache(), function ($a) use ($min_pxp, $max_pxp) {
            return $a->state === ObjectState::ACTIVE && $a->pxp >= $min_pxp && $a->pxp <= $max_pxp;
        });
    }

    public static function pick_monsters(int $target_pxp, int $qty): array {
        $spread = .05;
        do {
            $monsters = static::get_monsters_in_range($target_pxp, $spread);
            $spread += .05;
        } while (count($monsters) < $qty + 2);
        $retval = array();
        $array = array_rand($monsters, min($qty, count($monsters)));
        if (!is_array($array))
            $array = array($array);
        foreach ($array as $key)
            $retval[] = $monsters[$key];
        return $retval;
    }

    // How healthy a new monster starts, as a share of its full HP / MP:
    // usually full, sometimes anything down to nothing. Practice fights
    // roll their AI heroes the same way (see PracticeFight).
    public static function random_hp_ratio(): float {
        return min(mt_rand(0, 250), 100) / 100.0;
    }

    public static function random_mp_ratio(): float {
        return min(mt_rand(0, 150), 100) / 100.0;
    }

    // A monster party to meet $heroes: its size is a random draw
    // weighted by the heroes' strength, its strength matched to theirs.
    public static function encounter_for(PARTY $heroes): PARTY {
        $pxp = $heroes->get_pxp();
        $seed = mt_rand(0, min(round($pxp / 2), 1000));
        $qty = round(18 + pow($seed - 500, 3) * 18 / (500 * 500 * 500));
        if ($qty < 1) {
            $qty = 1;
        }
        return static::create_monster_party($pxp, count($heroes->get_character_list(null)), $qty);
    }

    public static function create_monster_party(int $player_pxp, int $player_qty, int $monster_qty): PARTY {
        //Get xp per monster and group count
        if ($monster_qty > $player_qty) {
            $ratio = $monster_qty / $player_qty;
            $offset = 1 - ($ratio - 1) / (2 * $ratio);
            $pxp_per_monster = (int)($player_pxp / $player_qty * $offset);
        } else {
            $ratio = $player_qty / $monster_qty;
            $offset = 1 + ($ratio - 1) / (2 * $ratio);
            $pxp_per_monster = (int)($player_pxp / $player_qty * $offset);
        }
        $target_pxp = $pxp_per_monster * $monster_qty;
        $groups_from_qty = array(
            1 => array(1),
            2 => array(1),
            3 => array(1, 2),
            4 => array(1, 2),
            5 => array(1, 2),
            6 => array(1, 2),
            7 => array(2, 3),
            8 => array(2, 3),
            9 => array(2, 3),
            10 => array(2, 3, 4),
            11 => array(2, 3, 4),
            12 => array(2, 3, 4),
            13 => array(2, 3, 4),
            14 => array(2, 3, 4),
            15 => array(2, 3, 4),
            16 => array(3, 4),
            17 => array(3, 4),
            18 => array(3, 4),
            19 => array(3, 4),
            20 => array(3, 4),
            21 => array(3, 4),
            22 => array(3, 4),
            23 => array(3, 4),
            24 => array(3, 4),
            25 => array(3, 4),
            26 => array(3, 4),
            27 => array(3, 4)
        );
        if (array_key_exists((int)$monster_qty, $groups_from_qty))
            $options = $groups_from_qty[$monster_qty];
        else
            $options = array(4);
        $groups = $options[array_rand($options)];
        //$GLOBALS['output'].= "Starting groups: $groups<br>";

        //Get the monsters for this party.
        $monsters = static::pick_monsters($pxp_per_monster, $groups);
        $groups = count($monsters);
        //$GLOBALS['output'].= "Ending groups: $groups<br>";

        //Setup the monster party and HP ratio.
        $party = new PARTY;
        $party->monster = true;
        $ratios = array();
        $total_pxp = 0;
        for ($count = 0; $count < $groups; $count++) {
            $party->add(new GROUP);
            $party->groups[$count]->name = "{$monsters[$count]->name} group";
            $ratios[$count] = array(static::random_hp_ratio() * 100);
            $total_pxp += $monsters[$count]->pxp;
        }

        //Loop until we are within 80-110% of target pxp
        $odds = 1;
        while ($monster_qty > count($party->get_character_list(null))) {
            $odds = 0;
            // Add a monster type only if it will not put us over the cap.
            for ($count = 0; $count < $groups; $count++)
                if ($total_pxp + $monsters[$count]->pxp <= $target_pxp) {
                    $odd = 81 - (count($ratios[$count]) * count($ratios[$count]));
                    //$GLOBALS['output'].= "$odd:";
                    $odds += $odd;
                }
            if ($odds == 0)
                break;
            $chance = mt_rand(1, $odds);
            //$GLOBALS['output'].= "($chance)";
            for ($count = 0; $count < $groups; $count++)
                if ($total_pxp + $monsters[$count]->pxp <= $target_pxp) {
                    if ($chance <= (81 - count($ratios[$count]) * count($ratios[$count]))) {
                        $ratios[$count][] = static::random_hp_ratio() * 100;
                        $total_pxp += $monsters[$count]->pxp;
                        break;
                    } else {
                        $chance -= 81 - (count($ratios[$count]) * count($ratios[$count]));
                        //$GLOBALS['output'].= "-($count-$chance)";
                    }
                }
        }
        log_debug('monsters', 'Monster party generated', ['player_pxp' => $player_pxp, 'player_qty' => $player_qty,
            'monster_qty' => $monster_qty, 'offset' => $offset, 'pxp_per_monster' => $pxp_per_monster, 'target_pxp' => $target_pxp]);
        //Fill the party
        for ($count = 0; $count < $groups; $count++)
            foreach ($ratios[$count] as $index => $ratio)
                $party->groups[$count]->add_monster($monsters[$count]->make_monster((count($ratios[$count]) == 1 ? '' : '-' . chr(ord('A') + $index)), $ratio / 100.0, static::random_mp_ratio()));
        //Return the party
        return $party;
    }

    protected static function translate_and_build(array $data): MONSTER {
        $monsterid = $data['monsterid'];
        $state_value = $data['state'];
        unset($data['monsterid'], $data['pxp'], $data['state']);
        $data['stats'] = json_decode($data['stats'], true);
        $data['abilities'] = json_decode($data['abilities'], true);
        $data['items'] = json_decode($data['items'], true);
        $data['equipment'] = json_decode($data['equipment'], true);
        $monster =  new MONSTER(...$data);
        $monster->monsterid = $monsterid;
        $monster->state = ObjectState::from($state_value);
        return $monster;
    }

    public function translate_to_store(): array {
        $state = $this->state->value;

        return [
            $this->monsterid,
            $this->name,
            $this->personalityid,
            $this->pxp,
            $this->gold,
            json_encode($this->stats),
            json_encode($this->abilities),
            json_encode($this->items),
            json_encode($this->equipment),
            $this->ai_action,
            $this->ai_goal,
            $this->ai_target,
            $this->ai_experience,
            $state
        ];
    }

    private static function get_active_ability_ids(Database $db): array {
        static $ids = null;
        if ($ids === null) {
            $ids = array_keys(ABILITY::load_all_from_database($db));
        }
        return $ids;
    }

    private static function get_active_item_ids(Database $db): array {
        static $ids = null;
        if ($ids === null) {
            $ids = array_keys(ITEM::load_all_from_database($db));
        }
        return $ids;
    }

    private static function get_active_personality_ids(Database $db): array {
        static $ids = null;
        if ($ids === null) {
            $ids = array_keys(PERSONALITY::load_all_from_database($db));
        }
        return $ids;
    }

    public function get_inactive_resources(Database $db): array {
        $item_ids = array_column($this->items, 'item');
        return [
            'abilities' => array_diff($this->abilities, static::get_active_ability_ids($db)),
            'items' => array_diff(array_column($this->items, 'item'), static::get_active_item_ids($db)),
            'personality' => !in_array($this->personalityid, static::get_active_personality_ids($db)),
        ];
    }

    public function has_inactive_resources(Database $db): bool {
        $resources = $this->get_inactive_resources($db);
        return count($resources['abilities']) > 0 || count($resources['items']) > 0 || $resources['personality'];
    }

    public static function find_monsters_using_ability(Database $db, int $abilityid): array {
        return array_filter(static::load_all_from_database($db, true), fn($m) => in_array($abilityid, $m->abilities));
    }

    public static function find_monsters_using_item(Database $db, int $itemid): array {
        return array_filter(static::load_all_from_database($db, true), fn($m) => in_array($itemid, array_column($m->items, 'item')));
    }

    public static function find_monsters_using_personality(Database $db, int $personalityid): array {
        return array_filter(static::load_all_from_database($db, true), fn($m) => $m->personalityid === $personalityid);
    }
}
