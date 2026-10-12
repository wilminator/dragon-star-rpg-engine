<?php
require_once INCLUDE_GAME . 'effect.php';

// EffectSpec — new file, include/effect_spec.php
class EffectSpec implements JsonSerializable {
    public const TARGET_INDIVIDUAL = 0;
    public const TARGET_GROUP = -1;
    public const TARGET_PARTY = -2;
    public const TARGET_ALL_PARTIES = -3;
    public const TARGET_ALL_ENEMIES = -4;
    public const TARGET_ALL_ALLIES = -5;

    public Effect $effect;
    public int $targets;
    public int $base;
    public int $added;
    public int $attribute;

    public function __construct(Effect $effect, int $targets, int $base, int $added, int $attribute) {
        $this->effect = $effect;
        $this->targets = $targets;
        $this->base = $base;
        $this->added = $added;
        $this->attribute = $attribute;
    }

    public function describe_effect_specification(): string {
        return $this->describe_range() . " " . $this->describe_effect();
    }

    public function is_detrimental(): bool {
        return $this->effect->is_detrimental();
    }

    public function usable_in_field(): bool {
        return $this->effect->usable_in_field();
    }

    public function usable_in_combat(): bool {
        return $this->effect->usable_in_combat();
    }

    public function only_living_targets() {
        return $this->effect->only_living_targets();
    }

    public function jsonSerialize(): array {
        return ['effect' => $this->effect->value, 'targets' => $this->targets, 'base' => $this->base, 'added' => $this->added, 'attribute' => $this->attribute];
    }

    public static function from_array(?array $data): self {
        if ($data === null) {
            return new self(Effect::NONE, 0, 0, 0, 0);
        }
        return new self($data['effect'] ? Effect::from($data['effect']) : Effect::NONE, $data['targets'] ?? 0, $data['base'] ?? 0, $data['added'] ?? 0, $data['attribute'] ?? 0);
    }

    public static function from_json(?string $json): self {
        return self::from_array($json === null ? null : json_decode($json, true));
    }

    public function get_target_rating(): float {
        switch ($this->targets) {
            case static::TARGET_GROUP: //Group
                return 3;
            case static::TARGET_PARTY: //Party
                return 9;
            case static::TARGET_ALL_PARTIES: //All parties
            case static::TARGET_ALL_ENEMIES: //All enemies
            case static::TARGET_ALL_ALLIES: //All allies
                return 27;
            default:
                return ($this->targets < 3 ? $this->targets * .5 + 1 : 2.5);
        }
    }

    public function get_effect_rating() {
        switch ($this->effect) {
            case Effect::HEAL: //Heal
            case Effect::HURT: //Hurt
                return $this->base + $this->added / 2;
            case Effect::REVIVE: //Revive
            case Effect::SLAY: //Slay
                return $this->base * 2.5 + $this->added * 2.5;
                break;
            case Effect::INCREASE_STATS: //Inc Stat
            case Effect::DECREASE_STATS: //Dec Stat
                return ($this->base + $this->added / 2) * sqrt(count_ones($this->attribute) + 1);
            case Effect::STEAL_STATS: //Steal Stat
                return ($this->base + $this->added / 2) * sqrt(count_ones($this->attribute) + 1) * 2;
            case Effect::CAUSE_GOOD_STATUS: //Good Status Cause
            case Effect::REMOVE_GOOD_STATUS: //Good Status Remove
            case Effect::CAUSE_BAD_STATUS: //Bad Status Cause
            case Effect::REMOVE_BAD_STATUS: //Bad Status Remove
                return $this->base * $this->added;
        }
        return 0;
    }

    public function describe_effect() {
        $attributes = $GLOBALS['attributes'];
        switch ($this->effect) {
            case Effect::NONE: //Nothing
                return "Does nothing.";
            case Effect::HEAL: //Heal
                return "Heals between {$this->base} and " . ($this->base + $this->added) . " HP.";
            case Effect::HURT: //Hurt
                return "Causes " . strtolower($attributes[$this->attribute]) . " damage between {$this->base} and " . ($this->base + $this->added) . " HP.";
            case Effect::REVIVE: //Revive
                return "{$this->base}% chance to be revived to {$this->added}% HP. Restores 100% HP if alive.";
            case Effect::SLAY: //Slay
                return "{$this->base}% chance to instantly kill the target. Ineffective against bosses.";
            case Effect::INCREASE_STATS: //Increase Stats
                break;
            case Effect::DECREASE_STATS: //Decrease Stats
                break;
            case Effect::STEAL_STATS: //Steal Stats
                break;
            case Effect::CAUSE_GOOD_STATUS: //Cause Good Status
                break;
            case Effect::REMOVE_GOOD_STATUS: //Remove Good Status
                break;
            case Effect::CAUSE_BAD_STATUS: //Cause Bad Status
                break;
            case Effect::REMOVE_BAD_STATUS: //Remove Bad Status
                break;
            case Effect::RESTORE_MP: //Restore MP
                return "Restores between {$this->base} and " . ($this->base + $this->added) . " MP.";
        }
    }

    public function describe_range() {
        switch ($this->targets) {
            case static::TARGET_PARTY:
                return "Affects one party.";
            case static::TARGET_GROUP:
                return "Affects one group.";
            case static::TARGET_ALL_PARTIES:
                return "Affects all parties.";
            case static::TARGET_ALL_ENEMIES:
                return "Affects all enemies.";
            case static::TARGET_ALL_ALLIES:
                return "Affects all allies.";
            case static::TARGET_INDIVIDUAL:
                return "Affects one character.";
        }
        return "Affects one character plus {$this->targets} on either side, including dead characters.";
    }

    public function get_max_effect() {
        switch ($this->effect) {
            case Effect::NONE: //Nothing
                return 0;
            case Effect::HEAL: //Heal
                return $this->base + $this->added;
            case Effect::HURT: //Hurt
                return $this->base + $this->added;
            case Effect::REVIVE: //Revive
                return 0;
            case Effect::SLAY: //Slay
                return 0;
            case Effect::INCREASE_STATS: //Increase Stats
                return $this->base + $this->added;
            case Effect::DECREASE_STATS: //Decrease Stats
                return $this->base + $this->added;
            case Effect::STEAL_STATS: //Steal Stats
                return $this->base + $this->added;
            case Effect::CAUSE_GOOD_STATUS: //Cause Good Status
                return 0;
            case Effect::REMOVE_GOOD_STATUS: //Remove Good Status
                return 0;
            case Effect::CAUSE_BAD_STATUS: //Cause Bad Status
                return 0;
            case Effect::REMOVE_BAD_STATUS: //Remove Bad Status
                return 0;
            case Effect::RESTORE_MP: //Restore MP
                return $this->base + $this->added;
        }
    }
}
