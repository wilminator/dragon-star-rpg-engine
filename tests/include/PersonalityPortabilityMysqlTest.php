<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/PersonalityPortabilityInstance.php';

class PersonalityPortabilityMysqlTest extends DatabaseTestCase {
    use EntityPortabilityTests, PersonalityPortabilityInstance;
}
