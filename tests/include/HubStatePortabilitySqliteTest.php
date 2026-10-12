<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/HubStatePortabilityTests.php';
require_once INCLUDE_GAME . 'hub_state.php';

class HubStatePortabilitySqliteTest extends SqliteTestCase {
    use HubStatePortabilityTests;
}
