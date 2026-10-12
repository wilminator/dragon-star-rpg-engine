<?php

require_once INCLUDE_GAME . 'monster.php';

trait MonsterPortabilityInstance {
    protected function entity_class(): string {
        return MONSTER::class;
    }

    protected function make_instance(): object {
        $monster = new MONSTER(
            name: 'Test Monster',
            personalityid: 0,
            gold: 10,
            stats: ["HP" => 50, "MP" => 10, "Speed" => 5, "Accuracy" => 5, "Strength" => 5, "Dodge" => 5, "Block" => 5, "Power" => 5, "Resistance" => 5, "Focus" => 5],
            abilities: [],
            items: [],
            equipment: [],
            ai_action: 0,
            ai_goal: 0,
            ai_target: 0,
            ai_experience: 0,
        );
        $monster->state = ObjectState::ACTIVE;
        return $monster;
    }
}
