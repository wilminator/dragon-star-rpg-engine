<?php
require_once INCLUDE_GAME . 'images.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'animation_effect.php';
require_once INCLUDE_GAME . 'effect_spec.php';
require_once INCLUDE_GAME . 'sprite_animation.php';

class PERSONALITY implements DbStoreInterface, FileCacheInterface {
    use DbStoreTrait;
    use FileCacheTrait;

    protected static string $table_name = 'personalities';
    protected static string $table_key = 'personalityid';
    protected static string $cache_path = CACHE_DIR . 'personalities.php';
    protected static bool $cache_includes_inactive = true;

    protected static string $drop_sql = "drop table if exists personalities";
    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('personalityid');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS personalities (
  $id_column,
  name varchar(64) NOT NULL default '',
  base JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  equip JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  flee JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  hit JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  die JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  attack_close JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  attack_throw JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  attack_shoot JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  skill JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  spell JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  item JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  overworld JSON NOT NULL default ('{"stand":{"up":{"images":[],"times":[]},"down":{"images":[],"times":[]},"left":{"images":[],"times":[]},"right":{"images":[],"times":[]}},"move":{"up":{"images":[],"times":[]},"down":{"images":[],"times":[]},"left":{"images":[],"times":[]},"right":{"images":[],"times":[]}}}'),
  state int NOT NULL default 0
);
QUERY;
    }
    // Order must match translate_to_store()'s return order exactly.
    protected static array $save_columns = [
        'personalityid', 'name',
        'base', 'equip',
        'flee', 'hit', 'die',
        'attack_close', 'attack_throw', 'attack_shoot',
        'skill', 'spell', 'item',
        'overworld', 'state',
    ];
    public const array overworld_animation_types = ['stand', 'move'];
    public const array overworld_animation_directions = ['up', 'down', 'left', 'right'];
    public const array animation_names = ['base', 'equip', 'flee', 'hit', 'die', 'attack_close', 'attack_throw', 'attack_shoot', 'skill', 'spell', 'item'];

    public ?int $personalityid = null;
    public ObjectState $state;
    public string $name;
    public AnimationEffect $base;
    public AnimationEffect $equip;
    public AnimationEffect $flee;
    public AnimationEffect $hit;
    public AnimationEffect $die;
    public AnimationEffect $attack_close;
    public AnimationEffect $attack_throw;
    public AnimationEffect $attack_shoot;
    public AnimationEffect $skill;
    public AnimationEffect $spell;
    public AnimationEffect $item;
    public array $overworld;

    function __construct(
        string $name,
        AnimationEffect $base,
        AnimationEffect $equip,
        AnimationEffect $flee,
        AnimationEffect $hit,
        AnimationEffect $die,
        AnimationEffect $attack_close,
        AnimationEffect $attack_throw,
        AnimationEffect $attack_shoot,
        AnimationEffect $skill,
        AnimationEffect $spell,
        AnimationEffect $item,
        array $overworld
    ) {
        $this->name = $name;
        $this->base = $base;
        $this->equip = $equip;
        $this->flee = $flee;
        $this->hit = $hit;
        $this->die = $die;
        $this->attack_close = $attack_close;
        $this->attack_throw = $attack_throw;
        $this->attack_shoot = $attack_shoot;
        $this->skill = $skill;
        $this->spell = $spell;
        $this->item = $item;
        $this->overworld = $overworld;
        $this->validate_overworld_data();
    }

    private function validate_overworld_data(): void {
        if (array_any($this->overworld, fn($x) => !is_array($x))) {
            throw new Exception("Attempted to create Personality without nested array overworld animations.");
        }
        if (array_any($this->overworld, fn($x, $k) => !in_array($k, static::overworld_animation_types))) {
            throw new Exception("Attempted to create Personality with bad top-level keys.");
        }
        foreach ($this->overworld as $key => $array) {
            if (array_any($array, fn($x, $k) => !in_array($k, static::overworld_animation_directions))) {
                throw new Exception("Attempted to create Personality without bad direction keys for {$key}.");
            }
        }
    }

    function push_data() {
        foreach ($this->base->images as $image) push_image($image);
        foreach ($this->equip->images as $image) push_image($image);
        foreach ($this->flee->images as $image) push_image($image);
        foreach ($this->hit->images as $image) push_image($image);
        foreach ($this->die->images as $image) push_image($image);
        foreach ($this->attack_close->images as $image) push_image($image);
        foreach ($this->attack_throw->images as $image) push_image($image);
        foreach ($this->attack_shoot->images as $image) push_image($image);
        foreach ($this->skill->images as $image) push_image($image);
        foreach ($this->spell->images as $image) push_image($image);
        foreach ($this->item->images as $image) push_image($image);
    }

    protected static function translate_and_build(array $data): PERSONALITY {
        $personalityid = $data['personalityid'];
        $state_value = $data['state'];
        unset($data['personalityid'], $data['state']);
        foreach (static::animation_names as $key) {
            $data[$key] = AnimationEffect::from_json($data[$key]);
        }
        $overworld = json_decode($data['overworld'], true);
        foreach (static::overworld_animation_types as $type) {
            foreach (static::overworld_animation_directions as $direction) {
                $overworld[$type][$direction] = SpriteAnimation::from_array($overworld[$type][$direction]);
            }
        }
        $data['overworld'] = $overworld;
        $personality =  new PERSONALITY(...$data);
        $personality->personalityid = $personalityid;
        $personality->state = ObjectState::from($state_value);
        return $personality;
    }

    public function translate_to_store(): array {
        $data = get_object_vars($this);
        foreach (static::animation_names as $key) {
            $data[$key] = json_encode($data[$key]);
        }
        $data['overworld'] = json_encode($data['overworld']);
        $data['state'] = $this->state->value;
        return [
            $this->personalityid,
            $this->name,
            $data['base'],
            $data['equip'],
            $data['flee'],
            $data['hit'],
            $data['die'],
            $data['attack_close'],
            $data['attack_throw'],
            $data['attack_shoot'],
            $data['skill'],
            $data['spell'],
            $data['item'],
            $data['overworld'],
            $this->state->value
        ];
    }

    public static function empty_overworld_data(): array {
        $overworld = [];
        foreach (static::overworld_animation_types as $type) {
            foreach (static::overworld_animation_directions as $direction) {
                $overworld[$type][$direction] = new SpriteAnimation();
            }
        }
        return $overworld;
    }

    // TEMPORARY: flattens impact AnimationEffect back to the old two-key wire
    // shape (fight_impact_animation + fight_impact_data) that audio.js,
    // image_cache.js, process.js, and functions.php still expect.
    // Delete this override once those are updated to read the nested shape
    // AnimationEffect actually produces via json_encode().
    // Also mashes EffectSpec into the previous individual properties.
    public function to_client_js_structure(): array {
        $data = get_object_vars($this);
        foreach (static::animation_names as $key) {
            list($data["{$key}_animation"], $data["{$key}_data"]) = $this->$key->to_client_js();
            unset($key);
        }
        return $data;
    }
}
