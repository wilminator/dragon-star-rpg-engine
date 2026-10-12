<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/TeamManagementPortabilityTests.php';
require_once INCLUDE_GAME . 'team_management.php';

class TeamManagementPortabilitySqliteTest extends SqliteTestCase {
    use TeamManagementPortabilityTests;
}
