<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/FightBrowserPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_browser.php'; // not in bootstrap.php's require chain

class FightBrowserPortabilityPostgresTest extends PostgresTestCase {
    use FightBrowserPortabilityTests;
}
