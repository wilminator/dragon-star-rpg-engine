<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/AllianceScreenPortabilityTests.php';
require_once INCLUDE_GAME . 'alliance_screen.php';
require_once INCLUDE_GAME . 'hub_state.php';

class AllianceScreenPortabilitySqliteTest extends SqliteTestCase {
    use AllianceScreenPortabilityTests;
}
