<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/JobPortabilityInstance.php';

class JobPortabilityMysqlTest extends DatabaseTestCase {
    use EntityPortabilityTests, JobPortabilityInstance;
}
