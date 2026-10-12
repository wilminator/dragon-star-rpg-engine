<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/FightSpectatorPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_spectator.php'; // not in bootstrap.php's require chain

class FightSpectatorPortabilityMysqlTest extends DatabaseTestCase {
    use FightSpectatorPortabilityTests;
}
