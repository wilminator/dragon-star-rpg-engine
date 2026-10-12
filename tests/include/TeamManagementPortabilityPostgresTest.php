<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/TeamManagementPortabilityTests.php';
require_once INCLUDE_GAME . 'team_management.php';

class TeamManagementPortabilityPostgresTest extends PostgresTestCase {
    use TeamManagementPortabilityTests;
}
