<?php

require_once INCLUDE_GAME . 'job.php';

trait JobPortabilityInstance {
    protected function entity_class(): string {
        return JOB::class;
    }

    protected function make_instance(): object {
        $job = new JOB(
            name: 'Test Job',
            need: 100,
            upgrade: ["HP" => 1.0, "MP" => 1.0, "Speed" => 1.0, "Accuracy" => 1.0, "Strength" => 1.0, "Dodge" => 1.0, "Block" => 1.0, "Power" => 1.0, "Resistance" => 1.0, "Focus" => 1.0],
            abilities: [],
        );
        $job->state = ObjectState::ACTIVE;
        return $job;
    }
}
