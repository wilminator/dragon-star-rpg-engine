<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/DatabaseTablePortabilityTests.php';
require_once INCLUDE_GAME . 'alliance_screen.php';

class DatabaseTablePortabilityPostgresTest extends PostgresTestCase {
    use DatabaseTablePortabilityTests;
}
