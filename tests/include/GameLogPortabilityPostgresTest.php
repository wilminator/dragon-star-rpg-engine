<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/GameLogPortabilityTests.php';
require_once INCLUDE_GAME . 'game_log.php';

class GameLogPortabilityPostgresTest extends PostgresTestCase {
    use GameLogPortabilityTests;
}
