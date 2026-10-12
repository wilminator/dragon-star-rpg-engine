<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/AccountFlowPortabilityTests.php';
require_once INCLUDE_GAME . 'account_flow.php'; // not in bootstrap.php's require chain

class AccountFlowPortabilityMysqlTest extends DatabaseTestCase {
    use AccountFlowPortabilityTests;
}
