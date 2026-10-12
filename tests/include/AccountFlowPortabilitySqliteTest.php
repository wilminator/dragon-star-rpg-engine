<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/AccountFlowPortabilityTests.php';
require_once INCLUDE_GAME . 'account_flow.php'; // not in bootstrap.php's require chain

class AccountFlowPortabilitySqliteTest extends SqliteTestCase {
    use AccountFlowPortabilityTests;
}
