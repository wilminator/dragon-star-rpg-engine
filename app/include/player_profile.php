<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'player.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'fight_save.php';

/**
 * A player's public profile (profile.php): their handle, their teams
 * (heroes' names, jobs and levels -- nothing else), and each team's
 * showcase fights: the ones the team kept, and its underdog and overkill
 * records. Linked by the player's random guid (PLAYER::guid_for()),
 * never their id or username.
 *
 *   handle      the public name, and what search matches. Never the
 *               player's username or email address (or the part of it
 *               before the @), ignoring case. Unique, ignoring case.
 *   is_public   off by default; needs a handle. Off: the page shows
 *               nothing.
 *   searchable  listed in player search (players.php). A public profile
 *               that isn't searchable is reachable by its link only.
 *
 * One row per player in player_profiles; none until they set something.
 */
final class PlayerProfile {
    private static bool $table_verified = false;

    const HANDLE_PATTERN = '/^[A-Za-z0-9_-]{3,24}$/';
    const SEARCH_LIMIT = 50;
    const SHOWCASE_TYPES = ['retain' => 'Kept', 'underdog' => 'Underdog win', 'overkill' => 'Overkill'];

    public function __construct(
        public int $userid,
        public ?string $handle = null,
        public bool $is_public = false,
        public bool $searchable = false,
    ) {}

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS player_profiles",
            <<<QUERY
CREATE TABLE IF NOT EXISTS player_profiles (
  userid int NOT NULL PRIMARY KEY,
  handle varchar(24) NULL,
  handle_key varchar(24) NULL UNIQUE,
  is_public int NOT NULL,
  searchable int NOT NULL,
  updated_at int NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // The player's profile; defaults (no handle, private) if never set.
    public static function load(Database $db, int $userid): PlayerProfile {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT * FROM player_profiles WHERE userid = ?");
        $stmt->execute([$userid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? new PlayerProfile($userid)
            : new PlayerProfile($userid, $row['handle'], (bool) $row['is_public'], (bool) $row['searchable']);
    }

    private function save(Database $db): void {
        static::ensure_table_exists($db);
        $db->prepare($db->upsert_query('player_profiles', ['userid', 'handle', 'handle_key', 'is_public', 'searchable', 'updated_at'], 'userid'))
            ->execute([$this->userid, $this->handle, $this->handle === null ? null : strtolower($this->handle),
                (int) $this->is_public, (int) $this->searchable, time()]);
    }

    // Sets everything at once, from the account page. Null when saved,
    // otherwise why not (nothing is saved). A blank handle removes it,
    // which also makes the profile private.
    public static function update(Database $db, UserAccount $account, string $handle, bool $is_public, bool $searchable): ?string {
        $handle = trim($handle);
        $problem = $handle === '' ? null : self::handle_problem($db, $account, $handle);
        if ($problem !== null) {
            return $problem;
        }
        if ($handle === '' && $is_public) {
            return 'Choose a handle before making your profile public.';
        }
        $profile = new PlayerProfile($account->userid, $handle === '' ? null : $handle, $is_public, $is_public && $searchable);
        try {
            $profile->save($db);
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return 'That handle is taken.'; // lost a race for it
            }
            throw $e;
        }
        return null;
    }

    private static function handle_problem(Database $db, UserAccount $account, string $handle): ?string {
        if (!preg_match(self::HANDLE_PATTERN, $handle)) {
            return 'A handle is 3 to 24 letters, digits, underscores or hyphens.';
        }
        $key = strtolower($handle);
        $email = strtolower((string) $account->email);
        $forbidden = [strtolower($account->username), $email, explode('@', $email)[0]];
        if (in_array($key, $forbidden, true)) {
            return "Your handle can't be your username or email address.";
        }
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT userid FROM player_profiles WHERE handle_key = ? AND userid <> ?");
        $stmt->execute([$key, $account->userid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) === false ? null : 'That handle is taken.';
    }

    // Public, searchable profiles whose handle contains $text (at least
    // two characters), as [['handle' =>, 'guid' =>], ...], by handle.
    public static function search(Database $db, string $text): array {
        $text = strtolower(trim($text));
        if (strlen($text) < 2) {
            return [];
        }
        static::ensure_table_exists($db);
        // '!' escapes LIKE's wildcards the same way in every database.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
        $stmt = $db->prepare("SELECT userid, handle FROM player_profiles
            WHERE is_public = 1 AND searchable = 1 AND handle_key LIKE ? ESCAPE '!' ORDER BY handle_key LIMIT " . self::SEARCH_LIMIT);
        $stmt->execute([$like]);
        return array_map(fn($row) => ['handle' => $row['handle'], 'guid' => PLAYER::guid_for($db, (int) $row['userid'])],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // What profile.php shows for the player with this guid, or null if
    // there's no such player or their profile isn't public:
    //   ['handle' => string, 'teams' => [['name', 'heroes' => [['name', 'job', 'level']],
    //     'fights' => [['label', 'rating', 'played_at', 'url']]]]]
    public static function public_view(Database $db, string $guid): ?array {
        $userid = PLAYER::find_playerid_by_guid($db, $guid);
        $profile = $userid === null ? null : self::load($db, $userid);
        if ($profile === null || !$profile->is_public || $profile->handle === null) {
            return null;
        }
        $jobs = JOB::load_all_from_database($db);
        $teams = [];
        foreach (TEAM::load_all_by_playerid($db, $userid) as $team) {
            if ($team->state !== ObjectState::ACTIVE) {
                continue;
            }
            $heroes = [];
            foreach ($team->characters as $character) {
                $heroes[] = ['name' => $character->name, 'job' => $jobs[$character->jobid]->name ?? 'Unknown', 'level' => $character->level];
            }
            $teams[] = ['name' => $team->name, 'heroes' => $heroes, 'fights' => self::showcase($db, $team)];
        }
        return ['handle' => $profile->handle, 'teams' => $teams];
    }

    // A team's kept fights and its underdog and overkill records, newest
    // first, each with a replay link anyone can open.
    private static function showcase(Database $db, TEAM $team): array {
        $fights = [];
        foreach (self::SHOWCASE_TYPES as $type => $label) {
            foreach (FightSave::load_all_by_type($db, $team->teamid, $type) as $save) {
                $stmt = $db->prepare("SELECT created_at, guid FROM fights WHERE id = ?");
                $stmt->execute([$save->fightid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    continue;
                }
                $fights[] = [
                    'fightid' => $save->fightid,
                    'label' => $label,
                    'rating' => match ($type) {
                        'underdog' => $save->power_ratio === null ? null : sprintf('the monsters were %.1f× as strong', $save->power_ratio),
                        'overkill' => $save->power_ratio === null ? null : sprintf('overkill rating %.2f', $save->power_ratio),
                        default => null,
                    },
                    'played_at' => (int) $row['created_at'],
                    'url' => 'spectate_fight.php?fight=' . urlencode($row['guid']) . '&team=' . urlencode($team->guid),
                ];
            }
        }
        usort($fights, fn($a, $b) => $b['fightid'] <=> $a['fightid']);
        return array_map(fn($fight) => array_diff_key($fight, ['fightid' => true]), $fights);
    }
}
