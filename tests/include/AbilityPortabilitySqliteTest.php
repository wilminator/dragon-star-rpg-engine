<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/AbilityPortabilityInstance.php';

class AbilityPortabilitySqliteTest extends SqliteTestCase {
    use EntityPortabilityTests, AbilityPortabilityInstance;
}
