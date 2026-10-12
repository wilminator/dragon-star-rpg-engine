<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/FightSemaphorePortabilityTests.php';
require_once INCLUDE_GAME . 'fight_semaphore.php'; // not in bootstrap.php's require chain

class FightSemaphorePortabilitySqliteTest extends SqliteTestCase {
    use FightSemaphorePortabilityTests;
}
