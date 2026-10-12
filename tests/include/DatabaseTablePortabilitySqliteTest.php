<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/DatabaseTablePortabilityTests.php';
require_once INCLUDE_GAME . 'alliance_screen.php';

class DatabaseTablePortabilitySqliteTest extends SqliteTestCase {
    use DatabaseTablePortabilityTests;
}
