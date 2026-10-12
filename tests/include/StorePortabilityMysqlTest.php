<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/StorePortabilityTests.php';
require_once INCLUDE_GAME . 'store.php';

class StorePortabilityMysqlTest extends DatabaseTestCase {
    use StorePortabilityTests;
}
