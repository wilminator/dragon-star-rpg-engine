<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/AlliancePortabilityTests.php';
require_once INCLUDE_GAME . 'alliance.php';

class AlliancePortabilityPostgresTest extends PostgresTestCase {
    use AlliancePortabilityTests;
}
