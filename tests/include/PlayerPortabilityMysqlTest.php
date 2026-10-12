<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/PlayerPortabilityTests.php';
require_once INCLUDE_GAME . 'player.php'; // not in bootstrap.php's require chain

class PlayerPortabilityMysqlTest extends DatabaseTestCase {
    use PlayerPortabilityTests;
}
