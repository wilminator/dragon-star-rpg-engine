<?php
// Lists the requesting player's own team's resolved fight history, and
// gives them a share link to watch one replayed through the actual
// client combat engine -- not a text dump of the action log. The link
// opens spectate_fight.php by fight and team guid, seated with the
// party this team fought in, and can be shared freely: it needs no
// login and shows no party numbers.
//
// Data source: the team's FightSave tags of the player types -- its
// rolling 'user' history, retains and highlights
// (FightSave::load_player_history_fightids()), never an admin's
// retention flag. Tags only ever exist for Resolved fights (FightSave::save()'s
// own constraint refuses to tag anything else), so this needs no
// explicit resolved-only filter at all; an unresolved fight simply
// never appears here. A player with a fight still in progress is
// redirected to it by the hub (app.php) itself
// (is_in_active_fight() -> rpg.php) before they'd ever reach this
// page.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'database.php';

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_membership_access('dse', AUTH_LOGIN, 'index.php');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

$db = get_database();

require_once INCLUDE_GAME . 'party_state.php';
$teamid = isset($_GET['teamid']) ? (int) $_GET['teamid'] : PartyState::lead_teamid($db, (int) $userid);
if ($teamid === null) {
    header('Location: pick_team.php');
    exit;
}

try {
    $team = TEAM::load_for_gameplay($db, $teamid);
} catch (\Throwable $e) {
    header('Location: pick_team.php');
    exit;
}
if ($team->playerid != $userid) {
    // Not this player's team -- never show another player's fight
    // history, own-team browsing only.
    header('Location: pick_team.php');
    exit;
}

require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'csrf.php';

// --- Keep / stop keeping (POST): up to FightSave's limit of 10 kept
// fights per team, which also show on the player's public profile.
// Works like the admin fight-save toggle. ---
$problem = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fightid'])) {
    $fightid = (int) $_POST['fightid'];
    if (!csrf_valid()) {
        $problem = 'The form expired. Please try again.';
    } elseif (!in_array($fightid, FightSave::load_player_history_fightids($db, $teamid), true)) {
        $problem = "That fight isn't in this team's history.";
    } elseif (($_POST['keep'] ?? '') === '1') {
        if (!FightSave::retain($db, $fightid, $teamid)) {
            $problem = 'This team already keeps 10 fights. Stop keeping one first.';
        }
    } else {
        FightSave::unretain($db, $fightid, $teamid);
    }
    if ($problem === null) {
        header('Location: fight_history.php?teamid=' . $teamid);
        exit;
    }
}

// --- List view: this team's resolved fights only. Fights an admin has
// flagged for retention aren't listed unless the team's own history,
// retains or highlights also include them. ---
$fightids = FightSave::load_player_history_fightids($db, $teamid);
$tags = [];
foreach (['retain', 'underdog', 'overkill'] as $type) {
    foreach (FightSave::load_all_by_type($db, $teamid, $type) as $save) {
        $tags[$save->fightid][$type] = true;
    }
}
$csrf = csrf_field();

echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>" . htmlspecialchars($team->name) . "'s Fight History</title>\n</head>\n<body>\n";
echo "<h2>" . htmlspecialchars($team->name) . "'s Fight History</h2>\n";
if ($problem !== null) {
    echo '<p style="color:#b00"><b>' . htmlspecialchars($problem) . "</b></p>\n";
}
echo "<p>Kept fights stay in your history for good, and show on your public profile along with your underdog and overkill records.</p>\n";
if (count($fightids) === 0) {
    echo "<p>No resolved fights yet.</p>\n";
} else {
    echo "<table border=\"1\" cellpadding=\"4\">\n";
    echo "<tr><th>Fight ID</th><th>Played</th><th></th><th></th><th></th></tr>\n";
    foreach ($fightids as $id) {
        $stmt = $db->prepare("SELECT created_at, guid FROM fights WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $created = $row['created_at'] ? date('Y-m-d H:i:s', $row['created_at']) : 'unknown';
        $replay_url = 'spectate_fight.php?fight=' . urlencode($row['guid']) . '&team=' . urlencode($team->guid);
        $kept = isset($tags[$id]['retain']);
        $records = implode(', ', array_filter([isset($tags[$id]['underdog']) ? 'Underdog record' : null,
            isset($tags[$id]['overkill']) ? 'Overkill record' : null]));
        echo "<tr><td>$id</td><td>$created</td><td><a href=\"" . htmlspecialchars($replay_url) . "\">Watch Replay</a></td>"
            . "<td><form method=\"post\" action=\"fight_history.php?teamid=$teamid\" style=\"margin:0\">$csrf"
            . "<input type=\"hidden\" name=\"fightid\" value=\"$id\"><input type=\"hidden\" name=\"keep\" value=\"" . ($kept ? '0' : '1') . "\">"
            . ($kept ? '<b>Kept</b> ' : '') . "<input type=\"submit\" value=\"" . ($kept ? 'Stop keeping' : 'Keep') . "\"></form></td>"
            . "<td>" . htmlspecialchars($records) . "</td></tr>\n";
    }
    echo "</table>\n";
}
echo "<p><a href=\"pick_team.php\">Back</a></p>\n";
echo "</body>\n</html>\n";
