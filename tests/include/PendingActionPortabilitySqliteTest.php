<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/PendingActionPortabilityTests.php';
require_once INCLUDE_GAME . 'pending_action.php'; // not in bootstrap.php's require chain

class PendingActionPortabilitySqliteTest extends SqliteTestCase {
    use PendingActionPortabilityTests;
}
