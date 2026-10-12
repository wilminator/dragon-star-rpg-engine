<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/TeamManagementPortabilityTests.php';
require_once INCLUDE_GAME . 'team_management.php';

class TeamManagementPortabilityMysqlTest extends DatabaseTestCase {
    use TeamManagementPortabilityTests;
}
