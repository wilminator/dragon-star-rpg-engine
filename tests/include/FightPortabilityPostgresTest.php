<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/FightPortabilityTests.php';
require_once INCLUDE_GAME . 'party.php'; // not in bootstrap.php's require chain
require_once INCLUDE_GAME . 'fight.php'; // not in bootstrap.php's require chain

class FightPortabilityPostgresTest extends PostgresTestCase {
    use FightPortabilityTests;
}
