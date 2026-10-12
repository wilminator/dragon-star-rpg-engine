<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/MonsterPortabilityInstance.php';

class MonsterPortabilityPostgresTest extends PostgresTestCase {
    use EntityPortabilityTests, MonsterPortabilityInstance;
}
