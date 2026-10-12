<?php

require_once INCLUDE_GAME . 'item.php';

trait ItemPortabilityInstance {
    protected function entity_class(): string {
        return ITEM::class;
    }

    protected function make_instance(): object {
        $item = new ITEM(
            name: 'Test Item',
            price: 100,
            use_effect_spec: new EffectSpec(Effect::HEAL, 0, 10, 5, 0),
            one_use: true,
            equip_type: null,
            stat_increase: ["HP" => 0, "MP" => 0, "Speed" => 0, "Accuracy" => 0, "Strength" => 0, "Dodge" => 0, "Block" => 0, "Power" => 0, "Resistance" => 0, "Focus" => 0],
            stat_percent_increase: ["HP" => 0, "MP" => 0, "Speed" => 0, "Accuracy" => 0, "Strength" => 0, "Dodge" => 0, "Block" => 0, "Power" => 0, "Resistance" => 0, "Focus" => 0],
            attack_targets: 0,
            attack_count: 1,
            attack_attribute: 0,
            ammo_type: '',
            description: 'A test item.',
            menu_pic: 'bignone.png',
            fight_effect_type: 'none',
            fight_animation_effect: new AnimationEffect(),
            use_animation_effect: new AnimationEffect(),
        );
        $item->state = ObjectState::ACTIVE;
        return $item;
    }
}
