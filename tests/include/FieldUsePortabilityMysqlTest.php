<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/FieldUsePortabilityTests.php';
require_once INCLUDE_GAME . 'field_use.php';

class FieldUsePortabilityMysqlTest extends DatabaseTestCase {
    use FieldUsePortabilityTests;
}
