<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/AnomalyFlagPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_communicator.php'; // not in bootstrap.php's require chain
require_once INCLUDE_GAME . 'anomaly.php';

class AnomalyFlagPortabilityMysqlTest extends DatabaseTestCase {
    use AnomalyFlagPortabilityTests;
}
