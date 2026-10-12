<?php
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'fight_spectator.php';
require_once INCLUDE_GAME.'auth.php';

// Shared by every spectator endpoint. These are the only calls an
// anonymous (not logged in) visitor may make -- see processor.php and
// dispatch_rpc_call() -- so everything they rely on is re-derived here
// on every request: which fight (from its public guid, never its
// sequential id), whether the caller is an admin, and whether they may
// sit with the requested party. Nothing is remembered in the session.

function spectator_is_admin(): bool {
    $userid = $GLOBALS['userid'] ?? 0;
    return $userid > 0 && check_permission($userid, 'dse', 'SPECTATE_FIGHTS');
}

// Where a spectator goes once the fight has ended, by where they came
// from. A replay opened by team guid came from a fight history link, so
// a logged-in viewer goes back to fight history -- that team's, when
// it's theirs -- admins included. Otherwise admins go back to the admin
// page they watch from (the fight browser after a replay, the live
// fight list otherwise), and everyone else to the public spectating
// page.
function spectator_exit_url(bool $after_replay = false, ?string $team_guid = null): string {
    $userid = $GLOBALS['userid'] ?? 0;
    if ($after_replay && $team_guid !== null && $userid > 0) {
        $team = TEAM::get_team_from_guid(get_database(), $team_guid);
        return $team !== null && $team->playerid == $userid ? 'fight_history.php?teamid=' . $team->teamid : 'fight_history.php';
    }
    if (spectator_is_admin()) {
        return $after_replay ? 'admin/fight_playback.php' : 'admin/active_fights.php';
    }
    return 'spectate.php';
}

// Null when the fight doesn't exist, isn't watchable by this caller,
// or the seat isn't one they may take. $team_guid, when given, picks
// the seat instead of $party (see FightSpectator::open()). The caller
// has already been sent the refusal.
function spectator_open($fight_guid, $party, $team_guid = null): ?FightSpectator {
    $fightid = is_string($fight_guid) ? FIGHT::find_id_by_guid(get_database(), $fight_guid) : null;
    $party = ($party === null || $party === '') ? null : (int) $party;
    $team_guid = (is_string($team_guid) && $team_guid !== '') ? $team_guid : null;
    $spectator = $fightid === null ? null : FightSpectator::open(get_database(), $fightid, $party, spectator_is_admin(), $team_guid);
    if ($spectator === null) {
        queue_response('spectator_access_denied', spectator_exit_url());
    }
    return $spectator;
}

function spectator_queue_view(array $view): void {
    queue_response('receive_fight_data', $view['sequence'], $view['client_prefight'], $view['action_list'],
        $view['client_fight'], $view['timeout'], $view['redirect'], $view['round_log'] ?? []);
}
?>
