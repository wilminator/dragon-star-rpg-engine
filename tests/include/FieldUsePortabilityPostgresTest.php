<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/FieldUsePortabilityTests.php';
require_once INCLUDE_GAME . 'field_use.php';

class FieldUsePortabilityPostgresTest extends PostgresTestCase {
    use FieldUsePortabilityTests;
}
