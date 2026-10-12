<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'app_setting.php';
require_once INCLUDE_GAME . 'rate_limit.php';
require_once INCLUDE_GAME . 'account_token.php';
require_once INCLUDE_GAME . 'security_key.php';
require_once INCLUDE_GAME . 'fight_communicator.php';

/**
 * Clears out rows that have outlived their use, a little at a time, as
 * the site is used -- no cron. At most once per INTERVAL, after a
 * request's response has gone out (see after_response()), one run:
 *
 *   rate_limits      windows that ended more than a day ago (the
 *                    longest window in use is an hour)
 *   account_tokens   expired one-time links and pending 2FA changes
 *   security_keys    registrations never confirmed from the email,
 *                    after a day
 *   fight_pending_actions, monster_seats, fight_semaphore
 *                    a resolved fight's mid-fight state, a day after the
 *                    fight started. Replays don't need it: a fight's own
 *                    record says whether it's open to spectators.
 *   fights           resolved fights no one keeps -- no player history,
 *                    retain, highlight or admin flag -- once older than
 *                    the admin's setting. Off unless set (see
 *                    set_untagged_fight_days()): it removes the fight
 *                    for good, and its replay link stops working. At
 *                    most FIGHTS_PER_RUN per run.
 *
 * admin/housekeeping.php shows the last run and holds the fight setting.
 */
final class Housekeeping {
    const INTERVAL = 600;
    const RAN_AT = 'housekeeping_ran_at';
    const LAST_RESULT = 'housekeeping_last_result';
    const UNTAGGED_FIGHT_DAYS = 'housekeeping.untagged_fight_days';

    const RATE_LIMIT_AGE = 86400;
    const EPHEMERAL_FIGHT_AGE = 86400;
    const FIGHTS_PER_RUN = 100;

    private static bool $scheduled = false;
    private static bool $enabled = true;

    // Off for tests, which run many "requests" in one process.
    public static function set_after_response_enabled(bool $enabled): void {
        self::$enabled = $enabled;
    }

    // Runs maybe_run() once this request is done, after the response has
    // been sent where the server allows it (PHP-FPM), so no player waits
    // on it. Never throws: a failure is logged.
    public static function after_response(Database $db): void {
        if (self::$scheduled || !self::$enabled) {
            return;
        }
        self::$scheduled = true;
        register_shutdown_function(function () use ($db) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::maybe_run($db);
            } catch (\Throwable $e) {
                log_error($e);
            }
        });
    }

    // run(), unless one ran within INTERVAL. Null when skipped.
    public static function maybe_run(Database $db, ?int $now = null): ?array {
        $now ??= time();
        $last = (int) (AppSetting::get($db, self::RAN_AT) ?? 0);
        if ($last + self::INTERVAL > $now) {
            return null;
        }
        // Claimed first, so requests arriving meanwhile don't pile in.
        AppSetting::set($db, self::RAN_AT, (string) $now);
        return self::run($db, $now);
    }

    // Every task once. [task => rows removed]; also kept as the last
    // result, with when it ran.
    public static function run(Database $db, ?int $now = null): array {
        $now ??= time();
        $removed = [
            'rate_limits' => self::delete($db, "DELETE FROM rate_limits WHERE window_start < ?", [$now - self::RATE_LIMIT_AGE], RateLimit::class),
            'account_tokens' => self::delete($db, "DELETE FROM account_tokens WHERE expires_at < ?", [$now], AccountToken::class),
            'security_keys' => self::delete($db, "DELETE FROM security_keys WHERE active = 0 AND created_at < ?", [$now - 86400], SecurityKey::class),
        ];
        $removed += self::purge_fight_state($db, $now - self::EPHEMERAL_FIGHT_AGE);
        $removed['fights'] = self::purge_untagged_fights($db, $now);
        AppSetting::set($db, self::LAST_RESULT, json_encode(['at' => $now, 'removed' => $removed]));
        return $removed;
    }

    // ['at' => int, 'removed' => [task => n]], or null if none has run.
    public static function last_result(Database $db): ?array {
        $value = AppSetting::get($db, self::LAST_RESULT);
        $decoded = $value === null ? null : json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function delete(Database $db, string $query, array $params, string $table_class): int {
        $table_class::ensure_table_exists($db);
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // --- fights ---

    // A resolved fight's pending actions and semaphore, for fights
    // started before $started_before. Pending actions first: they're
    // found through the semaphore (see PendingAction::purge_resolved()).
    private static function purge_fight_state(Database $db, int $started_before): array {
        FIGHT::ensure_table_exists($db);
        FightSemaphore::ensure_table_exists($db);
        PendingAction::ensure_table_exists($db);
        $old_resolved = "SELECT fight_semaphore.fightid FROM fight_semaphore JOIN fights ON fights.id = fight_semaphore.fightid
                         WHERE fight_semaphore.state = ? AND fights.created_at < ?";
        $params = [FightStatus::Resolved->value, $started_before];
        $stmt = $db->prepare("DELETE FROM fight_pending_actions WHERE fightid IN (SELECT fightid FROM ($old_resolved) AS due)");
        $stmt->execute($params);
        $pending = $stmt->rowCount();
        // Monster seats: settled when the fight ended (see MonsterSide).
        MonsterSeat::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM monster_seats WHERE fightid IN (SELECT fightid FROM ($old_resolved) AS due)");
        $stmt->execute($params);
        $seats = $stmt->rowCount();
        $stmt = $db->prepare("DELETE FROM fight_semaphore WHERE fightid IN (SELECT fightid FROM ($old_resolved) AS due)");
        $stmt->execute($params);
        return ['pending_actions' => $pending, 'monster_seats' => $seats, 'semaphores' => $stmt->rowCount()];
    }

    // Null: kept for good (the default).
    public static function untagged_fight_days(Database $db): ?int {
        $value = AppSetting::get($db, self::UNTAGGED_FIGHT_DAYS);
        return $value === null || $value === '' ? null : (int) $value;
    }

    // The reasons nothing was saved; [] when saved.
    public static function set_untagged_fight_days(Database $db, ?int $days): array {
        if ($days !== null && $days < 1) {
            return ['Keep fights for at least 1 day, or leave it blank to keep them for good.'];
        }
        if ($days === null) {
            AppSetting::delete($db, self::UNTAGGED_FIGHT_DAYS);
        } else {
            AppSetting::set($db, self::UNTAGGED_FIGHT_DAYS, (string) $days);
        }
        return [];
    }

    // Resolved fights (or fights whose semaphore has already been
    // purged) started before $started_before that carry no FightSave tag
    // at all, oldest first.
    public static function find_untagged_fightids(Database $db, int $started_before, int $limit): array {
        FIGHT::ensure_table_exists($db);
        FightSemaphore::ensure_table_exists($db);
        FightSave::ensure_table_exists($db);
        $limit = max(1, $limit);
        $stmt = $db->prepare(
            "SELECT fights.id FROM fights
             LEFT JOIN fight_semaphore ON fight_semaphore.fightid = fights.id
             WHERE fights.created_at < ?
               AND (fight_semaphore.fightid IS NULL OR fight_semaphore.state = ?)
               AND NOT EXISTS (SELECT 1 FROM fight_saves WHERE fight_saves.fightid = fights.id)
             ORDER BY fights.id LIMIT $limit"
        );
        $stmt->execute([$started_before, FightStatus::Resolved->value]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    // How many fights the setting would remove now (all of them, over as
    // many runs as it takes).
    public static function count_untagged_fights(Database $db, int $days, ?int $now = null): int {
        return count(self::find_untagged_fightids($db, ($now ?? time()) - $days * 86400, PHP_INT_MAX >> 1));
    }

    private static function purge_untagged_fights(Database $db, int $now): int {
        $days = self::untagged_fight_days($db);
        if ($days === null) {
            return 0;
        }
        $fightids = self::find_untagged_fightids($db, $now - $days * 86400, self::FIGHTS_PER_RUN);
        foreach ($fightids as $fightid) {
            FightCommunicator::delete_fight($db, $fightid);
        }
        return count($fightids);
    }
}
