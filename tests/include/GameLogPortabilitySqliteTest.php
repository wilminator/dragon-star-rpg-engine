<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/GameLogPortabilityTests.php';
require_once INCLUDE_GAME . 'game_log.php';

class GameLogPortabilitySqliteTest extends SqliteTestCase {
    use GameLogPortabilityTests;
}
