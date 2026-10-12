<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/FightSemaphorePortabilityTests.php';
require_once INCLUDE_GAME . 'fight_semaphore.php'; // not in bootstrap.php's require chain

class FightSemaphorePortabilityMysqlTest extends DatabaseTestCase {
    use FightSemaphorePortabilityTests;
}
