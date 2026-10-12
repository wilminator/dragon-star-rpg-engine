<?php

/**
 * Tokens earned as the monsters (MonsterToken) and spent on alliances
 * (Alliance): the earning limit, forming, adding, extending, the fight
 * clock, overtime and letting teams go.
 */
trait AlliancePortabilityTests {

    private const OWNED = [11, 12, 13];

    private function reset_alliances(): void {
        $this->reset_tables(MonsterToken::class, Alliance::class);
    }

    private function tokens(int $n, int $userid = 7): void {
        MonsterToken::grant($this->db, $userid, $n, 1);
    }

    // --- earning ---

    public function test_a_fight_earns_one_and_a_win_one_more() {
        $this->reset_alliances();
        $this->assertSame(1, MonsterToken::award($this->db, 7, true, false, 100));
        $this->assertSame(2, MonsterToken::award($this->db, 8, true, true, 101));
        $this->assertSame(1, MonsterToken::award($this->db, 9, false, true, 102), 'abandoned rounds lose the fight token, not the win');
        $this->assertSame(0, MonsterToken::award($this->db, 10, false, false, 103));
        $this->assertSame([1, 2, 1, 0], array_map(fn($u) => MonsterToken::balance($this->db, $u), [7, 8, 9, 10]));
    }

    public function test_the_daily_limit_lets_a_fights_whole_award_through_then_stops() {
        $this->reset_alliances();
        $now = 1000000;
        $this->assertSame(2, MonsterToken::award($this->db, 7, true, true, 1, $now));
        $this->assertSame(2, MonsterToken::award($this->db, 7, true, true, 2, $now + 60), 'under the limit: the whole award, to 4');
        $this->assertSame(0, MonsterToken::award($this->db, 7, true, true, 3, $now + 120));
        $this->assertSame(4, MonsterToken::earned_recently($this->db, 7, $now + 120));
    }

    public function test_the_limit_is_a_rolling_24_hours() {
        $this->reset_alliances();
        $now = 1000000;
        MonsterToken::award($this->db, 7, true, true, 1, $now);
        MonsterToken::award($this->db, 7, true, false, 2, $now + 3600);
        $this->assertSame(0, MonsterToken::award($this->db, 7, true, false, 3, $now + 86399));
        $this->assertSame(1, MonsterToken::award($this->db, 7, true, false, 4, $now + 86401), 'the first fight\'s two have rolled off');
    }

    public function test_granted_and_spent_tokens_do_not_count_toward_the_limit() {
        $this->reset_alliances();
        $this->tokens(10);
        $this->assertTrue(MonsterToken::spend($this->db, 7, 3));
        $this->assertSame(0, MonsterToken::earned_recently($this->db, 7));
        $this->assertSame(7, MonsterToken::balance($this->db, 7));
        $this->assertFalse(MonsterToken::spend($this->db, 7, 8));
        $this->assertSame(7, MonsterToken::balance($this->db, 7));
    }

    // --- forming ---

    public function test_one_token_brings_a_second_team_for_an_hour() {
        $this->reset_alliances();
        $this->tokens(1);
        $this->assertNull(Alliance::form($this->db, 7, self::OWNED, 11, [12]));
        $alliance = Alliance::load($this->db, 7);
        $this->assertSame([[11, 12], Alliance::HOUR, Alliance::ACTIVE], [$alliance->teamids, $alliance->seconds_left, $alliance->state]);
        $this->assertSame(0, MonsterToken::balance($this->db, 7));
        $this->assertSame([11, 12], Alliance::party_teamids($this->db, 7, 11, self::OWNED));
        $this->assertSame([12, 11], Alliance::party_teamids($this->db, 7, 12, self::OWNED), 'either member can lead');
        $this->assertSame([13], Alliance::party_teamids($this->db, 7, 13, self::OWNED), 'a team outside it fights alone');
    }

    public function test_two_tokens_at_once_bring_both_teams_for_the_same_hour() {
        $this->reset_alliances();
        $this->tokens(2);
        $this->assertNull(Alliance::form($this->db, 7, self::OWNED, 11, [12, 13]));
        $this->assertSame([[11, 12, 13], Alliance::HOUR], [Alliance::load($this->db, 7)->teamids, Alliance::load($this->db, 7)->seconds_left]);
        $this->assertSame(0, MonsterToken::balance($this->db, 7));
    }

    public function test_forming_is_refused_without_enough_tokens_and_nothing_is_spent() {
        $this->reset_alliances();
        $this->tokens(1);
        $this->assertNotNull(Alliance::form($this->db, 7, self::OWNED, 11, [12, 13]));
        $this->assertNull(Alliance::load($this->db, 7));
        $this->assertSame(1, MonsterToken::balance($this->db, 7));
    }

    public function test_only_the_players_own_distinct_teams_can_join() {
        $this->reset_alliances();
        $this->tokens(5);
        $this->assertNotNull(Alliance::form($this->db, 7, self::OWNED, 11, [99]), 'not theirs');
        $this->assertNotNull(Alliance::form($this->db, 7, self::OWNED, 11, [11]), 'itself');
        $this->assertNotNull(Alliance::form($this->db, 7, self::OWNED, 11, []), 'no one');
        $this->assertSame(5, MonsterToken::balance($this->db, 7));
    }

    // --- adding and extending ---

    public function test_the_second_token_adds_the_third_team_without_more_time() {
        $this->reset_alliances();
        $this->tokens(3);
        Alliance::form($this->db, 7, self::OWNED, 11, [12]);
        $this->assertNotNull(Alliance::extend($this->db, 7, self::OWNED), 'the third team comes first');
        $this->assertNull(Alliance::add_team($this->db, 7, self::OWNED, 13));
        $this->assertSame([[11, 12, 13], Alliance::HOUR], [Alliance::load($this->db, 7)->teamids, Alliance::load($this->db, 7)->seconds_left]);
        $this->assertNull(Alliance::extend($this->db, 7, self::OWNED));
        $this->assertSame(2 * Alliance::HOUR, Alliance::load($this->db, 7)->seconds_left);
        $this->assertSame(0, MonsterToken::balance($this->db, 7));
    }

    public function test_with_only_two_teams_a_token_adds_time() {
        $this->reset_alliances();
        $this->tokens(2);
        Alliance::form($this->db, 7, [11, 12], 11, [12]);
        $this->assertNull(Alliance::extend($this->db, 7, [11, 12]));
        $this->assertSame(2 * Alliance::HOUR, Alliance::load($this->db, 7)->seconds_left);
    }

    public function test_what_the_page_offers() {
        $this->reset_alliances();
        $this->tokens(1);
        $none = Alliance::options($this->db, 7, self::OWNED, 11);
        $this->assertSame([1, [12, 13], [], false], [$none['balance'], $none['can_form'], $none['can_add'], $none['can_extend']]);

        Alliance::form($this->db, 7, self::OWNED, 11, [12]);
        $this->tokens(1);
        $two = Alliance::options($this->db, 7, self::OWNED, 11);
        $this->assertSame([[], [13], false], [$two['can_form'], $two['can_add'], $two['can_extend']]);

        Alliance::add_team($this->db, 7, self::OWNED, 13);
        $this->tokens(1);
        $full = Alliance::options($this->db, 7, self::OWNED, 11);
        $this->assertSame([[], true, Alliance::HOUR], [$full['can_add'], $full['can_extend'], $full['extend_seconds']]);
    }

    // --- the clock ---

    public function test_fight_time_comes_off_only_for_fights_the_alliance_fought() {
        $this->reset_alliances();
        $this->tokens(1);
        Alliance::form($this->db, 7, self::OWNED, 11, [12]);
        Alliance::record_fight_time($this->db, 7, [13], 600);
        Alliance::record_fight_time($this->db, 7, [11], 600);
        $this->assertSame(Alliance::HOUR, Alliance::load($this->db, 7)->seconds_left, 'fights without the alliance cost nothing');
        Alliance::record_fight_time($this->db, 7, [11, 12], 600);
        $this->assertSame(Alliance::HOUR - 600, Alliance::load($this->db, 7)->seconds_left);
    }

    public function test_running_out_mid_fight_expires_it_and_keeps_the_overtime() {
        $this->reset_alliances();
        $this->tokens(1);
        Alliance::form($this->db, 7, self::OWNED, 11, [12]);
        Alliance::record_fight_time($this->db, 7, [11, 12], Alliance::HOUR + 420);

        $alliance = Alliance::load($this->db, 7);
        $this->assertSame([Alliance::EXPIRED, 0, 420], [$alliance->state, $alliance->seconds_left, $alliance->overtime]);
        $this->assertSame([11], Alliance::party_teamids($this->db, 7, 11, self::OWNED), 'the teams have left');
        $options = Alliance::options($this->db, 7, self::OWNED, 11);
        $this->assertSame([false, []], [$options['can_extend'], $options['can_form']], 'no token to extend with, and no fresh start');
    }

    public function test_extending_an_expired_alliance_takes_off_the_overtime() {
        $this->reset_alliances();
        $this->tokens(1);
        $this->tokens(1);
        Alliance::form($this->db, 7, self::OWNED, 11, [12, 13]);
        Alliance::record_fight_time($this->db, 7, [11, 12, 13], Alliance::HOUR + 420);
        $this->tokens(1);

        $this->assertSame(Alliance::HOUR - 420, Alliance::options($this->db, 7, self::OWNED, 11)['extend_seconds']);
        $this->assertNull(Alliance::extend($this->db, 7, self::OWNED));
        $alliance = Alliance::load($this->db, 7);
        $this->assertSame([Alliance::ACTIVE, Alliance::HOUR - 420, 0, [11, 12, 13]],
            [$alliance->state, $alliance->seconds_left, $alliance->overtime, $alliance->teamids]);
    }

    public function test_letting_the_teams_go_still_owes_the_overtime_on_the_next_alliance() {
        $this->reset_alliances();
        $this->tokens(1);
        Alliance::form($this->db, 7, self::OWNED, 11, [12]);
        Alliance::record_fight_time($this->db, 7, [11, 12], Alliance::HOUR + 300);
        $this->assertNotNull(Alliance::form($this->db, 7, self::OWNED, 11, [12]), 'extend or let go first');

        Alliance::end($this->db, 7);
        $this->assertSame([11], Alliance::party_teamids($this->db, 7, 11, self::OWNED));
        $this->tokens(1);
        $this->assertNull(Alliance::form($this->db, 7, self::OWNED, 11, [13]));
        $this->assertSame(Alliance::HOUR - 300, Alliance::load($this->db, 7)->seconds_left);
        $this->assertSame(0, Alliance::load($this->db, 7)->overtime);
    }

    public function test_a_team_no_longer_owned_drops_out_of_the_party() {
        $this->reset_alliances();
        $this->tokens(2);
        Alliance::form($this->db, 7, self::OWNED, 11, [12, 13]);
        $this->assertSame([11, 13], Alliance::party_teamids($this->db, 7, 11, [11, 13]));
    }
}
