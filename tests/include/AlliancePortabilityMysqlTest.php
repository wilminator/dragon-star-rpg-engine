<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/AlliancePortabilityTests.php';
require_once INCLUDE_GAME . 'alliance.php';

class AlliancePortabilityMysqlTest extends DatabaseTestCase {
    use AlliancePortabilityTests;
}
