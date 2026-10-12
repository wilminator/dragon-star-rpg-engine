<?php
// Every recorded fight, live or resolved, for admins holding
// SPECTATE_FIGHTS: search by player (name or id), team (name or id) and
// when the fight was created; watch any of them (live, or as a replay
// from the start) from any party; read a fight's raw round-by-round log;
// and flag fights for retention on a team's behalf, without limit.
// Flagged fights stay out of the team's own fight history (see
// FightBrowser).
//
// Nothing here processes or advances a fight: watching goes through
// the read-only spectator viewer, and the log view only reads.

define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_membership_access('dse', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');
if (!check_permission($userid, 'dse', 'SPECTATE_FIGHTS')) {
    header('Location: index.php');
    exit;
}

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'hero.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'monster.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'fight_browser.php';
require_once INCLUDE_GAME . 'html/html_spectate.php';

$db = get_database();
ensure_all_databases_exist($db);

// --- Flag / unflag (POST), then back to the same search. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fightid'], $_POST['teamid'])) {
    FightBrowser::set_flag($db, (int) $_POST['fightid'], (int) $_POST['teamid'], ($_POST['flag'] ?? '') === '1');
    $back = (string) ($_POST['return'] ?? '');
    header('Location: fight_playback.php' . ($back !== '' && $back[0] === '?' ? $back : ''));
    exit;
}

$fightid = isset($_GET['fightid']) ? (int) $_GET['fightid'] : null;

if ($fightid !== null) {
    // --- Playback view: one fight, read-only, in full. ---
    $fight = FIGHT::load_current($db, $fightid);
    $semaphore = FightSemaphore::load($db, $fightid);
    $player_states = FightPlayerState::load_all_by_fightid($db, $fightid);
    $actions = FightAction::load_all_by_fightid($db, $fightid);
    $stmt = $db->prepare("SELECT created_at FROM fights WHERE id = ?");
    $stmt->execute([$fightid]);
    $created_at = $stmt->fetch(PDO::FETCH_ASSOC)['created_at'] ?? null;

    if ($fight === null) {
        echo "<p>No fight found with id $fightid.</p>\n";
        echo "<p><a href=\"fight_playback.php\">Back to fight list</a></p>\n";
        exit;
    }

    echo "<h2>Fight $fightid</h2>\n";
    echo "<p><b>Created:</b> " . ($created_at ? date('Y-m-d H:i:s', $created_at) : 'unknown')
        // No semaphore left: resolved and purged.
        . " &nbsp; <b>State:</b> " . ($semaphore?->state->name ?? FightStatus::Resolved->name)
        . " &nbsp; <b>Sequence:</b> " . ($semaphore?->sequence ?? count($actions)) . "</p>\n";

    echo "<p><b>Players:</b></p>\n<ul>\n";
    foreach ($player_states as $player_state) {
        echo "<li>playerid {$player_state->playerid}, teamid {$player_state->teamid}, party {$player_state->player_party}</li>\n";
    }
    echo "</ul>\n";

    echo "<p><b>Round-by-round log:</b></p>\n";
    foreach ($actions as $action) {
        echo "<p><b>Sequence {$action->sequence}:</b></p>\n<ul>\n";
        foreach ($action->action_data as $message) {
            [$type, $data] = $message;
            echo "<li>" . htmlspecialchars($type) . ": " . htmlspecialchars(json_encode($data)) . "</li>\n";
        }
        echo "</ul>\n";
    }
    if (count($actions) === 0) {
        echo "<p><i>No rounds have been processed yet.</i></p>\n";
    }

    echo "<p><a href=\"fight_playback.php\">Back to fight list</a></p>\n";
    exit;
}

// --- List view ---
$player_input = trim((string) ($_GET['player'] ?? ''));
$team_input = trim((string) ($_GET['team'] ?? ''));
$from_input = trim((string) ($_GET['from'] ?? ''));
$to_input = trim((string) ($_GET['to'] ?? ''));
$flagged_only = ($_GET['flagged'] ?? '') === '1';

$filters = ['team' => $team_input, 'flagged_only' => $flagged_only];
$problems = [];
if ($player_input !== '') {
    if (ctype_digit($player_input)) {
        $filters['playerid'] = (int) $player_input;
    } else {
        $user = UserAccount::load_by_username($db, $player_input);
        if ($user !== null) {
            $filters['playerid'] = $user->userid;
        } else {
            $problems[] = 'No player named "' . $player_input . '".';
        }
    }
}
// A date alone covers that whole day.
if ($from_input !== '') {
    $from = strtotime($from_input);
    if ($from === false) {
        $problems[] = 'Couldn\'t read the From time.';
    } else {
        $filters['from'] = $from;
    }
}
if ($to_input !== '') {
    $to = strtotime($to_input);
    if ($to !== false && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_input)) {
        $to = strtotime('+1 day', $to) - 1;
    }
    if ($to === false) {
        $problems[] = 'Couldn\'t read the To time.';
    } else {
        $filters['to'] = $to;
    }
}

$rows = $problems === [] ? FightBrowser::search($db, $filters) : [];

$player_names = [];
foreach ($rows as $row) {
    if (!array_key_exists($row['playerid'], $player_names)) {
        $player_names[$row['playerid']] = $row['playerid'] > 0 ? UserAccount::load($db, $row['playerid'])?->username : null;
    }
}

$return = '?' . http_build_query(array_filter([
    'player' => $player_input, 'team' => $team_input, 'from' => $from_input, 'to' => $to_input,
    'flagged' => $flagged_only ? '1' : '',
], fn($v) => $v !== ''));
if ($return === '?') {
    $return = '';
}

echo "<h2>Fight Playback</h2>\n";
echo "<form method=\"get\" action=\"fight_playback.php\">\n";
echo "Player: <input type=\"text\" name=\"player\" placeholder=\"name or id\" value=\"" . spectate_escape($player_input) . "\"> &nbsp;\n";
echo "Team: <input type=\"text\" name=\"team\" placeholder=\"name or id\" value=\"" . spectate_escape($team_input) . "\"> &nbsp;\n";
echo "From: <input type=\"text\" name=\"from\" placeholder=\"YYYY-MM-DD [HH:MM]\" value=\"" . spectate_escape($from_input) . "\"> &nbsp;\n";
echo "To: <input type=\"text\" name=\"to\" placeholder=\"YYYY-MM-DD [HH:MM]\" value=\"" . spectate_escape($to_input) . "\"> &nbsp;\n";
echo "<label><input type=\"checkbox\" name=\"flagged\" value=\"1\"" . ($flagged_only ? ' checked' : '') . "> Flagged only</label> &nbsp;\n";
echo "<input type=\"submit\" value=\"Search\">\n";
echo "</form>\n";

foreach ($problems as $problem) {
    echo "<p><b>" . spectate_escape($problem) . "</b></p>\n";
}
if (count($rows) >= FightBrowser::DEFAULT_LIMIT) {
    echo "<p>Showing the newest " . FightBrowser::DEFAULT_LIMIT . " matches. Narrow the search to see older fights.</p>\n";
}

echo "<table border=\"1\" cellpadding=\"4\">\n";
echo "<tr><th>Fight</th><th>Created</th><th>Player</th><th>Team</th><th>Party</th><th>State</th><th></th><th>Retention</th></tr>\n";
foreach ($rows as $row) {
    $created = $row['created_at'] ? date('Y-m-d H:i:s', $row['created_at']) : 'unknown';
    $player_name = $player_names[$row['playerid']];
    $player = spectate_escape($player_name ?? '') . " ({$row['playerid']})";
    if ($row['teamid'] === CHARACTER::MONSTER_TEAMID) {
        $team = '<i>monsters</i>';
    } else {
        $team = spectate_escape($row['team_name'] ?? '') . " ({$row['teamid']})";
    }
    $state = $row['resolved'] ? 'Resolved' : 'Live, round ' . $row['round'];
    $watch_url = '../spectate_fight.php?fight=' . urlencode($row['guid']) . '&party=' . $row['party'];
    $watch = "<a href=\"" . spectate_escape($watch_url) . "\">" . ($row['resolved'] ? 'Replay' : 'Watch') . "</a>"
        . " &middot; <a href=\"fight_playback.php?fightid={$row['fightid']}\">Log</a>";

    if (!$row['resolved'] || $row['teamid'] <= 0) {
        $retention = '';
    } else {
        $retention = "<form method=\"post\" action=\"fight_playback.php\" style=\"margin:0\">"
            . "<input type=\"hidden\" name=\"fightid\" value=\"{$row['fightid']}\">"
            . "<input type=\"hidden\" name=\"teamid\" value=\"{$row['teamid']}\">"
            . "<input type=\"hidden\" name=\"flag\" value=\"" . ($row['flagged'] ? '0' : '1') . "\">"
            . "<input type=\"hidden\" name=\"return\" value=\"" . spectate_escape($return) . "\">"
            . ($row['flagged'] ? '<b>Flagged</b> ' : '')
            . "<input type=\"submit\" value=\"" . ($row['flagged'] ? 'Unflag' : 'Flag') . "\">"
            . "</form>";
    }

    echo "<tr><td>{$row['fightid']}</td><td>$created</td><td>$player</td><td>$team</td>"
        . "<td>{$row['party']}</td><td>$state</td><td>$watch</td><td>$retention</td></tr>\n";
}
echo "</table>\n";
if ($rows === [] && $problems === []) {
    echo "<p>No fights match.</p>\n";
}
echo "<p><a href=\"index.php\">Back</a></p>\n";
