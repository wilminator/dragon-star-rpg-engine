<?php
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'team.php';

// What admin/fight_playback.php lists and changes: every recorded fight,
// live or resolved, one row per participant, searchable by player, team
// and time; and the admin retention flag, which keeps a fight for a
// team without limit.
//
// The flag is a FightSave tag of type FightSave::ADMIN_TYPE. It is kept
// out of the team's own fight history: a player only sees the fights
// their own history, retains and highlights have tagged (see
// FightSave::load_player_history_fightids()).
//
// Player names aren't looked up here: the page turns a player name
// into a playerid before searching (see UserAccount), and names the
// players in the results itself.
class FightBrowser {
    const DEFAULT_LIMIT = 200;

    // $filters, every key optional:
    //   'playerid'     => int     only this player's rows
    //   'team'         => string  a teamid (all digits), or part of a
    //                             team name (case-insensitive)
    //   'from', 'to'   => int     created between these Unix times,
    //                             inclusive
    //   'flagged_only' => bool    only rows an admin has flagged
    //   'limit'        => int     at most this many rows (default 200)
    //
    // Newest fight first. Each row:
    //   ['fightid' => int, 'guid' => string, 'created_at' => int,
    //    'playerid' => int, 'teamid' => int, 'team_name' => ?string,
    //    'party' => int, 'resolved' => bool, 'round' => ?int,
    //    'flagged' => bool]
    // round is null once a resolved fight's semaphore has been purged.
    public static function search(Database $db, array $filters = []): array {
        FIGHT::ensure_table_exists($db);
        FightPlayerState::ensure_table_exists($db);
        FightSemaphore::ensure_table_exists($db);
        FightSave::ensure_table_exists($db);
        TEAM::ensure_table_exists($db);

        $conditions = [];
        $params = [FightSave::ADMIN_TYPE];
        if (isset($filters['playerid'])) {
            $conditions[] = 'fp.playerid = ?';
            $params[] = (int) $filters['playerid'];
        }
        $team = trim((string) ($filters['team'] ?? ''));
        if ($team !== '') {
            if (ctype_digit($team)) {
                $conditions[] = 'fp.teamid = ?';
                $params[] = (int) $team;
            } else {
                $conditions[] = 'LOWER(t.name) LIKE ?';
                $params[] = '%' . strtolower($team) . '%';
            }
        }
        if (isset($filters['from'])) {
            $conditions[] = 'f.created_at >= ?';
            $params[] = (int) $filters['from'];
        }
        if (isset($filters['to'])) {
            $conditions[] = 'f.created_at <= ?';
            $params[] = (int) $filters['to'];
        }
        if (!empty($filters['flagged_only'])) {
            $conditions[] = 's.fightid IS NOT NULL';
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $limit = max(1, (int) ($filters['limit'] ?? self::DEFAULT_LIMIT));

        $stmt = $db->prepare("
            SELECT f.id, f.guid, f.created_at, fp.playerid, fp.teamid, fp.player_party,
                   fs.state, fs.sequence, t.name AS team_name, s.fightid AS flagged_fightid
            FROM fight_players fp
            JOIN fights f ON f.id = fp.fightid
            LEFT JOIN fight_semaphore fs ON fs.fightid = f.id
            LEFT JOIN teams t ON t.teamid = fp.teamid
            LEFT JOIN fight_saves s ON s.fightid = fp.fightid AND s.teamid = fp.teamid AND s.type = ?
            $where
            ORDER BY f.id DESC, fp.player_party, fp.playerid
            LIMIT $limit
        ");
        $stmt->execute($params);

        return array_map(fn($row) => [
            'fightid' => (int) $row['id'],
            'guid' => (string) $row['guid'],
            'created_at' => (int) $row['created_at'],
            'playerid' => (int) $row['playerid'],
            'teamid' => (int) $row['teamid'],
            'team_name' => $row['team_name'],
            'party' => (int) $row['player_party'],
            // No semaphore left: resolved and purged.
            'resolved' => $row['state'] === null || (int) $row['state'] === FightStatus::Resolved->value,
            'round' => $row['sequence'] === null ? null : (int) $row['sequence'],
            'flagged' => $row['flagged_fightid'] !== null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Flags (or unflags) a fight for retention on behalf of one of the
    // teams that fought in it. Only resolved fights can be flagged, and
    // only for a real team (a player controlling monsters has none).
    // Returns false, changing nothing, when the fight isn't resolved or
    // the team wasn't in it.
    public static function set_flag(Database $db, int $fightid, int $teamid, bool $flagged): bool {
        if ($teamid <= 0 || !self::team_fought_in($db, $fightid, $teamid)) {
            return false;
        }
        if (!$flagged) {
            FightSave::delete($db, $fightid, $teamid, FightSave::ADMIN_TYPE);
            return true;
        }
        try {
            (new FightSave($fightid, $teamid, FightSave::ADMIN_TYPE))->save($db);
        } catch (\Exception $e) {
            return false; // not resolved yet
        }
        return true;
    }

    private static function team_fought_in(Database $db, int $fightid, int $teamid): bool {
        foreach (FightPlayerState::load_all_by_fightid($db, $fightid) as $state) {
            if ($state->teamid === $teamid) {
                return true;
            }
        }
        return false;
    }
}
