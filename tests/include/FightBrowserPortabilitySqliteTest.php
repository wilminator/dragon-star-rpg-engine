<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/FightBrowserPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_browser.php'; // not in bootstrap.php's require chain

class FightBrowserPortabilitySqliteTest extends SqliteTestCase {
    use FightBrowserPortabilityTests;
}
