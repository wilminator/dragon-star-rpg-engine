<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/StorePortabilityTests.php';
require_once INCLUDE_GAME . 'store.php';

class StorePortabilityPostgresTest extends PostgresTestCase {
    use StorePortabilityTests;
}
