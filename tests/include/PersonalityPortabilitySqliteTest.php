<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/PersonalityPortabilityInstance.php';

class PersonalityPortabilitySqliteTest extends SqliteTestCase {
    use EntityPortabilityTests, PersonalityPortabilityInstance;
}
