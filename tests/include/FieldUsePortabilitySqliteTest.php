<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/FieldUsePortabilityTests.php';
require_once INCLUDE_GAME . 'field_use.php';

class FieldUsePortabilitySqliteTest extends SqliteTestCase {
    use FieldUsePortabilityTests;
}
