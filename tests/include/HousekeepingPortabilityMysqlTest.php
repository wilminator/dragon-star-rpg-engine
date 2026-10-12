<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/HousekeepingPortabilityTests.php';
require_once INCLUDE_GAME . 'housekeeping.php';

class HousekeepingPortabilityMysqlTest extends DatabaseTestCase {
    use HousekeepingPortabilityTests;
}
