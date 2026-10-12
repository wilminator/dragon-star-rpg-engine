<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/JobPortabilityInstance.php';

class JobPortabilityPostgresTest extends PostgresTestCase {
    use EntityPortabilityTests, JobPortabilityInstance;
}
