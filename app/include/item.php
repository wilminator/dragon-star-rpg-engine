<?php
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'animation_effect.php';
require_once INCLUDE_GAME . 'effect_spec.php';

class ITEM implements DbStoreInterface, FileCacheInterface {
    use DbStoreTrait {
        load_all_from_database as protected trait_load_all_from_database;
    }
    use FileCacheTrait {
        load_all_from_cache as protected trait_load_all_from_cache;
    }

    protected static string $table_name = 'items';
    protected static string $table_key = 'itemid';
    protected static string $cache_path = CACHE_DIR . 'items.php';
    protected static bool $cache_includes_inactive = true;

    protected static string $drop_sql = "drop table if exists items";
    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('itemid');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS items (
  $id_column,
  name varchar(64) NOT NULL default '',
  price int NOT NULL default 0,
  use_effect_spec JSON NOT NULL default ('{"effect":0,"targets":0,"base":0,"added":0,"attribute":0}'),
  one_use int NOT NULL default 1,
  equip_type JSON NULL default NULL,
  stat_increase JSON NOT NULL default ('{"HP":0,"MP":0,"Speed":0,"Accuracy":0,"Strength":0,"Dodge":0,"Block":0,"Power":0,"Resistance":0,"Focus":0}'),
  stat_percent_increase JSON NOT NULL default ('{"HP":0,"MP":0,"Speed":0,"Accuracy":0,"Strength":0,"Dodge":0,"Block":0,"Power":0,"Resistance":0,"Focus":0}'),
  attack_targets int NOT NULL default 0,
  attack_count int NOT NULL default 0,
  attack_attribute int NOT NULL default 0,
  ammo_type varchar(32) NOT NULL default '',
  description varchar(255) NOT NULL default '',
  menu_pic varchar(64) NOT NULL default 'bignone.png',
  fight_effect_type varchar(16) NOT NULL default 'none',
  fight_animation_effect JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  use_animation_effect JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  state int NOT NULL default 0
);
QUERY;
    }
    // Order must match translate_to_store()'s return order exactly.
    protected static array $save_columns = [
        'itemid', 'name', 'price',
        'use_effect_spec', 'one_use',
        'equip_type', 'stat_increase', 'stat_percent_increase',
        'attack_targets', 'attack_count', 'attack_attribute',
        'ammo_type', 'description', 'menu_pic',
        'fight_effect_type', 'fight_animation_effect', 'use_animation_effect', 'state',
    ];

    public ?int $itemid = null;
    public ObjectState $state;
    public string $name;
    public int $price;
    public EffectSpec $use_effect_spec;
    public bool $one_use;
    public ?array $equip_type;
    public array $stat_increase;
    public array $stat_percent_increase;
    public int $attack_targets;
    public int $attack_count;
    public int $attack_attribute;
    public string $ammo_type;
    public string $description;
    public string $menu_pic;
    public string $fight_effect_type;
    public AnimationEffect $fight_animation_effect;
    public AnimationEffect $use_animation_effect;

    function __construct(
        string $name,
        int $price,
        EffectSpec $use_effect_spec,
        bool $one_use,
        ?array $equip_type,
        array $stat_increase,
        array $stat_percent_increase,
        int $attack_targets,
        int $attack_count,
        int $attack_attribute,
        string $ammo_type,
        string $description,
        string $menu_pic,
        string $fight_effect_type,
        AnimationEffect $fight_animation_effect,
        AnimationEffect $use_animation_effect
    ) {
        $this->name = $name;
        $this->price = $price;
        $this->use_effect_spec = $use_effect_spec;
        $this->one_use = $one_use;
        $this->equip_type = $equip_type;
        $this->stat_increase = $stat_increase;
        $this->stat_percent_increase = $stat_percent_increase;
        $this->attack_targets = $attack_targets;
        $this->attack_count = $attack_count;
        $this->attack_attribute = $attack_attribute;
        $this->ammo_type = $ammo_type;
        $this->description = $description;
        $this->menu_pic = $menu_pic;
        $this->fight_effect_type = $fight_effect_type;
        $this->fight_animation_effect = $fight_animation_effect;
        $this->use_animation_effect = $use_animation_effect;
    }

    function describe_use() {
        $response = $this->use_effect_spec->describe_effect_specification();
        if ($this->one_use)
            $response .= ' Disappears when used as an item.';
        else
            $response .= ' Never disappears when used as an item.';
        return $response;
    }

    function describe_equip() {
        if (is_null($this->equip_type))
            return "Not equippable.";
        $response = '';
        if (in_array('lhand', $this->equip_type) && in_array('rhand', $this->equip_type) && in_array('rammo', $this->equip_type))
            $response = "Two handed expendable weapon.";
        if (in_array('lhand', $this->equip_type) && in_array('rhand', $this->equip_type) && $this->ammo_type != '')
            $response = "Two handed weapon needing '{$this->ammo_type}' ammunition.";
        if (in_array('lhand', $this->equip_type) && in_array('rhand', $this->equip_type))
            $response = "Two handed weapon.";
        elseif (in_array('hand', $this->equip_type) && in_array('ammo', $this->equip_type))
            $response = "Single handed expendable weapon.";
        elseif (in_array('hand', $this->equip_type) && $this->ammo_type != '')
            $response = "Single handed weapon needing '{$this->ammo_type}' ammunition.";
        elseif (in_array('hand', $this->equip_type))
            $response = "Single handed weapon.";
        elseif (in_array('arm', $this->equip_type))
            $response = "Protective item that is worn on the arm.";
        elseif (in_array('body', $this->equip_type))
            $response = "Body armor.";
        elseif (in_array('head', $this->equip_type))
            $response = "Head gear.";
        elseif (in_array('back', $this->equip_type))
            $response = "Accessory that covers the back.";
        elseif (in_array('feet', $this->equip_type))
            $response = "Footwear.";
        elseif (in_array('ammo', $this->equip_type))
            $response = "Ammunition for weapons needing '{$this->ammo_type}' type ammunition.";
        if ($response != '')
            $response .= " " . $this->describe_stats();
        else
            $response .= "Not equippable.";
        return $response;
    }

    function describe_stats() {
        $attributes = $GLOBALS['attributes'];

        $response = array();
        foreach ($this->stat_increase as $index => $inc) {
            $percinc = $this->stat_percent_increase[$index];
            if ($inc != 0 || $percinc != 0) {
                $output = $index;
                if ($percinc != 0) {
                    if ($percinc > 0) $percinc = '+' . $percinc;
                    $output .= " $percinc%";
                }
                if ($inc != 0) {
                    if ($inc > 0) $inc = '+' . $inc;
                    $output .= " $inc";
                }
                $response[] = $output;
            }
        }
        $atk_cnt = ($this->attack_count > 1 ? "Attacks {$this->attack_count} times per turn. " : '');
        return "{$attributes[$this->attack_attribute]} based. " . $atk_cnt . implode(', ', $response);
    }

    function is_detrimental() {
        return $this->use_effect_spec->is_detrimental();
    }

    function usable_in_field() {
        return $this->use_effect_spec->usable_in_field();
    }

    function usable_in_combat() {
        return $this->use_effect_spec->usable_in_combat();
    }

    function get_max_effect() {
        return $this->use_effect_spec->get_max_effect();
    }

    function get_weapon_effect_spec(int $strength): EffectSpec {
        return new EffectSpec(
            effect: Effect::HURT,
            targets: $this->attack_targets,
            base: floor(0.5 * $strength),
            added: ceil(0.5 * $strength),
            attribute: $this->attack_attribute
        );
    }

    protected static function translate_and_build(array $data): ITEM {
        $itemid = $data['itemid'];
        $state_value = $data['state'];
        unset($data['itemid'], $data['state']);
        $data['stat_increase'] = is_null($data['stat_increase']) ? ["HP" => 0, "MP" => 0, "Speed" => 0, "Accuracy" => 0, "Strength" => 0, "Dodge" => 0, "Block" => 0, "Power" => 0, "Resistance" => 0, "Focus" => 0] : json_decode($data['stat_increase'], true);
        $data['stat_percent_increase'] = is_null($data['stat_percent_increase']) ? ["HP" => 0.0, "MP" => 0.0, "Speed" => 0.0, "Accuracy" => 0.0, "Strength" => 0.0, "Dodge" => 0.0, "Block" => 0.0, "Power" => 0.0, "Resistance" => 0.0, "Focus" => 0.0] : json_decode($data['stat_percent_increase'], true);
        $data['equip_type'] = is_null($data['equip_type']) ? null : json_decode($data['equip_type'], true);
        $data['use_effect_spec'] = EffectSpec::from_json($data['use_effect_spec']);
        $data['fight_animation_effect'] = AnimationEffect::from_json($data['fight_animation_effect']);
        $data['use_animation_effect'] = AnimationEffect::from_json($data['use_animation_effect']);
        $item =  new ITEM(...$data);
        $item->itemid = $itemid;
        $item->state = ObjectState::from($state_value);
        return $item;
    }

    public function translate_to_store(): array {
        $fight_animation_effect = json_encode($this->fight_animation_effect);
        $use_animation_effect = json_encode($this->use_animation_effect);
        $use_effect_spec = json_encode($this->use_effect_spec);
        $stat_increase = json_encode($this->stat_increase);
        $stat_percent_increase = json_encode($this->stat_percent_increase);
        $equip_type = json_encode($this->equip_type);

        $state = $this->state->value;
        return [
            $this->itemid,
            $this->name,
            $this->price,
            $use_effect_spec,
            (int)$this->one_use,
            $equip_type,
            $stat_increase,
            $stat_percent_increase,
            $this->attack_targets,
            $this->attack_count,
            $this->attack_attribute,
            $this->ammo_type,
            $this->description,
            $this->menu_pic,
            $this->fight_effect_type,
            $fight_animation_effect,
            $use_animation_effect,
            $state
        ];
    }

    // TEMPORARY: flattens impact AnimationEffect back to the old two-key wire
    // shape (fight_impact_animation + fight_impact_data) that audio.js,
    // image_cache.js, process.js, and functions.php still expect.
    // Delete this override once those are updated to read the nested shape
    // AnimationEffect actually produces via json_encode().
    // Also mashes EffectSpec into the previous individual properties.
    public function to_client_js_structure(): array {
        $data = get_object_vars($this);
        unset(
            $data['fight_animation_effect'],
            $data['use_animation_effect'],
            $data['use_effect_spec'],
            $data['attack_targets'],
            $data['stat_increase'],
            $data['stat_percent_increase']
        );
        list($data['fight_impact_animation'], $data['fight_impact_data']) = $this->fight_animation_effect->to_client_js();
        list($data['use_impact_animation'], $data['use_impact_data']) = $this->use_animation_effect->to_client_js();
        $effect_data = get_object_vars($this->use_effect_spec);
        $effect_data['use_targets'] = $effect_data['targets'];
        $effect_data['targets'] = $this->attack_targets;
        $effect_data['statinc'] = $this->stat_increase;
        $effect_data['statpercinc'] = $this->stat_percent_increase;
        return array_merge($data, $effect_data);
    }

    private static function nothing_item(): ITEM {
        $item = new ITEM(
            name: "Nothing",
            price: 0,
            use_effect_spec: new EffectSpec(Effect::NONE, -2, 0, 0, 0),
            one_use: false,
            equip_type: [],
            stat_increase: ["HP" => 0, "MP" => 0, "Speed" => 0, "Accuracy" => 0, "Strength" => 0, "Dodge" => 0, "Block" => 0, "Power" => 0, "Resistance" => 0, "Focus" => 0],
            stat_percent_increase: ["HP" => 0, "MP" => 0, "Speed" => 0, "Accuracy" => 0, "Strength" => 0, "Dodge" => 0, "Block" => 0, "Power" => 0, "Resistance" => 0, "Focus" => 0],
            attack_targets: 0,
            attack_count: 1,
            attack_attribute: 0,
            ammo_type: '',
            description: "",
            menu_pic: 'bignone.png',
            fight_effect_type: 'close',
            fight_animation_effect: new AnimationEffect('static_individual_impact', ['punch.gif'], [''], [100, 300]),
            use_animation_effect: new AnimationEffect('static_individual_impact', [''], [''], [100, 300])
        );
        $item->itemid = 0;
        $item->state = ObjectState::ACTIVE;
        return $item;
    }

    public static function load_all_from_cache(): array {
        static::trait_load_all_from_cache();
        if (!array_key_exists(0, static::$cache)) {
            static::$cache[0] = static::nothing_item();
        }
        return array_slice(static::$cache, 0, preserve_keys: true);
    }

    public static function load_all_from_database(Database $db, bool $include_inactive = false): array {
        $items = static::trait_load_all_from_database($db, $include_inactive);
        $items[0] = static::nothing_item();
        return $items;
    }
}
