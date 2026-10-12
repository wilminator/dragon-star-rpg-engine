<?php

/**
 * Housekeeping: clearing out expired and unneeded rows as the site is
 * used -- what goes, what stays, and how often it runs.
 */
trait HousekeepingPortabilityTests {

    private const DAY = 86400;

    private function reset_housekeeping(): void {
        $this->reset_tables(FIGHT::class, FightAction::class, FightPlayerState::class, FightSemaphore::class,
            PendingAction::class, FightSave::class, RateLimit::class, AccountToken::class, SecurityKey::class,
            AppSetting::class, TEAM::class, HERO::class);
    }

    // A fight between one player's team and an NPC, started $age seconds
    // ago; resolved unless $live. Returns its id.
    private function fight(int $age, bool $live = false): int {
        $character = CHARACTER::create_hero('Hero', 999, 1, null);
        $character->teamid = 5;
        $group = new GROUP();
        $group->add_hero($character);
        $hero = new PARTY();
        $hero->add($group);
        $npc = CHARACTER::create_hero('Target', 999, 1, null);
        $npc->teamid = 999;
        $npc_group = new GROUP();
        $npc_group->add_hero($npc);
        $other = new PARTY();
        $other->add($npc_group);
        $fight = new FIGHT('bg', 'music');
        $fight->add($hero);
        $fight->add($other);
        $fightid = FightCommunicator::start_fight($this->db, $fight, [['playerid' => 42, 'teamid' => 5, 'player_party' => 0]]);
        $this->db->prepare("UPDATE fights SET created_at = ? WHERE id = ?")->execute([time() - $age, $fightid]);
        if (!$live) {
            $this->db->prepare("UPDATE fight_semaphore SET state = ? WHERE fightid = ?")->execute([FightStatus::Resolved->value, $fightid]);
        }
        return $fightid;
    }

    private function fight_exists(int $fightid): bool {
        $stmt = $this->db->prepare("SELECT id FROM fights WHERE id = ?");
        $stmt->execute([$fightid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function rows(string $table, string $where = '1 = 1', array $params = []): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS n FROM $table WHERE $where");
        $stmt->execute($params);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['n'];
    }

    // --- account and login rows ---

    public function test_old_rate_limit_windows_go_and_current_ones_stay() {
        $this->reset_housekeeping();
        RateLimit::hit($this->db, 'old', 5, 3600, time() - 2 * self::DAY);
        RateLimit::hit($this->db, 'recent', 5, 3600, time() - 3600);

        $this->assertSame(1, Housekeeping::run($this->db)['rate_limits']);
        $this->assertSame(1, $this->rows('rate_limits', 'bucket = ?', ['recent']));
    }

    public function test_expired_tokens_go_and_live_ones_stay() {
        $this->reset_housekeeping();
        AccountToken::issue($this->db, 1, AccountToken::RESET, -10);
        $live = AccountToken::issue($this->db, 2, AccountToken::RESET, 3600);

        $this->assertSame(1, Housekeeping::run($this->db)['account_tokens']);
        $this->assertSame(2, AccountToken::peek($this->db, $live, AccountToken::RESET));
    }

    public function test_unconfirmed_security_keys_go_after_a_day() {
        $this->reset_housekeeping();
        $stale = SecurityKey::add_pending($this->db, 1, 'stale-id', 'pk', 0, 'Old key');
        SecurityKey::add_pending($this->db, 1, 'fresh-id', 'pk', 0, 'New key');
        $confirmed = SecurityKey::add_pending($this->db, 1, 'confirmed-id', 'pk', 0, 'Mine');
        $confirmed->activate($this->db);
        $this->db->prepare("UPDATE security_keys SET created_at = ? WHERE keyid IN (?, ?)")
            ->execute([time() - 2 * self::DAY, $stale->keyid, $confirmed->keyid]);

        $this->assertSame(1, Housekeeping::run($this->db)['security_keys']);
        $this->assertNull(SecurityKey::load($this->db, $stale->keyid));
        $this->assertNotNull(SecurityKey::load($this->db, $confirmed->keyid), 'a confirmed key stays, however old');
        $this->assertCount(2, SecurityKey::load_for($this->db, 1, include_pending: true));
    }

    // --- fights' leftover state ---

    public function test_a_resolved_fights_leftover_state_goes_after_a_day() {
        $this->reset_housekeeping();
        $old = $this->fight(2 * self::DAY);
        $recent = $this->fight(3600);
        $live = $this->fight(2 * self::DAY, live: true);
        foreach ([$old, $recent, $live] as $fightid) {
            (new PendingAction($fightid, 0, 0, 0, 42, 0, 0, [1, 0, 0]))->save($this->db);
        }

        $removed = Housekeeping::run($this->db);

        $this->assertSame([1, 1], [$removed['pending_actions'], $removed['semaphores']]);
        $this->assertNull(FightSemaphore::load($this->db, $old));
        $this->assertNotNull(FightSemaphore::load($this->db, $recent), 'just resolved: its players may still be on the results');
        $this->assertNotNull(FightSemaphore::load($this->db, $live));
        $this->assertSame(2, $this->rows('fight_pending_actions'));
        $this->assertTrue($this->fight_exists($old), 'the fight itself stays');
    }

    // --- fights nothing keeps ---

    public function test_fights_are_kept_for_good_unless_a_limit_is_set() {
        $this->reset_housekeeping();
        $fightid = $this->fight(400 * self::DAY);
        FightSave::delete_all_by_fightid($this->db, $fightid);

        $this->assertNull(Housekeeping::untagged_fight_days($this->db));
        $this->assertSame(0, Housekeeping::run($this->db)['fights']);
        $this->assertTrue($this->fight_exists($fightid));
    }

    public function test_only_old_resolved_fights_nothing_keeps_go() {
        $this->reset_housekeeping();
        $untagged = $this->fight(40 * self::DAY);
        $young = $this->fight(10 * self::DAY);
        $live = $this->fight(40 * self::DAY, live: true);
        $in_history = $this->fight(40 * self::DAY);
        $flagged = $this->fight(40 * self::DAY);
        $no_semaphore = $this->fight(40 * self::DAY);
        foreach ([$untagged, $young, $live, $flagged, $no_semaphore] as $fightid) {
            FightSave::delete_all_by_fightid($this->db, $fightid);
        }
        (new FightSave($in_history, 5, 'user'))->save($this->db);
        (new FightSave($flagged, 5, FightSave::ADMIN_TYPE))->save($this->db);
        FightSemaphore::delete($this->db, $no_semaphore);
        (new PendingAction($untagged, 0, 0, 0, 42, 0, 0, [1, 0, 0]))->save($this->db);
        Housekeeping::set_untagged_fight_days($this->db, 30);

        $this->assertSame(2, Housekeeping::count_untagged_fights($this->db, 30));
        $this->assertSame(2, Housekeeping::run($this->db)['fights']);

        $this->assertFalse($this->fight_exists($untagged));
        $this->assertFalse($this->fight_exists($no_semaphore), 'a fight whose semaphore was already purged is resolved');
        foreach (['fight_players', 'fight_actions', 'fight_pending_actions', 'fight_semaphore', 'fight_saves'] as $table) {
            $this->assertSame(0, $this->rows($table, 'fightid = ?', [$untagged]), $table);
        }
        foreach ([$young, $live, $in_history, $flagged] as $fightid) {
            $this->assertTrue($this->fight_exists($fightid));
        }
    }

    public function test_fights_go_a_batch_at_a_time_oldest_first() {
        $this->reset_housekeeping();
        $fightids = [];
        for ($i = 0; $i < 3; $i++) {
            $fightids[] = $fightid = $this->fight(40 * self::DAY);
            FightSave::delete_all_by_fightid($this->db, $fightid);
        }
        Housekeeping::set_untagged_fight_days($this->db, 30);
        $this->assertSame([$fightids[0], $fightids[1]], Housekeeping::find_untagged_fightids($this->db, time() - 30 * self::DAY, 2));
    }

    public function test_bad_fight_settings_are_refused() {
        $this->reset_housekeeping();
        $this->assertNotSame([], Housekeeping::set_untagged_fight_days($this->db, 0));
        $this->assertSame([], Housekeeping::set_untagged_fight_days($this->db, 90));
        $this->assertSame(90, Housekeeping::untagged_fight_days($this->db));
        $this->assertSame([], Housekeeping::set_untagged_fight_days($this->db, null));
        $this->assertNull(Housekeeping::untagged_fight_days($this->db));
    }

    // --- when it runs ---

    public function test_it_runs_at_most_once_per_interval_and_keeps_its_result() {
        $this->reset_housekeeping();
        $this->assertNull(Housekeeping::last_result($this->db));

        $this->assertIsArray(Housekeeping::maybe_run($this->db, 10000));
        $this->assertNull(Housekeeping::maybe_run($this->db, 10000 + Housekeeping::INTERVAL - 1));
        $this->assertIsArray(Housekeeping::maybe_run($this->db, 10000 + Housekeeping::INTERVAL));

        $last = Housekeeping::last_result($this->db);
        $this->assertSame(10000 + Housekeeping::INTERVAL, $last['at']);
        $this->assertSame(['rate_limits', 'account_tokens', 'security_keys', 'pending_actions', 'monster_seats', 'semaphores', 'fights'],
            array_keys($last['removed']));
    }
}
