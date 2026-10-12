<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/EntityPortabilityTests.php';
require_once __DIR__ . '/JobPortabilityInstance.php';

class JobPortabilitySqliteTest extends SqliteTestCase {
    use EntityPortabilityTests, JobPortabilityInstance;
}
