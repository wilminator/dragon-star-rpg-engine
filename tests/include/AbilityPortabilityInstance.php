<?php

require_once INCLUDE_GAME . 'ability.php';

trait AbilityPortabilityInstance {
    protected function entity_class(): string {
        return ABILITY::class;
    }

    protected function make_instance(): object {
        $ability = new ABILITY(
            name: 'Test Ability',
            type: ABILITY::TYPE_SPELL,
            effect_spec: new EffectSpec(Effect::HURT, 0, 10, 5, 0),
            mp_used: 5,
            description: 'A test ability.',
            menu_pic: 'bignone.png',
            skill_effect_type: 'none',
            impact: new AnimationEffect(),
        );
        $ability->state = ObjectState::ACTIVE;
        return $ability;
    }
}
