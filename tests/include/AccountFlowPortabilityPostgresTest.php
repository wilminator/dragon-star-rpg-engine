<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/AccountFlowPortabilityTests.php';
require_once INCLUDE_GAME . 'account_flow.php'; // not in bootstrap.php's require chain

class AccountFlowPortabilityPostgresTest extends PostgresTestCase {
    use AccountFlowPortabilityTests;
}
