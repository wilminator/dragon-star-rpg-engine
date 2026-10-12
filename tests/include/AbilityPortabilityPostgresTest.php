<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/AbilityPortabilityInstance.php';

class AbilityPortabilityPostgresTest extends PostgresTestCase {
    use EntityPortabilityTests, AbilityPortabilityInstance;
}
