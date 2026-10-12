<?php

require_once __DIR__ . '/../PostgresTestCase.php';
require_once __DIR__ . '/PartyStatePortabilityTests.php';
require_once INCLUDE_GAME . 'party_state.php';

class PartyStatePortabilityPostgresTest extends PostgresTestCase {
    use PartyStatePortabilityTests;
}
