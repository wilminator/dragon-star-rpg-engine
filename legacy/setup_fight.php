<?php
#Prep the game objects.
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';

require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'party.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'group.php';
require_once INCLUDE_GAME . 'database.php';

#Include local libraries
require_once 'functions.php';
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'csrf.php';
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'inn.php';

session_start();

#Do LOGIN stuff :)
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_membership_access('dse', AUTH_LOGIN, 'index.php');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

$db = get_database();

require_once INCLUDE_GAME . 'fight_communicator.php';
$fight_communicator = new FightCommunicator($db, $userid);
if ($fight_communicator->is_in_active_fight()) {
    header('Location: rpg.php');
    exit;
}


#Check for a cancel
if (isset($_POST['CANCEL'])) {
    unset($_SESSION['party'], $_SESSION['teamid']);
    header('Location: pick_team.php');
    exit;
}

# The party lives in the database (PartyState): the team picked here or
# on pick_team.php, plus its alliance's teams, in the saved formation.
if (isset($_GET['teamid'])) {
    PartyState::choose_team($db, $userid, (int) $_GET['teamid']);
} elseif (PartyState::lead_teamid($db, $userid) === null && isset($_SESSION['teamid'])) {
    PartyState::choose_team($db, $userid, (int) $_SESSION['teamid']); // a session from before PartyState
}
$teamid = PartyState::lead_teamid($db, $userid);
if ($teamid === null) {
    header('Location: pick_team.php');
    exit;
}
$_SESSION['teamid'] = $teamid; // still read by pages not moved yet
unset($_SESSION['party']);
$team=TEAM::load_for_gameplay($db, $teamid);

# Alliance: the player's other teams fighting alongside this one, bought
# with tokens (see include/alliance.php).
$own_teams = array_filter(TEAM::load_all_by_playerid($db, $userid), fn($t) => $t->state === ObjectState::ACTIVE);
$owned = array_map('intval', array_keys($own_teams));
$alliance_message = '';
if (isset($_POST['ALLIANCE'])) {
    if (!csrf_valid()) {
        $alliance_message = 'The form expired. Please try again.';
    } else {
        $alliance_message = match ((string) $_POST['ALLIANCE']) {
            'form' => Alliance::form($db, $userid, $owned, (int) $teamid, array_map('intval', (array) ($_POST['join'] ?? []))),
            'add' => Alliance::add_team($db, $userid, $owned, (int) ($_POST['join_one'] ?? 0)),
            'extend' => Alliance::extend($db, $userid, $owned),
            'end' => (function () use ($db, $userid) { Alliance::end($db, $userid); return 'Your allies have gone their own way.'; })(),
            default => null,
        } ?? '';
    }
}
# The party is this team plus the alliance's (rebuilt on every load, so
# buying, or the alliance's time running out, shows at once).
$party_teamids = Alliance::party_teamids($db, $userid, (int) $teamid, $owned);
# Every team in the party, lead first.
$party_teams = [$team];
foreach (array_slice($party_teamids, 1) as $ally_teamid) {
    $party_teams[] = TEAM::load_for_gameplay($db, $ally_teamid);
}

# Cancel is the only option we will allow before checking if anyone has a retired job.
# If so, then send the user over to manage_team to fix the problem.
$valid_jobs = JOB::filter_active(array_column($team->characters, 'jobid'));
if (count($valid_jobs) < count($team->characters)) {
    header("Location: manage_team.php");
    exit;
}

# Now scrub the CHARACTER objects of inactive abilities and items.
$changed = [];
foreach ($party_teams as $party_team) {
    array_push($changed, ...array_filter($party_team->characters, fn(CHARACTER $char) => $char->scrub_inactive_objects()));
}
if (count($changed) > 0) {
    foreach ($changed as $char) {
        HERO::save_character($db, $char);
    }
}


#Check for shopping
if (isset($_POST['SHOP'])) {
    header('Location: store.php');
    exit;
}

#Check for action
if (isset($_POST['ACTION'])) {
    header('Location: hero_use_thing.php');
    exit;
}

#Set a blank error message
$error = '';

#Check for resting (see Inn)
if (isset($_POST['INN'])) {
    $short = Inn::rest($db, $party_teams);
    $error = $short === null ? (count($party_teams) > 1 ? 'Your teams have rested.' : 'Your team has rested.') : htmlspecialchars($short);
}

#Check for a move (saved: see PartyState::move())
if (isset($_GET['direction'])) {
    PartyState::move($db, $userid, (int) $_GET['group'], (int) $_GET['position'], (string) $_GET['direction']);
}

# The party as it now stands, for the page.
$party = PartyState::party($db, $userid);

#Check for character selection
if (isset($_GET['equip'])) {
    header("Location: setup_equip.php?group=$_GET[group]&position=$_GET[position]");
    exit;
}

#If this is a continue command, then goto the init_fight page.
if (isset($_POST['DO_FIGHT'])) {
    header("Location: init_fight.php");
    exit;
}

#Is this a public fight request?
if (isset($_POST['DO_PUBLIC_FIGHT'])) {
    header("Location: init_fight.php?public");
    exit;
}


foreach ($party_teams as $party_team) {
    echo '<p><b>Team:</b> ' . htmlspecialchars($party_team->name) . " <b>Gold:</b> {$party_team->gold} <b>PXP:</b> {$party_team->get_pxp()}</p>";
}
if ($error)
    echo "<p><font color=\"red\">$error</font></p>\n";
if ($alliance_message !== '')
    echo '<p><font color="red">' . htmlspecialchars($alliance_message) . "</font></p>\n";
echo "<table border=\"0\" cellpadding=\"0\" cellspacing=\"8\">\n";
foreach (array_keys($party->groups) as $gindex) {
    echo "<tr>\n";
    foreach (array_keys($party->groups[$gindex]->characters) as $cindex) {
        echo "<td>\n";
        display_hero_tile($party, $party->groups[$gindex]->characters[$cindex], $gindex, $cindex, FIGHTER_IMAGES_DIR, MENU_IMAGES_DIR);
        echo "</td>\n";
    }
    echo "</tr>\n";
}
echo "</table>\n";

?>

<p>
    Click on hero to change equipment.<br>
    Click on an arrow next to a hero to rearrange your party layout.<br>
    Click on a button below to use items or skills, or cast spells, visit an inn to recover, go shopping for items, or to go fight (or die trying).<br></p>
<form method="post" action="setup_fight.php">
    <table>
        <tr>
            <td><input type="submit" name="ACTION" value="Use Spell, Skill, or Item"></td>
            <td><input type="submit" name="INN" value="Visit an inn for <?php echo implode(' + ', array_map(fn($t) => Inn::price($t), $party_teams)); ?> gold"></td>
            <td><input type="submit" name="SHOP" value="Go Shopping"></td>
            <td><input type="submit" name="DO_FIGHT" value="Fight With Party"></td>
            <td><input type="submit" name="DO_PUBLIC_FIGHT" value="Fight Publicly With Party"></td>
            <td><a href="monster_queue.php">Fight as the monsters</a></td>
            <td><a href="fight_history.php?teamid=<?php echo $teamid; ?>">View Fight History</a></td>
            <td><a href="app.php">Try the new hub</a></td>
        </tr>
        <tr>
            <td><input type="submit" name="CANCEL" value="Go Back"></td>
        </tr>
    </table>
</form>

<?php
# --- Alliance ---
$options = Alliance::options($db, $userid, $owned, (int) $teamid);
echo '<a id="alliance"></a>';
$alliance = $options['alliance'];
$name_of = fn(int $id) => htmlspecialchars(isset($own_teams[$id]) ? $own_teams[$id]->name : "team $id");
$minutes = fn(int $seconds) => (int) ceil($seconds / 60);
$csrf = csrf_field();
echo "<h3>Alliance</h3>\n<p>Tokens: {$options['balance']} <small>(earned by fighting as the monsters)</small></p>\n";
if ($alliance !== null && $alliance->is_active() && in_array((int) $teamid, $alliance->teamids, true)) {
    echo '<p>Allied: ' . implode(', ', array_map($name_of, $alliance->teamids)) . ' &mdash; '
        . $minutes($alliance->seconds_left) . " minutes left. Only time in fights counts; your allies stay until the end of a fight.</p>\n";
} elseif ($alliance !== null && $alliance->is_active()) {
    echo '<p>Your alliance (' . implode(', ', array_map($name_of, $alliance->teamids)) . ') has ' . $minutes($alliance->seconds_left)
        . " minutes left. Pick one of those teams to fight together.</p>\n";
} elseif ($alliance !== null && $alliance->state === Alliance::EXPIRED) {
    echo '<p>Your alliance ran out of time: its last fight ran ' . $minutes($alliance->overtime) . " minutes over, and your allies have left.</p>\n";
}
# One small form per choice.
$button = fn(string $action, string $label, string $extra = '') =>
    "<form method=\"post\" action=\"setup_fight.php\" style=\"display:inline\">$csrf$extra"
    . "<button type=\"submit\" name=\"ALLIANCE\" value=\"$action\">$label</button></form>\n";
if ($options['can_form'] !== [] && $options['balance'] > 0) {
    echo "<form method=\"post\" action=\"setup_fight.php\">$csrf\n"
        . "<p>Bring more of your teams along, 1 token each, for {$minutes($options['extend_seconds'])} minutes:<br>\n";
    foreach ($options['can_form'] as $id) {
        echo "<label><input type=\"checkbox\" name=\"join[]\" value=\"$id\"> {$name_of($id)}</label><br>\n";
    }
    echo "<button type=\"submit\" name=\"ALLIANCE\" value=\"form\">Form an alliance</button></p></form>\n";
} elseif ($options['can_form'] !== [] && $alliance === null) {
    echo "<p>Earn tokens fighting as the monsters to bring your other teams along.</p>\n";
}
if ($options['balance'] > 0) {
    foreach ($options['can_add'] as $id) {
        echo '<p>' . $button('add', "Bring {$name_of($id)} too (1 token, same time)", "<input type=\"hidden\" name=\"join_one\" value=\"$id\">") . "</p>\n";
    }
}
if ($options['can_extend']) {
    echo '<p>' . $button('extend', $alliance->is_active() ? 'Add an hour (1 token)'
        : "Bring them back for {$minutes($options['extend_seconds'])} minutes (1 token)") . "</p>\n";
}
if ($alliance !== null && $alliance->state === Alliance::EXPIRED) {
    echo '<p>' . $button('end', 'Let them go') . "</p>\n";
}
?>
