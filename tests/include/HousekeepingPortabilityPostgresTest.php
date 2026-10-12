<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/HousekeepingPortabilityTests.php';
require_once INCLUDE_GAME . 'housekeeping.php';

class HousekeepingPortabilityPostgresTest extends PostgresTestCase {
    use HousekeepingPortabilityTests;
}
