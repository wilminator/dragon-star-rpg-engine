<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight.php';

/**
 * FightSemaphore wraps one row of the fight_semaphore table: the
 * coordination state for a single fight -- state, whose turn it is to
 * process (sequence), when it should be force-processed regardless of
 * confirmation from all clients (timeout), and who currently holds
 * exclusive rights to process it (lock_token/locked_by_playerid/
 * lock_expires_at).
 *
 * The successor to FIGHT_STORE's own concurrency handling
 * (LOCK TABLES), which doesn't port -- MySQL-specific syntax, and in
 * any case a table-wide lock is far coarser than this needs, since
 * only one fight's row should ever need to be contended over at a
 * time, not every fight simultaneously.
 *
 * Deliberately its own table rather than adding these columns to
 * fights -- two different classes both declaring DDL for a table by
 * the same name is exactly the collision already found once this
 * session (FIGHT vs FIGHT_STORE, both authoring conflicting schemas
 * for "fights"); a dedicated table avoids repeating it, the same way
 * FightAction and PendingAction each already got their own table
 * rather than folding into an existing one.
 *
 * Not a DbStoreTrait entity, despite having a single-column primary
 * key (fightid) unlike FightAction/PendingAction's composite keys:
 * that trait's save() is an unconditional upsert, but claim()/
 * extend()/release() are all *conditional* updates that can and
 * routinely do affect zero rows on purpose -- losing a race is the
 * normal, expected outcome for a losing caller, not an error -- a
 * shape DbStoreTrait's contract has no way to express. Manual
 * persistence via hand-written guarded UPDATEs instead, same
 * Database/PDO layer as everything else.
 *
 * Two separate timeouts live on this one row, worth not confusing:
 * timeout is the gameplay-pacing value (a game-design concern,
 * minutes-scale) -- how long to wait for player input before someone
 * should force this turn to process. lock_expires_at is
 * infrastructure reliability (seconds-scale) -- how long a thread that
 * claimed the processing job gets before it's presumed crashed and
 * another thread may take over. Related (crossing the gameplay
 * timeout is typically what prompts an attempt to claim), but distinct
 * fields with distinct lifetimes, each managed independently.
 *
 * Both timeout and lock_expires_at are stored as unix timestamps
 * (plain ints), not DATETIME -- sidesteps DATETIME format/timezone
 * differences across MySQL/Postgres/SQLite entirely, and every
 * comparison becomes a plain integer comparison rather than a
 * dialect-specific date function. Every "N minutes/seconds from now"
 * value is computed in PHP (time() + ...) and bound directly, for the
 * same reason -- no SQL-side date arithmetic (MySQL's adddate()/now()
 * included) to keep portable.
 */
class FightSemaphore {
    private static bool $table_verified = false;

    public int $fightid;
    public FightStatus $state;
    public int $sequence;
    public int $timeout;
    public ?string $lock_token;
    public ?int $locked_by_playerid;
    public ?int $lock_expires_at;
    // Non-admins may spectate this fight while it's live. Lives here,
    // not on the fight, so it disappears with the semaphore once the
    // fight resolves; after that, a replay link works for anyone.
    public bool $spectatable;

    public function __construct(
        int $fightid,
        FightStatus $state,
        int $sequence,
        int $timeout,
        ?string $lock_token = null,
        ?int $locked_by_playerid = null,
        ?int $lock_expires_at = null,
        bool $spectatable = false
    ) {
        $this->fightid = $fightid;
        $this->state = $state;
        $this->sequence = $sequence;
        $this->timeout = $timeout;
        $this->lock_token = $lock_token;
        $this->locked_by_playerid = $locked_by_playerid;
        $this->lock_expires_at = $lock_expires_at;
        $this->spectatable = $spectatable;
    }

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_semaphore",
            <<<QUERY
CREATE TABLE IF NOT EXISTS fight_semaphore (
  fightid int NOT NULL,
  state int NOT NULL,
  sequence int NOT NULL,
  timeout int NOT NULL,
  lock_token varchar(32) NULL,
  locked_by_playerid int NULL,
  lock_expires_at int NULL,
  spectatable int NOT NULL DEFAULT 0,
  PRIMARY KEY (fightid)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Called once, alongside a fight's own creation. state starts
    // WaitingForInput, sequence 0, no lock held. The caller decides the
    // deadline ($timeout_seconds from now); this just stores it.
    public static function create(Database $db, int $fightid, int $timeout_seconds, bool $spectatable = false): FightSemaphore {
        static::ensure_table_exists($db);
        $semaphore = new FightSemaphore(
            $fightid,
            FightStatus::WaitingForInput,
            0,
            time() + $timeout_seconds,
            spectatable: $spectatable,
        );
        $stmt = $db->prepare("INSERT INTO fight_semaphore (fightid, state, sequence, timeout, spectatable) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$fightid, $semaphore->state->value, $semaphore->sequence, $semaphore->timeout, (int) $spectatable]);
        return $semaphore;
    }

    // A plain upsert -- deliberately the only mutation method on this
    // class with no concurrency guard of any kind, unlike every other
    // one (create() only ever inserts a fresh row; claim()/release()
    // are lock_token-guarded; tighten_timeout() is a one-directional
    // LEAST()). This exists for exactly one purpose: restoring a
    // previously-exported row wholesale during a database import,
    // where the value being written is already a complete, trusted
    // snapshot and there is no concurrent gameplay to race against by
    // construction (the whole table was just dropped and recreated
    // moments before). Never call this from anything touching a fight
    // currently being played -- every real gameplay path must keep
    // going through create()/claim()/release(), which is what actually
    // keeps two simultaneous requests from processing the same round
    // twice.
    public function save(Database $db): void {
        static::ensure_table_exists($db);
        $columns = ['fightid', 'state', 'sequence', 'timeout', 'lock_token', 'locked_by_playerid', 'lock_expires_at', 'spectatable'];
        $query = $db->upsert_query('fight_semaphore', $columns, ['fightid']);
        $stmt = $db->prepare($query);
        $stmt->execute([
            $this->fightid,
            $this->state->value,
            $this->sequence,
            $this->timeout,
            $this->lock_token,
            $this->locked_by_playerid,
            $this->lock_expires_at,
            (int) $this->spectatable,
        ]);
    }

    // The cheap, no-FIGHT-hydration peek this class exists to make
    // possible: a single-row, primary-key lookup, nothing else
    // touched. Meant to be hit frequently -- once per connected
    // player, per poll -- without that cost growing with a fight's
    // complexity the way loading the full FIGHT object would.
    public static function load(Database $db, int $fightid): ?FightSemaphore {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_semaphore WHERE fightid = ?");
        $stmt->execute([$fightid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::from_row($row);
    }

    // Every fight's semaphore row, across the whole table -- for the
    // database export/import work in progress. Keyed by fightid, not
    // a plain list like FightAction::load_all_from_database()/
    // FightSave::load_all_from_database(): fight_semaphore genuinely
    // has one row per fight (fightid is its whole primary key), unlike
    // those two tables where a single fight can have many rows.
    public static function load_all_from_database(Database $db): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_semaphore ORDER BY fightid");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_combine(
            array_column($rows, 'fightid'),
            array_map(fn($row) => static::from_row($row), $rows)
        );
    }

    public function is_timed_out(): bool {
        return time() >= $this->timeout;
    }

    public function seconds_remaining(): int {
        return $this->timeout - time();
    }

    // Attempts to claim exclusive rights to process this fight's
    // current turn -- either a fresh claim (state is WaitingForInput)
    // or taking over an abandoned one (state is Processing, but
    // whoever claimed it let lock_expires_at pass without extending or
    // releasing, so is presumed crashed). Both cases are one guarded,
    // atomic UPDATE; a caller can't distinguish which happened, and
    // doesn't need to. Returns the newly-claimed semaphore on success,
    // null if another thread already holds a live claim -- the normal,
    // expected outcome for a losing caller, not an error.
    //
    // lock_token is the actual proof of ownership, checked by every
    // subsequent extend()/release() -- a fresh random value per
    // attempt, never reused. locked_by_playerid rides along for admin
    // visibility only (who's holding a stuck fight); it's never part
    // of the guard itself, so two concurrent claim attempts from the
    // same player still can't both win.
    public static function claim(Database $db, int $fightid, int $playerid, int $lock_duration_seconds): ?FightSemaphore {
        static::ensure_table_exists($db);
        $token = bin2hex(random_bytes(16));
        $now = time();
        $expires_at = $now + $lock_duration_seconds;
        $stmt = $db->prepare("
            UPDATE fight_semaphore
            SET state = ?, lock_token = ?, locked_by_playerid = ?, lock_expires_at = ?
            WHERE fightid = ?
              AND (state = ? OR (state = ? AND lock_expires_at < ?))
        ");
        $stmt->execute([
            FightStatus::Processing->value, $token, $playerid, $expires_at,
            $fightid,
            FightStatus::WaitingForInput->value, FightStatus::Processing->value, $now,
        ]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        return static::load($db, $fightid);
    }

    // The heartbeat: a thread that's still actively processing proves
    // it's alive by pushing lock_expires_at forward, using the token
    // from its own successful claim() as proof it's still the rightful
    // holder. Returns false if that token no longer matches this row
    // -- meaning this thread's claim already expired and was taken
    // over by someone else, the signal for this thread to abandon its
    // own work rather than push forward and step on whoever holds the
    // lock now.
    public function extend(Database $db, int $lock_duration_seconds): bool {
        $expires_at = time() + $lock_duration_seconds;
        $stmt = $db->prepare("UPDATE fight_semaphore SET lock_expires_at = ? WHERE fightid = ? AND lock_token = ?");
        $stmt->execute([$expires_at, $this->fightid, $this->lock_token]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->lock_expires_at = $expires_at;
        return true;
    }

    // Ends this thread's claim, transitioning to whatever comes next
    // ($new_state -- WaitingForInput for another round, Resolved if
    // the fight just ended), advancing sequence, and resetting timeout
    // for the new round. Same lock_token guard as extend(): returns
    // false, rather than corrupting a newer claim, if this thread's
    // token no longer matches -- its claim already expired and was
    // taken over before it finished.
    public function release(Database $db, FightStatus $new_state, int $new_sequence, int $timeout_seconds): bool {
        $new_timeout = time() + $timeout_seconds;
        $stmt = $db->prepare("
            UPDATE fight_semaphore
            SET state = ?, sequence = ?, timeout = ?, lock_token = NULL, locked_by_playerid = NULL, lock_expires_at = NULL
            WHERE fightid = ? AND lock_token = ?
        ");
        $stmt->execute([$new_state->value, $new_sequence, $new_timeout, $this->fightid, $this->lock_token]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->state = $new_state;
        $this->sequence = $new_sequence;
        $this->timeout = $new_timeout;
        $this->lock_token = null;
        $this->locked_by_playerid = null;
        $this->lock_expires_at = null;
        return true;
    }

    // Shrinks timeout toward $candidate_timeout, never extends it --
    // matches FIGHT_STORE::update_timeout()'s existing "only ever
    // tighten" semantics exactly. Doesn't require holding the lock:
    // unlike claim()/extend()/release(), this is something any player
    // can trigger at any time (see FightCommunicator::
    // acknowledge_playback()), not part of who gets to process the turn.
    //
    // Explicit PDO::PARAM_INT binding, not the usual array-form
    // execute(): confirmed directly that SQLite's type-affinity rules
    // treat any string-bound parameter as greater than any integer, so
    // with the default string binding array-form execute() uses,
    // MIN(timeout, ?) would always silently return timeout's own,
    // unchanged value -- the parameter's actual numeric size never
    // enters into it, comparing as text against a number always loses.
    public static function tighten_timeout(Database $db, int $fightid, int $candidate_timeout): void {
        static::ensure_table_exists($db);
        $least = $db->least('timeout', '?');
        $stmt = $db->prepare("UPDATE fight_semaphore SET timeout = $least WHERE fightid = ?");
        $stmt->bindValue(1, $candidate_timeout, PDO::PARAM_INT);
        $stmt->bindValue(2, $fightid, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function from_row(array $row): FightSemaphore {
        return new FightSemaphore(
            (int) $row['fightid'],
            FightStatus::from((int) $row['state']),
            (int) $row['sequence'],
            (int) $row['timeout'],
            $row['lock_token'],
            $row['locked_by_playerid'] === null ? null : (int) $row['locked_by_playerid'],
            $row['lock_expires_at'] === null ? null : (int) $row['lock_expires_at'],
            (bool) ($row['spectatable'] ?? 0),
        );
    }

    // Deletes this fight's one semaphore row outright -- see
    // FightAction::delete_all_by_fightid()'s own docblock for why this
    // exists at all (system-generated simulated fights, genuinely
    // thrown away and regenerated, unlike any real player-owned
    // fight).
    public static function delete(Database $db, int $fightid): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM fight_semaphore WHERE fightid = ?");
        $stmt->execute([$fightid]);
    }

    // A fight's coordination state (state/sequence/timeout/lock) only
    // means anything while it's still being played -- once Resolved,
    // none of it is consulted again by anything, and it has no place
    // in a database export. This is the maintenance sweep that
    // reflects that: deletes every already-Resolved row, leaving
    // active fights' rows untouched. Returns the number removed.
    //
    // Ordering matters if PendingAction::purge_resolved() is also
    // being run: that method's own subquery identifies which fights
    // are Resolved by querying this table, so it must run BEFORE this
    // one -- once a fight's semaphore row is gone, there's no longer
    // any way to tell whether its now-orphaned pending-action rows
    // belonged to a Resolved fight or an active one. See
    // FightCommunicator::purge_ephemeral_state(), which runs both in
    // the correct order rather than leaving that ordering to the
    // caller to remember.
    public static function purge_resolved(Database $db): int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM fight_semaphore WHERE state = ?");
        $stmt->execute([FightStatus::Resolved->value]);
        return $stmt->rowCount();
    }
}
