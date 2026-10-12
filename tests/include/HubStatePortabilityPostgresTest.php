<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/HubStatePortabilityTests.php';
require_once INCLUDE_GAME . 'hub_state.php';

class HubStatePortabilityPostgresTest extends PostgresTestCase {
    use HubStatePortabilityTests;
}
