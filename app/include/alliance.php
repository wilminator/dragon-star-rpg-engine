<?php
require_once INCLUDE_GAME . 'database.php';

/**
 * Tokens a player earns by fighting as the monsters, and spends on an
 * alliance (see Alliance). A player's tokens are a ledger: one row per
 * change, the balance their sum.
 *
 * Earning (award()): a fight played as the monsters earns 1, plus 1 more
 * for a win. At most DAILY_LIMIT are earned in any 24 hours -- but a
 * player still under it when a fight ends gets that fight's whole award,
 * so a winning fight can carry them to 4. Admins can grant tokens.
 */
final class MonsterToken {
    private static bool $table_verified = false;

    const DAILY_LIMIT = 3;
    const WINDOW_SECONDS = 86400;

    const PLAYED = 'played';
    const WON = 'won';
    const GRANTED = 'granted';
    const SPENT = 'spent';
    const EARNED = [self::PLAYED, self::WON];

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $id_column = $db->auto_increment_primary_key('id');
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS monster_tokens",
            <<<QUERY
CREATE TABLE IF NOT EXISTS monster_tokens (
  $id_column,
  userid int NOT NULL,
  amount int NOT NULL,
  reason varchar(16) NOT NULL,
  fightid int NULL,
  by_userid int NULL,
  created_at int NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public static function balance(Database $db, int $userid): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS n FROM monster_tokens WHERE userid = ?");
        $stmt->execute([$userid]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['n'];
    }

    // Tokens earned from fights in the 24 hours before $now.
    public static function earned_recently(Database $db, int $userid, ?int $now = null): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS n FROM monster_tokens
            WHERE userid = ? AND reason IN (?, ?) AND created_at > ?");
        $stmt->execute([$userid, self::PLAYED, self::WON, ($now ?? time()) - self::WINDOW_SECONDS]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['n'];
    }

    // A monster fight's tokens: 1 for playing it through ($played), 1 for
    // winning ($won). Nothing once DAILY_LIMIT have been earned in the
    // last 24 hours. Returns how many were given.
    public static function award(Database $db, int $userid, bool $played, bool $won, ?int $fightid, ?int $now = null): int {
        $now ??= time();
        if (self::earned_recently($db, $userid, $now) >= self::DAILY_LIMIT) {
            return 0;
        }
        $given = 0;
        foreach ([self::PLAYED => $played, self::WON => $won] as $reason => $earned) {
            if ($earned) {
                self::add($db, $userid, 1, $reason, $fightid, null, $now);
                $given++;
            }
        }
        return $given;
    }

    public static function grant(Database $db, int $userid, int $amount, int $admin_userid): void {
        if ($amount < 1) {
            throw new \InvalidArgumentException('Grant at least 1 token.');
        }
        self::add($db, $userid, $amount, self::GRANTED, null, $admin_userid, time());
    }

    // Takes $amount if the player has them. False, and nothing taken, if
    // not. Call inside the transaction that makes the purchase.
    public static function spend(Database $db, int $userid, int $amount): bool {
        if ($amount < 1 || self::balance($db, $userid) < $amount) {
            return false;
        }
        self::add($db, $userid, -$amount, self::SPENT, null, null, time());
        // A purchase racing this one could have spent the same tokens.
        return self::balance($db, $userid) >= 0;
    }

    private static function add(Database $db, int $userid, int $amount, string $reason, ?int $fightid, ?int $by, int $now): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare($db->insert_query('monster_tokens', ['userid', 'amount', 'reason', 'fightid', 'by_userid', 'created_at']));
        $stmt->execute([$userid, $amount, $reason, $fightid, $by, $now]);
    }
}

/**
 * A player's alliance: up to three of their own teams fighting as one
 * party, bought with tokens (MonsterToken).
 *
 *   - The first token brings a second team for an hour; a second token
 *     brings the third for that same hour. Spending two at once brings
 *     both.
 *   - Once every team the player has is in, each token adds an hour.
 *   - The hour is real time spent in fights (record_fight_time()),
 *     taken off when each fight ends. Teams only leave outside a fight:
 *     a fight that runs past the time finishes with everyone, and the
 *     alliance is then expired, with the overtime remembered.
 *   - Overtime comes off the next time bought -- an extension or a new
 *     alliance alike -- so playing past the hour isn't free time.
 *
 * One row per player in the alliances table. state: 'active', 'expired'
 * (time ran out; teams have left; the player can extend) or 'ended'
 * (let go; only the owed overtime is remembered).
 */
final class Alliance {
    private static bool $table_verified = false;

    const HOUR = 3600;
    const MAX_TEAMS = 3;

    const ACTIVE = 'active';
    const EXPIRED = 'expired';
    const ENDED = 'ended';

    public function __construct(
        public int $userid,
        public array $teamids,
        public int $seconds_left,
        public int $overtime,
        public string $state,
    ) {}

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS alliances",
            <<<QUERY
CREATE TABLE IF NOT EXISTS alliances (
  userid int NOT NULL PRIMARY KEY,
  teamids varchar(64) NOT NULL,
  seconds_left int NOT NULL,
  overtime int NOT NULL,
  state varchar(8) NOT NULL,
  updated_at int NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public static function load(Database $db, int $userid): ?Alliance {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM alliances WHERE userid = ?");
        $stmt->execute([$userid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $teamids = $row['teamids'] === '' ? [] : array_map('intval', explode(',', $row['teamids']));
        return new Alliance((int) $row['userid'], $teamids, (int) $row['seconds_left'], (int) $row['overtime'], $row['state']);
    }

    private function save(Database $db): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare($db->upsert_query('alliances', ['userid', 'teamids', 'seconds_left', 'overtime', 'state', 'updated_at'], 'userid'));
        $stmt->execute([$this->userid, implode(',', $this->teamids), $this->seconds_left, $this->overtime, $this->state, time()]);
    }

    public function is_active(): bool {
        return $this->state === self::ACTIVE;
    }

    // The teams to fight with when the player picks $lead_teamid: the
    // alliance's teams (those the player still owns, in $owned) while
    // it's active and includes $lead_teamid; otherwise that team alone.
    // The picked team leads.
    public static function party_teamids(Database $db, int $userid, int $lead_teamid, array $owned): array {
        $alliance = self::load($db, $userid);
        if ($alliance === null || !$alliance->is_active() || !in_array($lead_teamid, $alliance->teamids, true)) {
            return [$lead_teamid];
        }
        $others = array_values(array_filter($alliance->teamids,
            fn($id) => $id !== $lead_teamid && in_array($id, $owned, true)));
        return [$lead_teamid, ...$others];
    }

    // What the player can do now, for the page. $owned: every team the
    // player has (ids); $lead_teamid: the one they've picked.
    //   'balance'    tokens held
    //   'alliance'   ?Alliance
    //   'can_form'   teams that could join $lead_teamid in a new alliance
    //   'can_add'    teams that could join the active alliance
    //   'can_extend' whether a token would add time now
    //   'extend_seconds' how much it would add
    public static function options(Database $db, int $userid, array $owned, int $lead_teamid): array {
        $alliance = self::load($db, $userid);
        $balance = MonsterToken::balance($db, $userid);
        $others = array_values(array_filter($owned, fn($id) => $id !== $lead_teamid));
        $overtime = $alliance?->overtime ?? 0;
        $options = ['balance' => $balance, 'alliance' => $alliance, 'can_form' => [], 'can_add' => [],
            'can_extend' => false, 'extend_seconds' => max(0, self::HOUR - $overtime)];
        if ($alliance === null || $alliance->state === self::ENDED) {
            $options['can_form'] = $others;
        } elseif ($alliance->is_active()) {
            $options['can_add'] = count($alliance->teamids) < self::MAX_TEAMS
                ? array_values(array_filter($owned, fn($id) => !in_array($id, $alliance->teamids, true)))
                : [];
            $options['can_extend'] = $options['can_add'] === [];
            $options['extend_seconds'] = self::HOUR;
        } else {
            $options['can_extend'] = true;
        }
        if ($balance < 1) {
            $options['can_extend'] = false;
        }
        return $options;
    }

    // --- buying ---
    // Each returns null when done, or why not. Nothing is spent on a refusal.

    // A new alliance: $lead_teamid with one or two more of the player's
    // teams ($add), one token each, for an hour less any owed overtime.
    public static function form(Database $db, int $userid, array $owned, int $lead_teamid, array $add): ?string {
        $add = array_values(array_unique(array_map('intval', $add)));
        $existing = self::load($db, $userid);
        if ($existing !== null && $existing->state !== self::ENDED) {
            return $existing->is_active() ? 'You already have an alliance.' : 'Your alliance ran out of time: extend it or let the teams go first.';
        }
        if ($add === [] || count($add) > self::MAX_TEAMS - 1) {
            return 'Choose one or two teams to join you.';
        }
        foreach ([$lead_teamid, ...$add] as $teamid) {
            if (!in_array($teamid, $owned, true)) {
                return "That isn't one of your teams.";
            }
        }
        if (in_array($lead_teamid, $add, true)) {
            return 'A team can only join once.';
        }
        $overtime = $existing?->overtime ?? 0;
        return self::buy($db, $userid, count($add),
            new Alliance($userid, [$lead_teamid, ...$add], max(0, self::HOUR - $overtime), 0, self::ACTIVE));
    }

    // The third team, for the time already left.
    public static function add_team(Database $db, int $userid, array $owned, int $teamid): ?string {
        $alliance = self::load($db, $userid);
        if ($alliance === null || !$alliance->is_active()) {
            return "You don't have an alliance to add a team to.";
        }
        if (count($alliance->teamids) >= self::MAX_TEAMS) {
            return 'Your alliance is already full.';
        }
        if (!in_array($teamid, $owned, true) || in_array($teamid, $alliance->teamids, true)) {
            return "That team can't join.";
        }
        $alliance->teamids[] = $teamid;
        return self::buy($db, $userid, 1, $alliance);
    }

    // Another hour: for an active alliance once every team is in (or
    // there are no more to add), or to bring back an expired one --
    // less the overtime it ran.
    public static function extend(Database $db, int $userid, array $owned): ?string {
        $alliance = self::load($db, $userid);
        if ($alliance === null || $alliance->state === self::ENDED) {
            return "You don't have an alliance to extend.";
        }
        if ($alliance->is_active()) {
            $room = count($alliance->teamids) < self::MAX_TEAMS
                && array_diff($owned, $alliance->teamids) !== [];
            if ($room) {
                return 'Add your third team first; after that, tokens add time.';
            }
            $alliance->seconds_left += self::HOUR;
        } else {
            $alliance->seconds_left = max(0, self::HOUR - $alliance->overtime);
            $alliance->overtime = 0;
            $alliance->state = self::ACTIVE;
        }
        return self::buy($db, $userid, 1, $alliance);
    }

    // Lets the teams go. Owed overtime is kept for the next purchase.
    public static function end(Database $db, int $userid): void {
        $alliance = self::load($db, $userid);
        if ($alliance !== null) {
            $alliance->state = self::ENDED;
            $alliance->seconds_left = 0;
            $alliance->save($db);
        }
    }

    private static function buy(Database $db, int $userid, int $tokens, Alliance $result): ?string {
        // Tables first: creating one inside the transaction would commit
        // it early on MySQL (see Database::ensure_table_exists()).
        MonsterToken::ensure_table_exists($db);
        static::ensure_table_exists($db);
        $db->begin_transaction();
        try {
            if (!MonsterToken::spend($db, $userid, $tokens)) {
                $db->rollback();
                return $tokens === 1 ? "You don't have a token to spend." : "You need $tokens tokens for that.";
            }
            $result->save($db);
            $db->commit();
            return null;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    // --- the clock ---

    // Takes a finished fight's length off the player's alliance, if the
    // fight had at least two of its teams. Time running out makes it
    // expired, with the overtime kept.
    public static function record_fight_time(Database $db, int $userid, array $fight_teamids, int $seconds): void {
        $alliance = self::load($db, $userid);
        if ($alliance === null || !$alliance->is_active()
            || count(array_intersect($alliance->teamids, array_map('intval', $fight_teamids))) < 2) {
            return;
        }
        $alliance->seconds_left -= max(0, $seconds);
        if ($alliance->seconds_left <= 0) {
            $alliance->overtime = -$alliance->seconds_left;
            $alliance->seconds_left = 0;
            $alliance->state = self::EXPIRED;
        }
        $alliance->save($db);
    }
}
