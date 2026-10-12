<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/MonsterPortabilityInstance.php';

class MonsterPortabilityMysqlTest extends DatabaseTestCase {
    use EntityPortabilityTests, MonsterPortabilityInstance;
}
