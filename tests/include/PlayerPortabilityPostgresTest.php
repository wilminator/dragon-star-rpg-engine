<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/PlayerPortabilityTests.php';
require_once INCLUDE_GAME . 'player.php'; // not in bootstrap.php's require chain

class PlayerPortabilityPostgresTest extends PostgresTestCase {
    use PlayerPortabilityTests;
}
