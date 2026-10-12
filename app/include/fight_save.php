<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight_semaphore.php';

/**
 * FightSave wraps one row of the fight_saves table: a tag on a fight,
 * optionally with a precomputed power_ratio for quickly finding
 * fights worth classifying as an underdog win or an overkill.
 *
 * Keyed by teamid, not playerid: a player can own more than one team
 * (TEAM::load_all_by_playerid() returns an array, not a single row),
 * and keying by playerid would mean all of a player's teams share one
 * pool -- a 25-fight rolling history, a 10-fight retain cap -- so a
 * player with two active teams would see one team's fights crowd out
 * the other's. Keying by teamid gives each team its own independent
 * history and cap, the equal treatment a player's teams should get
 * from each other. System-generated simulated fights use the sentinel
 * teamid 0, the same way playerid 0 worked before this rename --
 * never a real team's id, since real teamids are assigned starting
 * from 1.
 *
 * Re-aligned from the table's original shape (which nothing in the
 * codebase actually read or wrote -- confirmed directly, the only
 * references anywhere were FIGHT_STORE's own DDL and its LOCK TABLES
 * statement, never a real INSERT/SELECT/UPDATE): the original primary
 * key was (fightid, playerid), meaning one fight could carry at most
 * one tag per player. That's too narrow for how this is actually
 * meant to be used -- a single fight can genuinely need more than one
 * tag at once (a system-generated fight tagged both 'user', marking
 * it as generated content, and separately 'underdog', a classification
 * of its outcome) -- so type joins the primary key too:
 * (fightid, teamid, type).
 *
 * type is a plain string here, not an enum: 'retain', 'user',
 * 'underdog', 'overkill' are the four values in active use as of this
 * writing, but nothing about this class enforces that set -- matches
 * the portable-DDL convention already used elsewhere in this codebase
 * (varchar over MySQL-specific enum()) and leaves room for a type this
 * class doesn't need to already know about.
 *
 * power_ratio: winning party's total starting pxp divided by every
 * other (losing) party's combined starting pxp -- computed once, at
 * save time, from the live FIGHT object that just resolved, not
 * recomputed by re-hydrating and re-deriving it later. A low ratio
 * (winner started weaker than everyone else combined) is underdog
 * material; a high one (winner started far stronger) is overkill
 * material -- the actual thresholds for either classification are a
 * judgment call for whatever curates these, not something this class
 * decides. Nullable: not every tag needs one (a plain 'retain' tag,
 * say, has no outcome-classification purpose at all).
 *
 * 'admin' (ADMIN_TYPE) is an admin's retention flag: unlimited, and
 * never shown in the team's own fight history, which lists only the
 * PLAYER_TYPES (see load_player_history_fightids()).
 *
 * A fight can only be tagged once it's Resolved -- enforced in save()
 * itself, not left to callers to check first. A tag on a fight that's
 * still being played makes no sense on its own terms (what would
 * 'retain' or 'underdog' even mean for an outcome that hasn't happened
 * yet?), and enforcing it here means every future caller gets this for
 * free rather than needing to remember it -- including the database
 * import path, which is exactly why FightSemaphore's own restore must
 * run before FightSave's during an import (see import_db.php).
 *
 * Not a DbStoreTrait entity: composite primary key
 * (fightid, teamid, type), same situation as FightAction/PendingAction.
 */
class FightSave {
    private static bool $table_verified = false;

    // Tags the game gives a team's fights on the player's behalf: the
    // rolling history, their own retains, and highlights. These make up
    // the team's fight history.
    const PLAYER_TYPES = ['user', 'retain', 'underdog', 'overkill'];
    // An admin's retention flag (see FightBrowser::set_flag()).
    const ADMIN_TYPE = 'admin';

    public int $fightid;
    public int $teamid;
    public string $type;
    public ?float $power_ratio;

    public function __construct(int $fightid, int $teamid, string $type, ?float $power_ratio = null) {
        $this->fightid = $fightid;
        $this->teamid = $teamid;
        $this->type = $type;
        $this->power_ratio = $power_ratio;
    }

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_saves",
            <<<QUERY
CREATE TABLE IF NOT EXISTS fight_saves (
  fightid int NOT NULL,
  teamid int NOT NULL,
  type varchar(10) NOT NULL,
  power_ratio float NULL,
  PRIMARY KEY (fightid, teamid, type)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Throws if $this->fightid isn't Resolved -- see this class's own
    // docblock for why. A fight with no FightSemaphore row counts as
    // Resolved as long as the fight itself exists: semaphores are
    // purged once fights resolve (FightSemaphore::purge_resolved()),
    // and an admin may flag a fight long after that. With neither a
    // semaphore nor a fight row, there's nothing to tag.
    public function save(Database $db): void {
        static::ensure_table_exists($db);
        $semaphore = FightSemaphore::load($db, $this->fightid);
        $resolved = $semaphore === null
            ? static::fight_exists($db, $this->fightid)
            : $semaphore->state === FightStatus::Resolved;
        if (!$resolved) {
            throw new \Exception("Cannot save a FightSave tag for fightid {$this->fightid}: fight is not Resolved (or doesn't exist).");
        }
        $columns = ['fightid', 'teamid', 'type', 'power_ratio'];
        $query = $db->upsert_query('fight_saves', $columns, ['fightid', 'teamid', 'type']);
        $stmt = $db->prepare($query);
        $stmt->execute([$this->fightid, $this->teamid, $this->type, $this->power_ratio]);
    }

    private static function fight_exists(Database $db, int $fightid): bool {
        FIGHT::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT id FROM fights WHERE id = ?");
        $stmt->execute([$fightid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    // The team's fight history, newest first: every fight carrying one
    // of the PLAYER_TYPES tags for it. A fight only an admin has flagged
    // isn't included.
    public static function load_player_history_fightids(Database $db, int $teamid): array {
        static::ensure_table_exists($db);
        $placeholders = implode(',', array_fill(0, count(self::PLAYER_TYPES), '?'));
        $stmt = $db->prepare("SELECT DISTINCT fightid FROM fight_saves WHERE teamid = ? AND type IN ($placeholders) ORDER BY fightid DESC");
        $stmt->execute([$teamid, ...self::PLAYER_TYPES]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'fightid'));
    }

    public static function delete(Database $db, int $fightid, int $teamid, string $type): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM fight_saves WHERE fightid = ? AND teamid = ? AND type = ?");
        $stmt->execute([$fightid, $teamid, $type]);
    }

    public static function load(Database $db, int $fightid, int $teamid, string $type): ?FightSave {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_saves WHERE fightid = ? AND teamid = ? AND type = ?");
        $stmt->execute([$fightid, $teamid, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : static::from_row($row);
    }

    // Every tag on one fight, regardless of type or teamid.
    public static function load_all_by_fightid(Database $db, int $fightid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_saves WHERE fightid = ?");
        $stmt->execute([$fightid]);
        return array_map(fn($row) => static::from_row($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Every tag, across every fight and team -- for the database
    // export. Plain, unindexed list, not fightid => object: a fight
    // can carry more than one tag (that's this whole class's own
    // re-alignment), so fightid alone was never a unique key here.
    public static function load_all_from_database(Database $db): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM fight_saves ORDER BY fightid, teamid, type");
        $stmt->execute();
        return array_map(fn($row) => static::from_row($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Every fight carrying a given tag, for a given team -- the
    // lookup a "browse my retained fights" or "browse system-generated
    // fights" listing needs. $min_power_ratio/$max_power_ratio: the
    // quick underdog/overkill query this class exists to make cheap --
    // a plain indexed-column range filter, not a join or a recompute
    // against every candidate fight's actual state.
    public static function load_all_by_type(
        Database $db,
        int $teamid,
        string $type,
        ?float $min_power_ratio = null,
        ?float $max_power_ratio = null
    ): array {
        static::ensure_table_exists($db);
        $query = "SELECT * FROM fight_saves WHERE teamid = ? AND type = ?";
        $params = [$teamid, $type];
        if ($min_power_ratio !== null) {
            $query .= " AND power_ratio >= ?";
            $params[] = $min_power_ratio;
        }
        if ($max_power_ratio !== null) {
            $query .= " AND power_ratio <= ?";
            $params[] = $max_power_ratio;
        }
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return array_map(fn($row) => static::from_row($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // The power_ratio this class's own docblock defines: the winning
    // party's starting pxp divided by every other party's combined
    // starting pxp. $fight must still have every party's starting
    // stats intact (call this before anything downstream has a chance
    // to mutate them) and $winning_party_index identified by the
    // caller -- this method doesn't itself decide who won, only
    // computes the ratio once that's known. Returns null if there's
    // no meaningful ratio to compute (every other party already had
    // zero combined pxp -- division by zero, not a real "infinite
    // overkill").
    public static function compute_power_ratio(FIGHT $fight, int $winning_party_index): ?float {
        $winning_pxp = $fight->parties[$winning_party_index]->get_pxp();
        $other_pxp = 0;
        foreach ($fight->parties as $index => $party) {
            if ($index === $winning_party_index) continue;
            $other_pxp += $party->get_pxp();
        }
        if ($other_pxp <= 0) {
            return null;
        }
        return $winning_pxp / $other_pxp;
    }

    private static function from_row(array $row): FightSave {
        return new FightSave(
            (int) $row['fightid'],
            (int) $row['teamid'],
            $row['type'],
            $row['power_ratio'] === null ? null : (float) $row['power_ratio'],
        );
    }

    // Adds $fightid to $teamid's rolling 'user' history -- their most
    // recent completed fights, for review/browsing (and the pool they
    // pick 'retain' candidates from). Called once per fight a team
    // finishes, unconditionally (the fight must already be Resolved --
    // see save()). Always keeps the most recent 25: once adding this
    // one pushes the count past that, the oldest is evicted -- "oldest"
    // meaning lowest fightid, since fights are always created with
    // increasing, auto-incrementing ids and never manually reordered,
    // so fightid order is chronological order.
    public static function record_user_history(Database $db, int $fightid, int $teamid): void {
        (new FightSave($fightid, $teamid, 'user'))->save($db);
        $all = static::load_all_by_type($db, $teamid, 'user');
        if (count($all) <= 25) {
            return;
        }
        usort($all, fn($a, $b) => $a->fightid <=> $b->fightid);
        $excess = array_slice($all, 0, count($all) - 25);
        foreach ($excess as $stale) {
            static::delete($db, $stale->fightid, $teamid, 'user');
        }
    }

    // Player-initiated (on the team's behalf): tags $fightid as one of
    // up to 10 fights $teamid wants to keep permanently, unlike
    // record_user_history()'s automatic rolling window. Returns false,
    // tagging nothing, if they already have 10 and this fight isn't
    // already among them -- the caller decides what to do about that
    // (ask them to untag one first, say); this never silently evicts
    // anything on the team's behalf the way the 'user' window does.
    public static function retain(Database $db, int $fightid, int $teamid): bool {
        $existing = static::load_all_by_type($db, $teamid, 'retain');
        $already_retained = array_any($existing, fn($s) => $s->fightid === $fightid);
        if (count($existing) >= 10 && !$already_retained) {
            return false;
        }
        (new FightSave($fightid, $teamid, 'retain'))->save($db);
        return true;
    }

    public static function unretain(Database $db, int $fightid, int $teamid): void {
        static::delete($db, $fightid, $teamid, 'retain');
    }

    // Shared by consider_for_underdog()/consider_for_overkill() below:
    // both are singletons (at most one row per team per type, enforced
    // here, never two competing for the title at once) that only ever
    // change by being replaced outright when a new fight's value beats
    // the current holder's -- never by editing power_ratio in place,
    // so the fightid always points at the fight that earned the
    // record, not a stale one carrying an updated number.
    private static function replace_if_bigger(Database $db, int $fightid, int $teamid, string $type, float $value): void {
        $existing = static::load_all_by_type($db, $teamid, $type);
        if (count($existing) > 0 && $existing[0]->power_ratio !== null && $existing[0]->power_ratio >= $value) {
            return;
        }
        if (count($existing) > 0) {
            static::delete($db, $existing[0]->fightid, $teamid, $type);
        }
        (new FightSave($fightid, $teamid, $type, $value))->save($db);
    }

    // Considers a just-resolved fight for $teamid's single 'underdog'
    // record: the biggest pxp gap ($npc_pxp / $player_party_pxp --
    // note this is the inverse of compute_power_ratio()'s own
    // winner/others convention, since here the point is exactly how
    // much stronger the side that LOST was) they've ever overcome to
    // win.
    //
    // FightCommunicator::record_highlights() calls this for each winning
    // team when a fight ends. Eligibility is the caller's to determine,
    // not re-derived here:
    // this assumes the caller has already confirmed the fight was
    // PvE, that $teamid's own party actually won, and specifically
    // that it was player_party index 0 -- a party that joined a fight
    // already in progress, or a second player party in the same
    // fight, doesn't qualify. Walking in mid-fight doesn't face the
    // fight's true starting gap, and letting a second party's own
    // result count would make the record gameable (deliberately
    // losing with one party to inflate another's apparent underdog
    // credit).
    public static function consider_for_underdog(Database $db, int $fightid, int $teamid, float $npc_pxp, float $player_party_pxp): void {
        if ($player_party_pxp <= 0) {
            return;
        }
        static::replace_if_bigger($db, $fightid, $teamid, 'underdog', $npc_pxp / $player_party_pxp);
    }

    // Considers a just-resolved fight for $teamid's single 'overkill'
    // record: the highest ratio of the NPC party's own accumulated
    // overkill (excess damage dealt beyond what was needed to kill,
    // summed across every mob that died in the fight) to the player
    // party's pxp.
    //
    // $npc_party_overkill comes from the fight's Died messages (see
    // FightAction::overkill_taken()); FightCommunicator calls this for
    // each winning team when a fight ends.
    public static function consider_for_overkill(Database $db, int $fightid, int $teamid, float $npc_party_overkill, float $player_party_pxp): void {
        if ($player_party_pxp <= 0) {
            return;
        }
        static::replace_if_bigger($db, $fightid, $teamid, 'overkill', $npc_party_overkill / $player_party_pxp);
    }

    // Every tag on one fight, gone, regardless of teamid or type --
    // for genuinely deleting a fight outright (system-generated
    // simulated fights, thrown away and regenerated, not any real
    // team-owned fight). See FightCommunicator::delete_fight(),
    // which coordinates this alongside every other table a fight
    // touches.
    public static function delete_all_by_fightid(Database $db, int $fightid): void {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("DELETE FROM fight_saves WHERE fightid = ?");
        $stmt->execute([$fightid]);
    }

    // Every distinct fightid carrying any tag at all for $teamid --
    // "which fights does this team own," regardless of which specific
    // type(s) each is tagged with. The lookup bulk cleanup (deleting
    // every simulated fight before regenerating a fresh batch, say)
    // needs.
    public static function find_all_fightids_by_teamid(Database $db, int $teamid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT DISTINCT fightid FROM fight_saves WHERE teamid = ?");
        $stmt->execute([$teamid]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'fightid'));
    }
}
