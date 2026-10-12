<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/UserAccountPortabilityTests.php';
require_once INCLUDE_GAME . 'account_admin.php'; // not in bootstrap.php's require chain

class UserAccountPortabilityMysqlTest extends DatabaseTestCase {
    use UserAccountPortabilityTests;
}
