<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/AllianceFightPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_communicator.php'; // not in bootstrap.php's require chain
require_once INCLUDE_GAME . 'anomaly.php';

class AllianceFightPortabilitySqliteTest extends SqliteTestCase {
    use AllianceFightPortabilityTests;
}
