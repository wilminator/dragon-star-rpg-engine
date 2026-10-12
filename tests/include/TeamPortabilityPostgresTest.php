<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/TeamPortabilityTests.php';

class TeamPortabilityPostgresTest extends PostgresTestCase {
    use TeamPortabilityTests;
}
