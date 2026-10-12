<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/HousekeepingPortabilityTests.php';
require_once INCLUDE_GAME . 'housekeeping.php';

class HousekeepingPortabilitySqliteTest extends SqliteTestCase {
    use HousekeepingPortabilityTests;
}
