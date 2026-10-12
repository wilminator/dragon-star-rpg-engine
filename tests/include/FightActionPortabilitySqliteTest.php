<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/FightActionPortabilityTests.php';
require_once INCLUDE_GAME . 'fight_action.php'; // not in bootstrap.php's require chain

class FightActionPortabilitySqliteTest extends SqliteTestCase {
    use FightActionPortabilityTests;
}
