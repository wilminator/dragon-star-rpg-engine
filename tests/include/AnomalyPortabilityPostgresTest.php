<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/AnomalyPortabilityTests.php';
require_once INCLUDE_GAME . 'anomaly.php'; // not in bootstrap.php's require chain

class AnomalyPortabilityPostgresTest extends PostgresTestCase {
    use AnomalyPortabilityTests;
}
