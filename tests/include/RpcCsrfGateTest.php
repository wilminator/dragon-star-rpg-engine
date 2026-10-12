<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'proxy.php';
require_once INCLUDE_GAME . 'csrf.php';

/**
 * processor.php's calls need the page's CSRF token (an X-CSRF-Token
 * header) from a logged-in session, so another site can't make them as
 * the player. Only the calls anyone may make, and the overworld's
 * reads, go through without it.
 */
class RpcCsrfGateTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__proxy_values'] = [];
        $GLOBALS['userid'] = 42;
        unset($GLOBALS['is_anonymous'], $GLOBALS['rpc_csrf_valid']);
        $_SESSION['csrf_token'] = str_repeat('ab', 32);
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void {
        unset($GLOBALS['rpc_csrf_valid'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_SESSION['csrf_token']);
        $GLOBALS['__proxy_values'] = [];
    }

    public static function guarded_methods(): array {
        return [['change_character_command'], ['process_fight_commands'], ['character_sheet'], ['preview_equipment'], ['move_hero'], ['rest_at_inn'], ['change_equipment'], ['use_thing'], ['store_buy'], ['store_sell'], ['change_hero'], ['alliance_act']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guarded_methods')]
    public function test_a_call_without_the_token_is_refused_before_its_handler_loads(string $method) {
        $GLOBALS['rpc_csrf_valid'] = false;
        dispatch_rpc_call($method, []);
        $this->assertSame([], $GLOBALS['__proxy_values']);
        $this->assertFalse(function_exists($method), "$method's handler must not even be loaded");
    }

    public function test_the_header_must_match_the_sessions_token() {
        $this->assertFalse(csrf_header_valid(), 'no header');
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('cd', 32);
        $this->assertFalse(csrf_header_valid(), 'wrong token');
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('ab', 32);
        $this->assertTrue(csrf_header_valid());
    }

    public function test_everything_anyone_may_call_needs_no_token() {
        foreach ([...SPECTATOR_RPC_METHODS, ...ANONYMOUS_REPORT_METHODS] as $method) {
            $this->assertContains($method, CSRF_EXEMPT_RPC_METHODS);
        }
        $this->assertSame([], array_values(array_diff(CSRF_EXEMPT_RPC_METHODS,
            [...SPECTATOR_RPC_METHODS, ...ANONYMOUS_REPORT_METHODS])),
            'nothing else is exempt');
    }

    public function test_the_map_calls_are_gone_until_maps_return() {
        $this->assertNotContains('fetch_map_data', CSRF_EXEMPT_RPC_METHODS);
        $this->assertNotContains('fetch_map_size', CSRF_EXEMPT_RPC_METHODS);
        $source = file_get_contents(INCLUDE_GAME . 'proxy.php');
        $this->assertStringNotContainsString("case 'fetch_map_data'", $source, 'they built file paths from what the client sent');
        $this->assertStringNotContainsString("case 'fetch_map_size'", $source);
    }
}
