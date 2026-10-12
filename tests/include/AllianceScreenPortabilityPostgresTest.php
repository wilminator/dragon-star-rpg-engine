<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/AllianceScreenPortabilityTests.php';
require_once INCLUDE_GAME . 'alliance_screen.php';
require_once INCLUDE_GAME . 'hub_state.php';

class AllianceScreenPortabilityPostgresTest extends PostgresTestCase {
    use AllianceScreenPortabilityTests;
}
