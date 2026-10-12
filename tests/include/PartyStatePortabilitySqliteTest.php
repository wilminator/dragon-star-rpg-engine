<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once __DIR__ . '/PartyStatePortabilityTests.php';
require_once INCLUDE_GAME . 'party_state.php';

class PartyStatePortabilitySqliteTest extends SqliteTestCase {
    use PartyStatePortabilityTests;
}
