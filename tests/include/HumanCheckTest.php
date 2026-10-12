<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'human_check.php';

/**
 * Covers the free bot checks: the honeypot field, and Cloudflare
 * Turnstile with its verification call faked.
 */
class HumanCheckTest extends TestCase {

    public function test_the_honeypot_is_tripped_only_when_filled_in() {
        $this->assertFalse(honeypot_tripped([]));
        $this->assertFalse(honeypot_tripped([HONEYPOT_FIELD => '  ']));
        $this->assertTrue(honeypot_tripped([HONEYPOT_FIELD => 'http://spam.example']));
    }

    public function test_without_keys_turnstile_is_off_and_everyone_passes() {
        $check = new HumanCheck('', '', fn() => throw new LogicException('should not be called'));
        $this->assertFalse($check->enabled());
        $this->assertTrue($check->passed([], '203.0.113.1'));
    }

    public function test_turnstile_asks_cloudflare() {
        $calls = [];
        $check = new HumanCheck('site', 'secret', function ($url, $fields) use (&$calls) {
            $calls[] = [$url, $fields];
            return ['success' => $fields['response'] === 'good'];
        });

        $this->assertTrue($check->passed([HumanCheck::RESPONSE_FIELD => 'good'], '203.0.113.1'));
        $this->assertFalse($check->passed([HumanCheck::RESPONSE_FIELD => 'bad'], '203.0.113.1'));
        $this->assertSame([HumanCheck::VERIFY_URL, ['secret' => 'secret', 'response' => 'good', 'remoteip' => '203.0.113.1']], $calls[0]);
    }

    public function test_turnstile_fails_closed_without_an_answer_or_when_unreachable() {
        $check = new HumanCheck('site', 'secret', fn() => throw new RuntimeException('down'));
        $this->assertFalse($check->passed([], '203.0.113.1'));
        $this->assertFalse($check->passed([HumanCheck::RESPONSE_FIELD => 'x'], '203.0.113.1'));
    }
}
