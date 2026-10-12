<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/EquipmentChangePortabilityTests.php';
require_once INCLUDE_GAME . 'equipment_change.php';

class EquipmentChangePortabilitySqliteTest extends SqliteTestCase {
    use EquipmentChangePortabilityTests;
}
