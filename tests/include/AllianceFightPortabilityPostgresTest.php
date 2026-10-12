<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/AllianceFightPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_communicator.php'; // not in bootstrap.php's require chain
require_once INCLUDE_GAME . 'anomaly.php';

class AllianceFightPortabilityPostgresTest extends PostgresTestCase {
    use AllianceFightPortabilityTests;
}
