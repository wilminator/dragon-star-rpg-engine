<?php

/**
 * Covers storing problems reported by players' browsers (Anomaly) in
 * the site log: batches of reports, client errors and the limits.
 * Retention, searching and resolving are GameLog's -- see
 * GameLogPortabilityTests.
 */
trait AnomalyPortabilityTests {

    private function reset_anomalies(): void {
        $this->reset_tables(GameLog::class, RateLimit::class, AppSetting::class);
        GameLog::new_request();
    }

    private function kinds_of(array $anomalies): array {
        return array_map(fn($a) => $a['kind'], $anomalies);
    }

    public function test_a_batch_of_reports_is_stored_one_row_each() {
        $this->reset_anomalies();

        $kept = Anomaly::record_reports($this->db, [
            ['type' => 'resource_load_failed', 'kind' => 'image', 'url' => 'images/a.png'],
            ['type' => 'resource_load_failed', 'kind' => 'sound', 'url' => 'sound/b.mp3'],
        ], 42, 7, '203.0.113.1');

        $this->assertSame(2, $kept);
        $stored = GameLog::search($this->db);
        $this->assertCount(2, $stored);
        $newest = $stored[0];
        $this->assertSame(['browser', 'warning', 'resource_load_failed', 42, 7, '203.0.113.1'],
            [$newest['category'], $newest['level'], $newest['kind'], $newest['userid'], $newest['fightid'], $newest['ip']]);
        $this->assertSame('sound/b.mp3', $newest['details']['url']);
        $this->assertStringContainsString('url: sound/b.mp3', $newest['message']);
        $this->assertNull($newest['resolved_at']);
    }

    public function test_a_report_type_that_is_not_a_plain_name_is_stored_as_unknown() {
        $this->reset_anomalies();
        Anomaly::record_reports($this->db, [['type' => '<script>'], ['no type' => 1], 'just a string'], 42, null, '203.0.113.1');
        $this->assertSame(['unknown', 'unknown', 'unknown'], $this->kinds_of(GameLog::search($this->db)));
    }

    public function test_a_client_error_keeps_its_message_and_data() {
        $this->reset_anomalies();

        $this->assertTrue(Anomaly::record_client_error($this->db, 'Bad party', ['party' => 9], 42, null, '203.0.113.1'));

        $stored = GameLog::search($this->db)[0];
        $this->assertSame(['client_error', 'client_error', 'Bad party'], [$stored['category'], $stored['kind'], $stored['message']]);
        $this->assertSame(['message' => 'Bad party', 'data' => ['party' => 9]], $stored['details']);
    }

    public function test_huge_details_are_cut_down() {
        $this->reset_anomalies();
        Anomaly::record_client_error($this->db, 'Bad party', ['fight' => str_repeat('x', 200000)], 42, null, '203.0.113.1');

        $stored = GameLog::search($this->db)[0];
        $this->assertTrue($stored['details']['truncated']);
        $this->assertLessThanOrEqual(GameLog::MAX_DETAILS, strlen(json_encode($stored['details'])));
    }

    public function test_one_batch_keeps_at_most_the_batch_limit() {
        $this->reset_anomalies();
        $kept = Anomaly::record_reports($this->db, array_fill(0, Anomaly::MAX_PER_BATCH + 10, ['type' => 'x']), 42, null, '203.0.113.1');
        $this->assertSame(Anomaly::MAX_PER_BATCH, $kept);
    }

    public function test_reports_are_limited_per_player_or_address() {
        $this->reset_anomalies();
        for ($i = 0; $i < Anomaly::PER_HOUR; $i++) {
            RateLimit::hit($this->db, 'anomaly-user:42', Anomaly::PER_HOUR, 3600);
        }

        $this->assertSame(0, Anomaly::record_reports($this->db, [['type' => 'x']], 42, null, '203.0.113.1'));
        $this->assertFalse(Anomaly::record_client_error($this->db, 'm', null, 42, null, '203.0.113.1'));
        $this->assertSame(1, Anomaly::record_reports($this->db, [['type' => 'x']], 43, null, '203.0.113.1'), 'another player');
    }

    public function test_a_spectator_who_is_not_logged_in_is_heard_only_about_a_fight() {
        $this->reset_anomalies();

        $this->assertSame(0, Anomaly::record_reports($this->db, [['type' => 'x']], null, null, '203.0.113.1'));
        $this->assertFalse(Anomaly::record_client_error($this->db, 'm', null, null, null, '203.0.113.1'));
        $this->assertSame(1, Anomaly::record_reports($this->db, [['type' => 'x']], null, 7, '203.0.113.1'));
        $this->assertNull(GameLog::search($this->db)[0]['userid']);
    }

    public function test_anonymous_reports_are_limited_per_fight_whoever_sends_them() {
        $this->reset_anomalies();
        for ($i = 0; $i < Anomaly::PER_FIGHT_HOUR; $i++) {
            RateLimit::hit($this->db, 'anomaly-fight:7', Anomaly::PER_FIGHT_HOUR, 3600);
        }

        $this->assertSame(0, Anomaly::record_reports($this->db, [['type' => 'x']], null, 7, '198.51.100.9'), 'another address, same fight');
        $this->assertSame(1, Anomaly::record_reports($this->db, [['type' => 'x']], null, 8, '198.51.100.9'), 'another fight');
        $this->assertSame(1, Anomaly::record_reports($this->db, [['type' => 'x']], 42, 7, '198.51.100.9'), 'a logged-in player has their own limit');
    }

    public function test_reports_are_scrubbed() {
        $this->reset_anomalies();
        Anomaly::record_client_error($this->db, 'Failed: password=hunter2', ['csrf' => 'abc', 'party' => 9], 42, null, '203.0.113.1');
        $stored = GameLog::search($this->db)[0];
        $this->assertStringNotContainsString('hunter2', json_encode($stored));
        $this->assertStringNotContainsString('abc', json_encode($stored['details']));
        $this->assertSame(9, $stored['details']['data']['party']);
    }

    public function test_reports_for_a_fight_are_found() {
        $this->reset_anomalies();
        $this->assertFalse(Anomaly::has_reports_for_fight($this->db, 7));
        GameLog::write('server_error', 'error', 'x', 'not a report', null, ['fightid' => 7], $this->db);
        $this->assertFalse(Anomaly::has_reports_for_fight($this->db, 7), 'only reports from browsers count');
        Anomaly::record_reports($this->db, [['type' => 'x']], null, 7, '203.0.113.1');
        $this->assertTrue(Anomaly::has_reports_for_fight($this->db, 7));
    }
}
