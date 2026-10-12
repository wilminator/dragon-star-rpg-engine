<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/DatabaseTablePortabilityTests.php';
require_once INCLUDE_GAME . 'alliance_screen.php';

class DatabaseTablePortabilityMysqlTest extends DatabaseTestCase {
    use DatabaseTablePortabilityTests;
}
