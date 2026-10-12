<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/GameLogPortabilityTests.php';
require_once INCLUDE_GAME . 'game_log.php';

class GameLogPortabilityMysqlTest extends DatabaseTestCase {
    use GameLogPortabilityTests;
}
