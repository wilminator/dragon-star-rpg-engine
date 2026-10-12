<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'party.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'alliance.php';

/**
 * The player's party between fights, kept in the database instead of
 * the session: which team they're playing (the lead), and the formation
 * -- which heroes stand in which group, in what order. The party itself
 * is rebuilt from the teams on every load (party()), so every page, tab
 * and server call sees the same heroes, gold and alliance.
 *
 *   choose_team()  the team picked on pick_team.php (Play this team)
 *   party()        the lead team plus its alliance's, in formation
 *   move()         a step left, right, up or down (the Formation dialog)
 *
 * One row per player in player_parties. A formation lists charids; a
 * hero no longer in the party is dropped from it, and one who joined
 * (an ally's team) goes wherever there's room.
 */
final class PartyState {
    private static bool $table_verified = false;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS player_parties",
            <<<QUERY
CREATE TABLE IF NOT EXISTS player_parties (
  playerid int NOT NULL PRIMARY KEY,
  teamid int NOT NULL,
  formation text NULL,
  updated_at int NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    private static function row(Database $db, int $playerid): ?array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT teamid, formation FROM player_parties WHERE playerid = ?");
        $stmt->execute([$playerid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function write(Database $db, int $playerid, int $teamid, ?array $formation): void {
        static::ensure_table_exists($db);
        $db->prepare($db->upsert_query('player_parties', ['playerid', 'teamid', 'formation', 'updated_at'], 'playerid'))
            ->execute([$playerid, $teamid, $formation === null ? null : json_encode($formation), time()]);
    }

    // The player's active teams, id => TEAM.
    private static function own_active_teams(Database $db, int $playerid): array {
        return array_filter(TEAM::load_all_by_playerid($db, $playerid), fn($team) => $team->state === ObjectState::ACTIVE);
    }

    // Makes $teamid the one the player is playing. False (nothing
    // changes) unless it's one of their own active teams. The formation
    // starts over when the lead team changes.
    public static function choose_team(Database $db, int $playerid, int $teamid): bool {
        if (!array_key_exists($teamid, self::own_active_teams($db, $playerid))) {
            return false;
        }
        $row = self::row($db, $playerid);
        $keep = $row !== null && (int) $row['teamid'] === $teamid ? json_decode((string) $row['formation'], true) : null;
        self::write($db, $playerid, $teamid, is_array($keep) ? $keep : null);
        return true;
    }

    // The team the player is playing, if they've picked one that's still
    // theirs and active.
    public static function lead_teamid(Database $db, int $playerid): ?int {
        $row = self::row($db, $playerid);
        if ($row === null) {
            return null;
        }
        $teamid = (int) $row['teamid'];
        return array_key_exists($teamid, self::own_active_teams($db, $playerid)) ? $teamid : null;
    }

    // The party: the lead team and its alliance's teams (lead first),
    // loaded fresh, arranged in the saved formation. Null if the player
    // hasn't picked a team (or it's gone).
    public static function party(Database $db, int $playerid): ?PARTY {
        $row = self::row($db, $playerid);
        if ($row === null) {
            return null;
        }
        $lead = (int) $row['teamid'];
        $owned = array_map('intval', array_keys(self::own_active_teams($db, $playerid)));
        if (!in_array($lead, $owned, true)) {
            return null;
        }
        $party = new PARTY();
        foreach (Alliance::party_teamids($db, $playerid, $lead, $owned) as $teamid) {
            $party->add_team(TEAM::load_for_gameplay($db, $teamid));
        }
        $formation = json_decode((string) $row['formation'], true);
        return is_array($formation) ? self::arrange($party, $formation) : $party;
    }

    // The formation of $party: [[charid, ...], ...], one list per group.
    public static function formation_of(PARTY $party): array {
        $formation = [];
        foreach ($party->groups as $group) {
            $formation[] = array_values(array_map(fn(CHARACTER $character) => $character->charid, $group->characters));
        }
        return $formation;
    }

    public static function save_formation(Database $db, int $playerid, PARTY $party): bool {
        $row = self::row($db, $playerid);
        if ($row === null) {
            return false;
        }
        self::write($db, $playerid, (int) $row['teamid'], self::formation_of($party));
        return true;
    }

    // $party's heroes rearranged to $formation: those it names in its
    // groups and order, then any it doesn't where there's room.
    public static function arrange(PARTY $party, array $formation): PARTY {
        $heroes = [];
        foreach ($party->groups as $group) {
            foreach ($group->characters as $character) {
                $heroes[$character->charid] = $character;
            }
        }
        $arranged = new PARTY();
        $arranged->teams = $party->teams;
        $arranged->team_names = $party->team_names;
        $arranged->name = $party->name;
        foreach ($formation as $charids) {
            $group = null;
            foreach ((array) $charids as $charid) {
                if (!isset($heroes[(int) $charid]) || $arranged->count() > PARTY_MAX_COUNT) {
                    continue;
                }
                if ($group === null) {
                    if ($arranged->count() >= PARTY_MAX_COUNT) {
                        break;
                    }
                    $group = $arranged->count();
                }
                if ($arranged->insert_character($group, null, $heroes[(int) $charid])) {
                    continue; // the group is full: left for below
                }
                unset($heroes[(int) $charid]);
            }
        }
        // Whoever the formation didn't place (a team that just joined):
        // like PARTY::add_team(), a group of their own if there's room
        // for one, else the first with room.
        foreach ($party->teams as $charids) {
            $left = array_values(array_filter(array_map(fn($charid) => $heroes[$charid] ?? null, $charids)));
            if ($left === []) {
                continue;
            }
            $group = $arranged->count() < PARTY_MAX_COUNT ? $arranged->count() : 0;
            foreach ($left as $character) {
                while ($group < $arranged->count() && $arranged->groups[$group]->count() >= GROUP::MAX_COUNT) {
                    $group++;
                }
                if ($group >= PARTY_MAX_COUNT) {
                    break 2; // can't happen: add_team() never builds a bigger party
                }
                $arranged->insert_character($group, null, $character);
                unset($heroes[$character->charid]);
            }
        }
        return $arranged;
    }

    // Moves the hero at $group/$position one step ('l', 'r', 'u' or 'd',
    // as the Formation dialog's arrows) and saves the formation. False if
    // nothing moved.
    public static function move(Database $db, int $playerid, int $group, int $position, string $direction): bool {
        $party = self::party($db, $playerid);
        if ($party === null || !isset($party->groups[$group]->characters[$position])) {
            return false;
        }
        $before = self::formation_of($party);
        self::step($party, $group, $position, $direction);
        if (self::formation_of($party) === $before) {
            return false;
        }
        return self::save_formation($db, $playerid, $party);
    }

    // A step for the hero at $group/$position:
    //   left, right  trade places with the neighbour in the group
    //   up, down     into the group above or below, at the same place:
    //                taking a vacancy if that group has room, otherwise
    //                trading places with whoever stands there. Down from
    //                the last group starts a new one (up to
    //                PARTY_MAX_COUNT), unless the hero is alone in theirs.
    public static function step(PARTY $party, int $group, int $position, string $direction): void {
        switch ($direction) {
            case 'l':
                if ($position > 0)
                    self::swap($party, $group, $position, $group, $position - 1);
                break;
            case 'r':
                if ($position < $party->groups[$group]->count() - 1)
                    self::swap($party, $group, $position, $group, $position + 1);
                break;
            case 'u':
            case 'd':
                $target = $direction === 'u' ? $group - 1 : $group + 1;
                if ($target < 0) {
                    break;
                }
                if (!isset($party->groups[$target])) {
                    // Past the last group: a new one, while there's room.
                    if ($target < PARTY_MAX_COUNT && $party->groups[$group]->count() > 1)
                        $party->move_character($group, $position, $target, 0);
                    break;
                }
                $there = $party->groups[$target]->count();
                if ($there < GROUP::MAX_COUNT) {
                    $party->move_character($group, $position, $target, min($position, $there));
                } else {
                    self::swap($party, $group, $position, $target, min($position, $there - 1));
                }
                break;
        }
    }

    // Two heroes trade places (and each group is named for whoever now
    // leads it, as GROUP::add_hero() does).
    public static function swap(PARTY $party, int $group_a, int $position_a, int $group_b, int $position_b): void {
        $a = $party->groups[$group_a]->characters[$position_a];
        $party->groups[$group_a]->characters[$position_a] = $party->groups[$group_b]->characters[$position_b];
        $party->groups[$group_b]->characters[$position_b] = $a;
        foreach ([$group_a, $group_b] as $g) {
            $party->groups[$g]->name = $party->groups[$g]->characters[0]->name . "'s group";
        }
    }
}
