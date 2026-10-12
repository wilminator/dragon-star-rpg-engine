<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/AlliancePortabilityTests.php';
require_once INCLUDE_GAME . 'alliance.php';

class AlliancePortabilitySqliteTest extends SqliteTestCase {
    use AlliancePortabilityTests;
}
