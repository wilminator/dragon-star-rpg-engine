<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/EquipmentChangePortabilityTests.php';
require_once INCLUDE_GAME . 'equipment_change.php';

class EquipmentChangePortabilityMysqlTest extends DatabaseTestCase {
    use EquipmentChangePortabilityTests;
}
