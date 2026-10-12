<?php

require_once INCLUDE_GAME . 'personality.php';

trait PersonalityPortabilityInstance {
    protected function entity_class(): string {
        return PERSONALITY::class;
    }

    protected function make_instance(): object {
        $personality = new PERSONALITY(
            name: 'Test Personality',
            base: new AnimationEffect(),
            equip: new AnimationEffect(),
            flee: new AnimationEffect(),
            hit: new AnimationEffect(),
            die: new AnimationEffect(),
            attack_close: new AnimationEffect(),
            attack_throw: new AnimationEffect(),
            attack_shoot: new AnimationEffect(),
            skill: new AnimationEffect(),
            spell: new AnimationEffect(),
            item: new AnimationEffect(),
            overworld: PERSONALITY::empty_overworld_data(),
        );
        $personality->state = ObjectState::ACTIVE;
        return $personality;
    }
}
