<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'game_log.php';

class LogScrubberTest extends TestCase {

    public function test_values_under_telling_keys_are_replaced_at_any_depth() {
        $clean = LogScrubber::value([
            'password' => 'hunter2', 'new_password' => 'x', 'csrf' => 'c', 'code' => '123456', 'recovery_code' => 'r',
            'clientDataJSON' => 'j', 'signature' => 's', 'session_id' => 'sid', 'Authorization' => 'Basic abc',
            'nested' => ['deeper' => ['totp_secret' => 'JBSWY3DP', 'name' => 'Al']],
            'party' => 9,
        ]);
        foreach (['password', 'new_password', 'csrf', 'code', 'recovery_code', 'clientDataJSON', 'signature', 'session_id', 'Authorization'] as $key) {
            $this->assertSame(LogScrubber::MASK, $clean[$key], $key);
        }
        $this->assertSame(LogScrubber::MASK, $clean['nested']['deeper']['totp_secret']);
        $this->assertSame('Al', $clean['nested']['deeper']['name']);
        $this->assertSame(9, $clean['party']);
    }

    public function test_objects_are_scrubbed_like_arrays() {
        $this->assertSame(['token' => LogScrubber::MASK, 'n' => 1], LogScrubber::value((object) ['token' => 't', 'n' => 1]));
    }

    public function test_secrets_in_text_are_replaced() {
        $cases = [
            'login.php?user=al&password=hunter2&x=1' => 'hunter2',
            'otp=123456' => '123456',
            '{"code": "654321", "fight": 3}' => '654321',
            '{"new_password":"p\"q"}' => 'p\"q',
            'Authorization: Bearer eyJhbGciOi.abc.def' => 'eyJhbGciOi',
            'hash $argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA stored' => 'aGFzaA',
            'value enc1:0123456789abcdef:QUJDREVG== here' => 'QUJDREVG',
            'verify.php?t=' . str_repeat('9f', 32) => str_repeat('9f', 32),
        ];
        foreach ($cases as $text => $secret) {
            $clean = LogScrubber::text($text);
            $this->assertStringNotContainsString($secret, $clean, $text);
            $this->assertStringContainsString(LogScrubber::MASK, $clean, $text);
        }
    }

    public function test_ordinary_text_is_left_alone() {
        $text = 'Unknown action Zap in fight 12 for party 3 (error_code 5)';
        $this->assertSame($text, LogScrubber::text($text));
    }
}
