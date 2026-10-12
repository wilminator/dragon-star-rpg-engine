<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/TwoFactorPortabilityTests.php';
require_once INCLUDE_GAME . 'account_admin.php'; // not in bootstrap.php's require chain
require_once INCLUDE_GAME . 'two_factor_change.php';

class TwoFactorPortabilityMysqlTest extends DatabaseTestCase {
    use TwoFactorPortabilityTests;
}
