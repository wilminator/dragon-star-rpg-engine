<?php
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'animation_effect.php';
require_once INCLUDE_GAME . 'effect_spec.php';

class ABILITY implements DbStoreInterface, FileCacheInterface  {
    use DbStoreTrait;
    use FileCacheTrait;

    public const int TYPE_SPELL = 0;
    public const int TYPE_SKILL = 1;

    protected static string $table_name = 'abilities';
    protected static string $table_key = 'abilityid';
    protected static string $cache_path = CACHE_DIR . 'abilities.php';
    protected static bool $cache_includes_inactive = true;

    protected static string $drop_sql = "drop table if exists abilities";
    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('abilityid');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS abilities (
  $id_column,
  name varchar(64) NOT NULL default '',
  type int NOT NULL default 0,
  effect_spec JSON NOT NULL default ('{"effect":0,"targets":0,"base":0,"added":0,"attribute":0}'),
  mp_used int NOT NULL default 0,
  description varchar(255) NOT NULL default '',
  menu_pic varchar(64) NOT NULL default 'bignone.png',
  skill_effect_type varchar(16) NOT NULL default 'none',
  impact JSON NOT NULL default ('{"animation":"static_individual_impact","images":[],"sounds":[],"times":[100,100]}'),
  state int NOT NULL default 0
);
QUERY;
    }
    // Order must match translate_to_store()'s return order exactly.
    protected static array $save_columns = [
        'abilityid', 'name', 'type',
        'effect_spec', 'mp_used', 'description', 'menu_pic',
        'skill_effect_type', 'impact', 'state',
    ];

    public ?int $abilityid = null;
    public ObjectState $state;
    public string $name;
    public EffectSpec $effect_spec;
    public int $type;
    public int $mp_used;
    public string $description;
    public string $menu_pic;
    public string $skill_effect_type;
    public AnimationEffect $impact;

    public function __construct(
        string $name,
        int $type,
        EffectSpec $effect_spec,
        int $mp_used,
        string $description,
        string $menu_pic,
        string $skill_effect_type,
        AnimationEffect $impact
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->effect_spec = $effect_spec;
        $this->mp_used = $mp_used;
        $this->description = $description;
        $this->menu_pic = $menu_pic;
        $this->skill_effect_type = $skill_effect_type;
        $this->impact = $impact;
    }

    public function describe_use(): string {
        return ($this->type == static::TYPE_SPELL ? 'Spell. ' : 'Skill. ') . $this->effect_spec->describe_effect_specification();
    }

    public function is_detrimental(): bool {
        return $this->effect_spec->is_detrimental();
    }

    public function usable_in_field(): bool {
        return $this->effect_spec->usable_in_field();
    }

    public function usable_in_combat(): bool {
        return $this->effect_spec->usable_in_combat();
    }

    protected static function translate_and_build(array $data): ABILITY {
            $abilityid = $data['abilityid'];
            $state_value = $data['state'];            
            unset($data['abilityid'], $data['state'], $data['effect'], $data['targets'], $data['base'], $data['added'], $data['attribute']);
            $data['impact'] = AnimationEffect::from_json($data['impact']);
            $data['effect_spec'] = EffectSpec::from_json($data['effect_spec']);
            $ability =  new ABILITY(...$data);
            $ability->abilityid = $abilityid;
            $ability->state = ObjectState::from($state_value);
            return $ability;
    }

    public function translate_to_store(): array {
        $impact = json_encode($this->impact);
        $effect_spec = json_encode($this->effect_spec);
        $state = $this->state->value;
        return [
            $this->abilityid,
            $this->name,
            $this->type,
            $effect_spec,
            $this->mp_used,
            $this->description,
            $this->menu_pic,
            $this->skill_effect_type,
            $impact,
            $state
        ];
    }

    // TEMPORARY: flattens impact AnimationEffect back to the old two-key wire
    // shape (fight_impact_animation + fight_impact_data) that audio.js,
    // image_cache.js, process.js, and functions.php still expect.
    // Delete this override once those are updated to read the nested shape
    // AnimationEffect actually produces via json_encode().
    // Also mashes EffectSpec into the previous individual properties.
    public function to_client_js_structure() : array {
        $data = get_object_vars($this);
        unset($data['impact'],$data['effect_spec']);
        list($data['impact_animation'], $data['impact_data']) = $this->impact->to_client_js();
        return array_merge($data, get_object_vars($this->effect_spec));
    }
}
