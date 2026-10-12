<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/PersonalityPortabilityInstance.php';

class PersonalityPortabilityPostgresTest extends PostgresTestCase {
    use EntityPortabilityTests, PersonalityPortabilityInstance;
}
