<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/FightSavePortabilityTests.php';
require_once INCLUDE_GAME . 'fight_save.php'; // not in bootstrap.php's require chain

class FightSavePortabilityMysqlTest extends DatabaseTestCase {
    use FightSavePortabilityTests;
}
