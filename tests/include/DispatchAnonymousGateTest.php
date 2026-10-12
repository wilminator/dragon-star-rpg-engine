<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'proxy.php';

/**
 * processor.php lets anonymous (not logged in) sessions through so the
 * public can spectate. dispatch_rpc_call() is what keeps that safe: an
 * anonymous session may make the read-only spectator calls and nothing
 * else. A refused call must not run -- or even load -- its handler.
 */
class DispatchAnonymousGateTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__proxy_values'] = [];
        $GLOBALS['userid'] = 0;
        $GLOBALS['is_anonymous'] = true;
    }

    protected function tearDown(): void {
        unset($GLOBALS['is_anonymous']);
        $GLOBALS['__proxy_values'] = [];
    }

    public static function refused_methods(): array {
        return [
            ['change_character_command'],
            ['process_fight_commands'],
            ['check_fight_timeout'],
            ['ack_playback'],
            ['request_fight_data'],
            ['request_resource_manifest'],
            ['character_sheet'],
            ['preview_equipment'],
            ['hub_state'],
            ['move_hero'],
            ['rest_at_inn'],
            ['change_equipment'],
            ['field_options'],
            ['use_thing'],
            ['store_open'],
            ['manage_options'],
            ['change_hero'],
            ['alliance_view'],
            ['alliance_act'],
            ['store_goods'],
            ['store_buy'],
            ['store_sell'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused_methods')]
    public function test_an_anonymous_session_cannot_make_a_non_spectator_call(string $method) {
        dispatch_rpc_call($method, []);

        $this->assertSame([], $GLOBALS['__proxy_values'], "$method must not produce a response");
        $this->assertFalse(function_exists($method), "$method's handler must not even be loaded");
    }

    public function test_the_spectator_calls_are_exactly_the_anonymous_allowlist() {
        $this->assertSame(
            ['request_spectator_data', 'poll_spectator_data', 'request_spectator_resource_manifest'],
            SPECTATOR_RPC_METHODS
        );
    }

    public function test_anonymous_spectators_may_report_problems() {
        $this->assertSame(['client_error', 'report_anomalies'], ANONYMOUS_REPORT_METHODS);
    }
}
