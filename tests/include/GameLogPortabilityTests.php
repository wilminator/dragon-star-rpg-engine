<?php

/**
 * Covers the site log (GameLog): categories, switching them off,
 * per-category retention, searching, resolving, the per-request cap and
 * the file it falls back to, and log_error()'s routing.
 */
trait GameLogPortabilityTests {

    private function reset_log(): void {
        $this->reset_tables(GameLog::class, AppSetting::class);
        GameLog::new_request();
        // No purging as entries arrive unless a test asks for it.
        AppSetting::set($this->db, GameLog::RETENTION_CHECKED, (string) (PHP_INT_MAX >> 1));
    }

    private function kinds_of(array $entries): array {
        $kinds = array_map(fn($e) => $e['kind'], $entries);
        sort($kinds);
        return $kinds;
    }

    private function entries_created_at(string $category, array $times): void {
        foreach ($times as $time) {
            GameLog::write($category, 'notice', 't' . $time, 'at ' . $time, null, [], $this->db);
            $this->db->prepare("UPDATE game_log SET created_at = ? WHERE id = (SELECT MAX(id) FROM (SELECT id FROM game_log) AS ids)")->execute([$time]);
        }
    }

    // --- writing ---

    public function test_an_entry_keeps_what_it_was_given() {
        $this->reset_log();
        $this->assertTrue(GameLog::write('server_error', 'warning', 'php_warning', 'Undefined index', ['trace' => ['a.php:3 f']],
            ['userid' => 42, 'fightid' => 7, 'ip' => '203.0.113.1', 'request' => 'processor.php take_action', 'location' => 'b.php:9'], $this->db));

        $entry = GameLog::search($this->db)[0];
        $this->assertSame(['server_error', 'warning', 'php_warning', 'Undefined index', 42, 7, '203.0.113.1', 'processor.php take_action', 'b.php:9'],
            [$entry['category'], $entry['level'], $entry['kind'], $entry['message'], $entry['userid'], $entry['fightid'],
                $entry['ip'], $entry['request'], $entry['location']]);
        $this->assertSame(['trace' => ['a.php:3 f']], $entry['details']);
    }

    public function test_unknown_categories_levels_and_kinds_are_made_safe() {
        $this->reset_log();
        GameLog::write('nonsense', 'catastrophic', '<b>', 'm', null, [], $this->db);
        $entry = GameLog::search($this->db)[0];
        $this->assertSame(['server_error', 'error', 'unknown'], [$entry['category'], $entry['level'], $entry['kind']]);
    }

    public function test_a_long_message_is_cut_down() {
        $this->reset_log();
        GameLog::write('server_error', 'error', 'x', str_repeat('é', 2000), null, [], $this->db);
        $this->assertLessThanOrEqual(GameLog::MAX_MESSAGE, strlen(GameLog::search($this->db)[0]['message']));
    }

    public function test_entries_are_scrubbed() {
        $this->reset_log();
        GameLog::write('debug', 'debug', 'rpc', 'login?user=al&password=hunter2', null, [], $this->db); // off by default
        GameLog::set_debug_enabled($this->db, 'rpc', true);
        GameLog::write('debug', 'debug', 'rpc', 'login?user=al&password=hunter2',
            ['params' => ['otp' => '123456', 'name' => 'al'], 'raw' => '{"code":"654321","x":1}'],
            ['request' => 'reset.php?token=' . str_repeat('ab', 32)], $this->db);

        $entries = GameLog::search($this->db);
        $this->assertCount(1, $entries);
        $stored = json_encode($entries[0]);
        foreach (['hunter2', '123456', '654321', str_repeat('ab', 32)] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertSame('al', $entries[0]['details']['params']['name']);
    }

    public function test_a_category_can_be_switched_off_and_on() {
        $this->reset_log();
        $this->assertTrue(GameLog::enabled($this->db, 'server_error'));
        $this->assertFalse(GameLog::enabled($this->db, 'debug'), 'debug is off unless switched on');

        GameLog::set_enabled($this->db, 'server_error', false);
        $this->assertFalse(GameLog::write('server_error', 'error', 'x', 'dropped', null, [], $this->db));
        GameLog::set_enabled($this->db, 'server_error', true);
        $this->assertTrue(GameLog::write('server_error', 'error', 'x', 'kept', null, [], $this->db));
        $this->assertSame(['kept'], array_column(GameLog::search($this->db), 'message'));
    }

    public function test_past_the_per_request_cap_entries_go_to_the_file() {
        $this->reset_log();
        $file = tempnam(sys_get_temp_dir(), 'gamelog');
        $previous = GameLog::use_fallback_file($file);
        try {
            for ($i = 0; $i < GameLog::MAX_PER_REQUEST; $i++) {
                GameLog::write('server_error', 'notice', 'x', "entry $i", null, [], $this->db);
            }
            $this->assertFalse(GameLog::write('server_error', 'notice', 'x', 'one too many, password=hunter2', null, [], $this->db));
            $this->assertSame(GameLog::MAX_PER_REQUEST, GameLog::count($this->db, 'server_error'));
            $written = file_get_contents($file);
            $this->assertStringContainsString('[server_error/notice/x] one too many', $written);
            $this->assertStringNotContainsString('hunter2', $written, 'the file is scrubbed too');

            GameLog::new_request();
            $this->assertTrue(GameLog::write('server_error', 'notice', 'x', 'next request', null, [], $this->db));
        } finally {
            GameLog::use_fallback_file($previous);
            @unlink($file);
        }
    }

    // --- log_error() ---

    public function test_log_error_sorts_messages_into_categories() {
        $this->reset_log();
        GameLog::use_database($this->db);
        try {
            log_error('HACK: someone asked for a fight that is not theirs');
            log_error('Could not load the map');
            log_error(new RuntimeException('Boom'));
        } finally {
            GameLog::use_database(null);
        }
        $security = GameLog::search($this->db, ['category' => 'security']);
        $this->assertSame([['warning', 'refused_request']], array_map(fn($e) => [$e['level'], $e['kind']], $security));

        $errors = GameLog::search($this->db, ['category' => 'server_error']);
        $this->assertSame([['error', 'exception'], ['notice', 'log_error']], array_map(fn($e) => [$e['level'], $e['kind']], $errors));
        $this->assertSame('RuntimeException: Boom', $errors[0]['message']);
        $this->assertIsArray($errors[0]['details']['trace']);
        $this->assertStringContainsString('GameLogPortabilityTests.php:', $errors[1]['location'], 'where log_error was called');
    }

    public function test_each_debug_channel_is_switched_on_separately() {
        $this->reset_log();
        GameLog::use_database($this->db);
        $built = 0;
        try {
            $this->assertFalse(GameLog::debug_enabled('ai'), 'off by default');
            log_debug('ai', 'Targets', function () use (&$built) { $built++; return ['x' => 1]; });
            $this->assertSame(0, $built, 'details are never built for a channel that is off');

            GameLog::set_debug_enabled($this->db, 'monsters', true);
            log_debug('monsters', 'Monster party generated', ['target_pxp' => 120, 'password' => 'hunter2']);
            log_debug('ai', 'Targets', function () use (&$built) { $built++; return ['x' => 1]; });
            log_debug('nonsense', 'Not a channel');
            GameLog::set_debug_enabled($this->db, 'ai', true);
            log_debug('ai', 'Targets', function () use (&$built) { $built++; return ['x' => 1]; });
        } finally {
            GameLog::use_database(null);
        }
        $this->assertSame(1, $built);
        $entries = GameLog::search($this->db);
        $this->assertSame(['ai', 'monsters'], array_column($entries, 'kind'), 'newest first');
        $this->assertSame(['debug', 'debug', 'Monster party generated'], [$entries[1]['category'], $entries[1]['level'], $entries[1]['message']]);
        $this->assertSame(['target_pxp' => 120, 'password' => '[scrubbed]'], $entries[1]['details']);
        $this->assertSame(['x' => 1], $entries[0]['details']);
        $this->assertStringContainsString('GameLogPortabilityTests.php:', $entries[0]['location']);
        $this->assertFalse(GameLog::debug_enabled('nonsense', $this->db));
    }

    // --- retention ---

    public function test_no_retention_set_purges_nothing() {
        $this->reset_log();
        $this->entries_created_at('server_error', [1, 2, 3]);
        $this->assertSame(['days' => null, 'rows' => null, 'cull_percent' => 0], GameLog::retention($this->db, 'server_error'));
        $this->assertSame(0, GameLog::purge($this->db, 'server_error'));
    }

    public function test_each_category_is_its_own_log() {
        $this->reset_log();
        $this->entries_created_at('server_error', [1, 2, 3]);
        $this->entries_created_at('browser', [1, 2, 3]);
        GameLog::set_retention($this->db, 'server_error', null, 1, 0);

        $this->assertSame(['server_error' => 2, 'security' => 0, 'browser' => 0, 'client_error' => 0, 'debug' => 0],
            GameLog::purge_all($this->db));
        $this->assertSame(1, GameLog::count($this->db, 'server_error'));
        $this->assertSame(3, GameLog::count($this->db, 'browser'));
    }

    public function test_entries_past_the_row_limit_go_oldest_first() {
        $this->reset_log();
        $this->entries_created_at('browser', [1, 2, 3, 4, 5]);
        GameLog::set_retention($this->db, 'browser', null, 3, 0);

        $this->assertSame(2, GameLog::purge($this->db, 'browser'));
        $this->assertSame(['t3', 't4', 't5'], $this->kinds_of(GameLog::search($this->db)));
        $this->assertSame(0, GameLog::purge($this->db, 'browser'), 'nothing more is due');
    }

    public function test_entries_past_the_age_limit_go() {
        $this->reset_log();
        $now = 10 * 86400;
        $this->entries_created_at('browser', [$now - 5 * 86400, $now - 3 * 86400, $now - 86400, $now]);
        GameLog::set_retention($this->db, 'browser', 2, null, 0);

        $this->assertSame(2, GameLog::purge($this->db, 'browser', $now));
        $this->assertCount(2, GameLog::search($this->db));
    }

    public function test_with_both_limits_whichever_removes_more_wins() {
        $this->reset_log();
        $now = 10 * 86400;
        $this->entries_created_at('browser', [$now - 5 * 86400, $now - 4 * 86400, $now, $now, $now]);
        GameLog::set_retention($this->db, 'browser', 2, 4, 0);

        $this->assertSame(2, GameLog::purge($this->db, 'browser', $now), 'the age limit is due for 2, the row limit for 1');
        $this->assertCount(3, GameLog::search($this->db));
    }

    public function test_the_cull_takes_a_further_percentage_so_purges_come_in_batches() {
        $this->reset_log();
        $this->entries_created_at('browser', range(1, 12));
        GameLog::set_retention($this->db, 'browser', null, 10, 20);

        $this->assertSame(4, GameLog::purge($this->db, 'browser'), '2 due, plus 20% of the 10-row limit');
        $this->assertCount(8, GameLog::search($this->db));
        $this->entries_created_at('browser', [13, 14]);
        $this->assertSame(0, GameLog::purge($this->db, 'browser'), 'room to spare until the limit is passed again');
    }

    public function test_a_zero_percent_cull_still_removes_what_is_due() {
        $this->reset_log();
        $this->entries_created_at('browser', [1, 2, 3, 4]);
        GameLog::set_retention($this->db, 'browser', null, 3, 0);
        $this->assertSame(1, GameLog::purge($this->db, 'browser'));
    }

    public function test_the_cull_never_removes_more_than_there_is() {
        $this->reset_log();
        $this->entries_created_at('browser', [1, 2]);
        GameLog::set_retention($this->db, 'browser', null, 1, 100);
        $this->assertSame(2, GameLog::purge($this->db, 'browser'));
        $this->assertSame(0, GameLog::count($this->db, 'browser'));
    }

    public function test_bad_retention_settings_are_refused() {
        $this->reset_log();
        $this->assertNotSame([], GameLog::set_retention($this->db, 'browser', 0, null, 0));
        $this->assertNotSame([], GameLog::set_retention($this->db, 'browser', null, 0, 0));
        $this->assertNotSame([], GameLog::set_retention($this->db, 'browser', null, null, 101));
        $this->assertNotSame([], GameLog::set_retention($this->db, 'nonsense', 30, null, 0));
        $this->assertSame([], GameLog::set_retention($this->db, 'browser', 30, 10000, 10));
        $this->assertSame(['days' => 30, 'rows' => 10000, 'cull_percent' => 10], GameLog::retention($this->db, 'browser'));
        $this->assertSame(['days' => null, 'rows' => null, 'cull_percent' => 0], GameLog::retention($this->db, 'client_error'), 'other logs untouched');
        $this->assertSame([], GameLog::set_retention($this->db, 'browser', null, null, 0));
        $this->assertSame(['days' => null, 'rows' => null, 'cull_percent' => 0], GameLog::retention($this->db, 'browser'));
    }

    public function test_purging_as_entries_arrive_happens_at_most_once_a_minute() {
        $this->reset_log();
        $this->entries_created_at('browser', [1, 2, 3]);
        GameLog::set_retention($this->db, 'browser', null, 1, 0);
        AppSetting::set($this->db, GameLog::RETENTION_CHECKED, '0');

        $this->assertSame(2, GameLog::maybe_purge($this->db, 1000)['browser']);
        // Straight into the table: write() would itself purge, by the clock.
        $this->db->prepare($this->db->insert_query('game_log', ['created_at', 'category', 'level', 'kind', 'message']))
            ->execute([4, 'browser', 'notice', 't4', 'at 4']);
        $this->assertSame([], GameLog::maybe_purge($this->db, 1030), 'checked 30 seconds ago');
        $this->assertSame(1, GameLog::maybe_purge($this->db, 1061)['browser']);
    }

    // --- reviewing ---

    public function test_search_filters() {
        $this->reset_log();
        GameLog::write('browser', 'warning', 'resource_load_failed', 'url: images/dragon.png', null, ['userid' => 42, 'fightid' => 7], $this->db);
        GameLog::write('client_error', 'error', 'client_error', 'Unknown action', ['action' => 'Zap'], ['userid' => 43, 'fightid' => 8], $this->db);

        $this->assertSame(['resource_load_failed'], $this->kinds_of(GameLog::search($this->db, ['kind' => 'resource_load_failed'])));
        $this->assertSame(['client_error'], $this->kinds_of(GameLog::search($this->db, ['category' => 'client_error'])));
        $this->assertSame(['client_error'], $this->kinds_of(GameLog::search($this->db, ['level' => 'error'])));
        $this->assertSame(['client_error'], $this->kinds_of(GameLog::search($this->db, ['userid' => 43])));
        $this->assertSame(['resource_load_failed'], $this->kinds_of(GameLog::search($this->db, ['fightid' => 7])));
        $this->assertSame(['resource_load_failed'], $this->kinds_of(GameLog::search($this->db, ['text' => 'DRAGON'])));
        $this->assertSame(['client_error'], $this->kinds_of(GameLog::search($this->db, ['text' => 'zap'])), 'in the details too');
        $this->assertCount(2, GameLog::search($this->db, ['from' => time() - 60, 'to' => time() + 60]));
        $this->assertSame([], GameLog::search($this->db, ['to' => time() - 3600]));
        $this->assertCount(1, GameLog::search($this->db, ['limit' => 1]));
    }

    public function test_resolving_and_reopening() {
        $this->reset_log();
        foreach (['a', 'b', 'b'] as $kind) {
            GameLog::write('browser', 'warning', $kind, 'm', null, [], $this->db);
        }
        GameLog::write('server_error', 'error', 'a', 'm', null, [], $this->db);
        $ids = array_column(GameLog::search($this->db, ['kind' => 'b']), 'id');

        $this->assertSame(2, GameLog::set_resolved($this->db, $ids, true, 1));
        $this->assertSame(0, GameLog::set_resolved($this->db, $ids, true, 1), 'already resolved');
        $this->assertSame(['a', 'a'], $this->kinds_of(GameLog::search($this->db, ['status' => 'open'])));
        $resolved = GameLog::search($this->db, ['status' => 'resolved']);
        $this->assertCount(2, $resolved);
        $this->assertSame(1, $resolved[0]['resolved_by']);
        $this->assertSame(['browser' => ['a' => 1], 'server_error' => ['a' => 1]], GameLog::open_counts($this->db));

        $this->assertSame(1, GameLog::set_resolved($this->db, [$ids[0]], false, 1));
        $this->assertSame(['a' => 1, 'b' => 1], GameLog::open_counts($this->db)['browser']);
        $this->assertSame(['a', 'b'], GameLog::kinds($this->db, 'browser'));
        $this->assertSame(['a'], GameLog::kinds($this->db, 'server_error'));
    }

    public function test_open_counts_put_the_most_common_first() {
        $this->reset_log();
        foreach (['rare', 'common', 'common'] as $kind) {
            GameLog::write('browser', 'warning', $kind, 'm', null, [], $this->db);
        }
        $this->assertSame(['common' => 2, 'rare' => 1], GameLog::open_counts($this->db)['browser']);
    }
}
