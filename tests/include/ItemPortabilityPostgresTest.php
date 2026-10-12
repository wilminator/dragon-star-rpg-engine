<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/ItemPortabilityInstance.php';

class ItemPortabilityPostgresTest extends PostgresTestCase {
    use EntityPortabilityTests, ItemPortabilityInstance;
}
