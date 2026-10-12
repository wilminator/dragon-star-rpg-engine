<?php
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'anomaly.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_player_state.php';
require_once INCLUDE_GAME . 'fight_save.php';
require_once INCLUDE_GAME . 'fight_communicator.php'; // Anomaly::flag_fight() asks it whose teams are whose

// Who is reporting: the logged-in player (null when there isn't one),
// the fight -- the player's own active fight if they're in one,
// otherwise the one the page names by guid (a spectator's) -- and their
// address.
function anomaly_reporter($fight_guid = null): array {
    $db = get_database();
    $userid = (int) ($GLOBALS['userid'] ?? 0);
    $fightid = null;
    try {
        if ($userid > 0) {
            $fightid = FightPlayerState::find_active_fightid_for_player($db, $userid);
        }
        if ($fightid === null && is_string($fight_guid) && $fight_guid !== '') {
            $fightid = FIGHT::find_id_by_guid($db, $fight_guid);
        }
    } catch (\Throwable $e) {
        $fightid = null;
    }
    return [$userid > 0 ? $userid : null, $fightid, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')];
}

// After storing reports: flag their fight for review.
function anomaly_after_storing(?int $fightid): void {
    $db = get_database();
    try {
        if ($fightid !== null) {
            Anomaly::flag_fight($db, $fightid);
        }
    } catch (\Throwable $e) {
        log_error('Anomaly upkeep: ' . $e->getMessage());
    }
}
