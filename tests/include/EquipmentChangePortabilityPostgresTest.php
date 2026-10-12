<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/EquipmentChangePortabilityTests.php';
require_once INCLUDE_GAME . 'equipment_change.php';

class EquipmentChangePortabilityPostgresTest extends PostgresTestCase {
    use EquipmentChangePortabilityTests;
}
