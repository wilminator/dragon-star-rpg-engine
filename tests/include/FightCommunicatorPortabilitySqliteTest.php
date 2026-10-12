<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/FightCommunicatorPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_communicator.php'; // not in bootstrap.php's require chain

class FightCommunicatorPortabilitySqliteTest extends SqliteTestCase {
    use FightCommunicatorPortabilityTests;
}
