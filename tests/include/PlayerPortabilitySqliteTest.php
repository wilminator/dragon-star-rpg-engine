<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/PlayerPortabilityTests.php';
require_once INCLUDE_GAME . 'player.php'; // not in bootstrap.php's require chain

class PlayerPortabilitySqliteTest extends SqliteTestCase {
    use PlayerPortabilityTests;
}
