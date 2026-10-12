<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/StorePortabilityTests.php';
require_once INCLUDE_GAME . 'store.php';

class StorePortabilitySqliteTest extends SqliteTestCase {
    use StorePortabilityTests;
}
