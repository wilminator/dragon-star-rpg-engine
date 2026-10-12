<?php
require_once INCLUDE_GAME . 'game_log.php';

/**
 * Problems players' browsers report, stored in the site log (GameLog):
 *
 *   report_anomalies -> category 'browser', kind = the report's own type
 *                       (resource_load_failed, say)
 *   client_error     -> category 'client_error', kind 'client_error',
 *                       the client's message as the message
 *
 * Who and which fight come from the server's side where it knows them
 * (the session, and the player's active fight). A spectator who isn't
 * logged in names the fight they're watching by its guid, and is only
 * heard if it's a real fight.
 *
 * Limits, so a broken or hostile client can't flood the log: a
 * logged-in player's reports per hour, and anonymous spectators'
 * reports per fight per hour.
 *
 * A fight that gets a report is flagged for admin review (flag_fight()).
 */
class Anomaly {
    const MAX_PER_BATCH = 50;
    const PER_HOUR = 300;
    const PER_FIGHT_HOUR = 300;
    const CATEGORIES = ['browser', 'client_error'];

    // Stores what one report_anomalies call sent. Returns how many were
    // kept: entries past MAX_PER_BATCH, or over the hourly limit, are
    // dropped.
    public static function record_reports(Database $db, array $reports, ?int $userid, ?int $fightid, string $ip): int {
        $kept = 0;
        foreach (array_slice(array_values($reports), 0, self::MAX_PER_BATCH) as $report) {
            if (!self::within_limit($db, $userid, $fightid)) {
                break;
            }
            $report = is_array($report) || is_object($report) ? (array) $report : ['value' => $report];
            GameLog::write('browser', 'warning', GameLog::clean_kind($report['type'] ?? null), self::summary($report), $report,
                ['userid' => $userid, 'fightid' => $fightid, 'ip' => $ip], $db);
            $kept++;
        }
        return $kept;
    }

    // Stores one client_error. False if over the hourly limit.
    public static function record_client_error(Database $db, $message, $data, ?int $userid, ?int $fightid, string $ip): bool {
        if (!self::within_limit($db, $userid, $fightid)) {
            return false;
        }
        $message = is_scalar($message) ? (string) $message : (string) json_encode($message);
        GameLog::write('client_error', 'error', 'client_error', $message, ['message' => $message, 'data' => $data],
            ['userid' => $userid, 'fightid' => $fightid, 'ip' => $ip], $db);
        return true;
    }

    // A logged-in player: PER_HOUR of their own. Anyone else: only with
    // a fight, sharing PER_FIGHT_HOUR with everyone else watching it.
    private static function within_limit(Database $db, ?int $userid, ?int $fightid): bool {
        if ($userid !== null) {
            return RateLimit::hit($db, "anomaly-user:$userid", self::PER_HOUR, 3600);
        }
        return $fightid !== null && RateLimit::hit($db, "anomaly-fight:$fightid", self::PER_FIGHT_HOUR, 3600);
    }

    // One line saying what a report is about.
    private static function summary(array $report): string {
        $parts = [];
        foreach ($report as $key => $value) {
            if ($key !== 'type' && is_scalar($value)) {
                $parts[] = "$key: $value";
            }
        }
        return mb_strimwidth(implode(', ', $parts), 0, 300, '...');
    }

    // --- flagging the fight ---

    public static function has_reports_for_fight(Database $db, int $fightid): bool {
        GameLog::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT 1 FROM game_log WHERE fightid = ? AND category IN ('browser', 'client_error') LIMIT 1");
        $stmt->execute([$fightid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    // Flags the fight for admin review (retention -- see FightBrowser)
    // for every team that fought in it. Only a resolved fight can be
    // flagged; a live one is flagged when it resolves (see
    // FightCommunicator). Needs fight_communicator.php loaded. Returns how many teams were flagged.
    public static function flag_fight(Database $db, int $fightid): int {
        $flagged = 0;
        try {
            $fight = FIGHT::load($db, $fightid);
        } catch (\Exception $e) {
            $fight = null;
        }
        $teamids = [];
        foreach (FightPlayerState::load_all_by_fightid($db, $fightid) as $state) {
            if ($state->teamid > 0) {
                array_push($teamids, ...($fight === null ? [$state->teamid]
                    : FightCommunicator::own_teamids($db, $fight, $state->playerid, $state->teamid, $state->player_party)));
            }
        }
        foreach (array_unique($teamids) as $teamid) {
            try {
                (new FightSave($fightid, $teamid, FightSave::ADMIN_TYPE))->save($db);
                $flagged++;
            } catch (\Exception $e) {
                return $flagged; // not resolved yet
            }
        }
        return $flagged;
    }
}
