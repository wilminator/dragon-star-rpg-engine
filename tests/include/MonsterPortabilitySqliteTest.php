<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/MonsterPortabilityInstance.php';

class MonsterPortabilitySqliteTest extends SqliteTestCase {
    use EntityPortabilityTests, MonsterPortabilityInstance;
}
