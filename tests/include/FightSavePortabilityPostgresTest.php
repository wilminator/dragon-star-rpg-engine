<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/FightSavePortabilityTests.php';
require_once INCLUDE_GAME . 'fight_save.php'; // not in bootstrap.php's require chain

class FightSavePortabilityPostgresTest extends PostgresTestCase {
    use FightSavePortabilityTests;
}
