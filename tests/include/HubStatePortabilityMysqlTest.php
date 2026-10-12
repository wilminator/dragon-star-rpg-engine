<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/HubStatePortabilityTests.php';
require_once INCLUDE_GAME . 'hub_state.php';

class HubStatePortabilityMysqlTest extends DatabaseTestCase {
    use HubStatePortabilityTests;
}
