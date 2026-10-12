<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/AbilityPortabilityInstance.php';

class AbilityPortabilityMysqlTest extends DatabaseTestCase {
    use EntityPortabilityTests, AbilityPortabilityInstance;
}
