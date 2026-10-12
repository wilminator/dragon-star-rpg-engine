<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/MonsterSidePortabilityTests.php';
require_once INCLUDE_GAME . 'monster_side.php';
require_once INCLUDE_GAME . 'anomaly.php';

class MonsterSidePortabilityPostgresTest extends PostgresTestCase {
    use MonsterSidePortabilityTests;
}
