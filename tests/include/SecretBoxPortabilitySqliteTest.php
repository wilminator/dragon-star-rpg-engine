<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/SecretBoxPortabilityTests.php';
require_once INCLUDE_GAME . 'auth.php'; // not in bootstrap.php's require chain
require_once INCLUDE_GAME . 'two_factor_change.php';

class SecretBoxPortabilitySqliteTest extends SqliteTestCase {
    use SecretBoxPortabilityTests;
}
