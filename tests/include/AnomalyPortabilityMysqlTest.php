<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/AnomalyPortabilityTests.php';
require_once INCLUDE_GAME . 'anomaly.php'; // not in bootstrap.php's require chain

class AnomalyPortabilityMysqlTest extends DatabaseTestCase {
    use AnomalyPortabilityTests;
}
