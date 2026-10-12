<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/ItemPortabilityInstance.php';

class ItemPortabilityMysqlTest extends DatabaseTestCase {
    use EntityPortabilityTests, ItemPortabilityInstance;
}
