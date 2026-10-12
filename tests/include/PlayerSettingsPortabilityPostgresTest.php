<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/PlayerSettingsPortabilityTests.php';
require_once INCLUDE_GAME . 'player_settings.php';
require_once INCLUDE_GAME . 'monster_side.php';

class PlayerSettingsPortabilityPostgresTest extends PostgresTestCase {
    use PlayerSettingsPortabilityTests;
}
