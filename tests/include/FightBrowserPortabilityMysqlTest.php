<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/FightBrowserPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_browser.php'; // not in bootstrap.php's require chain

class FightBrowserPortabilityMysqlTest extends DatabaseTestCase {
    use FightBrowserPortabilityTests;
}
