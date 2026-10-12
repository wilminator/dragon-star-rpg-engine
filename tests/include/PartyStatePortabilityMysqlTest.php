<?php

require_once __DIR__ . '/../DatabaseTestCase.php';
require_once __DIR__ . '/PartyStatePortabilityTests.php';
require_once INCLUDE_GAME . 'party_state.php';

class PartyStatePortabilityMysqlTest extends DatabaseTestCase {
    use PartyStatePortabilityTests;
}
