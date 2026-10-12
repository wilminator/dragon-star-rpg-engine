<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/FightPlayerStatePortabilityTests.php';
require_once INCLUDE_GAME . 'fight_player_state.php'; // not in bootstrap.php's require chain

class FightPlayerStatePortabilityMysqlTest extends DatabaseTestCase {
    use FightPlayerStatePortabilityTests;
}
