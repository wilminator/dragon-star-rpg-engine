<?php
// Watch a fight: spectate_fight.php?fight=<fight guid>&team=<team guid>
//             or spectate_fight.php?fight=<fight guid>&party=<n>
//
// team=: seated with that team's party. Works for any fight's replay --
// it's what shared replay links carry -- and for a live fight the
// viewer could watch anyway.
// party= (or neither, for a random hero party): only for a spectatable
// fight, or for an admin (SPECTATE_FIGHTS), who may also sit with a
// player-controlled monster party.
//
// A live fight is watched as it happens; a resolved one plays back
// from the start. Open to anyone, logged in or not. The seat is fixed
// here for the whole visit: the page is rendered with it, and every
// later request carries it and is re-checked (see
// php_js/spectator_common.php).

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';

require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'html/html_fight.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'ai.php';
require_once INCLUDE_GAME . 'html/html_javascript.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'auth.php';
require_once 'php_js/spectator_common.php';

// Logged in is optional here. An on-hold account is simply treated as
// anonymous: it can still watch what the public can, nothing more.
$GLOBALS['userid'] = 0;
if (is_logged_in()) {
    $userid = get_logged_in_userid();
    if (!is_on_hold($userid)) {
        $GLOBALS['userid'] = $userid;
    }
}

$exit_url = spectator_exit_url();

$guid = $_GET['fight'] ?? null;
$party_param = $_GET['party'] ?? '';
if ($party_param !== '' && !ctype_digit((string) $party_param)) {
    header('Location: ' . $exit_url);
    exit;
}
$party = $party_param === '' ? null : (int) $party_param;
$team_guid = $_GET['team'] ?? null;
$team_guid = (is_string($team_guid) && $team_guid !== '') ? $team_guid : null;

$db = get_database();
$fightid = is_string($guid) ? FIGHT::find_id_by_guid($db, $guid) : null;
$spectator = $fightid === null ? null : FightSpectator::open($db, $fightid, $party, spectator_is_admin(), $team_guid);
if ($spectator === null) {
    header('Location: ' . $exit_url);
    exit;
}

html_combat(0, $spectator->party, null, $guid, $team_guid);
