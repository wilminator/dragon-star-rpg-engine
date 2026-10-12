<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/FightCommunicatorPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_communicator.php'; // not in bootstrap.php's require chain

class FightCommunicatorPortabilityMysqlTest extends DatabaseTestCase {
    use FightCommunicatorPortabilityTests;
}
