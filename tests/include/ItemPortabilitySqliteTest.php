<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/ItemPortabilityInstance.php';

class ItemPortabilitySqliteTest extends SqliteTestCase {
    use EntityPortabilityTests, ItemPortabilityInstance;
}
