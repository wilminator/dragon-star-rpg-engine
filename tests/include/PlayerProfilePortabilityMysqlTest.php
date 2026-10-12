<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/PlayerProfilePortabilityTests.php';
require_once INCLUDE_GAME . 'player_profile.php';
require_once INCLUDE_GAME . 'user_account.php';

class PlayerProfilePortabilityMysqlTest extends DatabaseTestCase {
    use PlayerProfilePortabilityTests;
}
