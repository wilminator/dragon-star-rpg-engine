<?php
#Prep the game objects.
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'team.php';

#Team display (display_team())
require_once INCLUDE_GAME . 'team_display.php';
#Load fight definitions
require_once INCLUDE_GAME . 'fight.php';

#Do LOGIN stuff :)
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_login(AUTH_LOGIN);
add_context_membership($userid, 'dse');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

require_once INCLUDE_GAME . 'fight_communicator.php';

$db = get_database();
$fight_communicator = new FightCommunicator($db, $userid);
if ($fight_communicator->is_in_active_fight()) {
    header('Location: rpg.php');
    exit;
}

//Check for admin permissions
$edit_items = check_permission($userid, 'dse', 'EDIT_ITEM');
$edit_ability = check_permission($userid, 'dse', 'EDIT_ABILITY');
$edit_monster = check_permission($userid, 'dse', 'EDIT_MONSTER');
$edit_job = check_permission($userid, 'dse', 'EDIT_JOB');
$edit_personality = check_permission($userid, 'dse', 'EDIT_PERSONALITY');
$reset_db = check_permission($userid, 'dse', 'RESET_DB');
// Any right at all opens the admin menu (it shows only what each allows).
$has_admin_rights = PermissionGrant::list_for(get_database(), (int) $userid) !== [];
#$=check_permission($userid,'dse','');

$db = get_database();
$teams = TEAM::load_all_by_playerid($db, $userid);

# The player's own page, outside the game: tokens, profile, account and
# each team's fight history, as well as picking the team to play.
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'player_profile.php';
$token_balance = MonsterToken::balance($db, (int) $userid);
$profile = PlayerProfile::load($db, (int) $userid);
$profile_link = $profile->is_public && $profile->handle !== null
    ? 'profile.php?p=' . urlencode(PLAYER::guid_for($db, (int) $userid)) : null;

if (array_key_exists('teamid', $_REQUEST)) {
    $teamid = (int)$_REQUEST['teamid'];
    if (array_key_exists($teamid, $teams)) {
        $teams[$teamid]->change_object_state($db, ObjectState::ACTIVE);
    }
}

unset($_SESSION['party']);
unset($_SESSION['teamid']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Pick A Team To Play</title>
    <style>
        /* Links big enough to hit (WCAG 2.5.8: at least 24px). */
        a { display: inline-block; min-height: 24px; line-height: 24px; }
    </style>
</head>

<body>
    <h1>Pick a team to play</h1>
    <?php /* The same warning as the login page (templates/_flashing_notice.html.twig). */ ?>
    <p role="note"><strong>Photosensitivity warning:</strong> some spell effects in fights contain flashing
        lights. If flashing affects you, turn on <em>Hide attack and spell animations</em> in Settings (in the hub's
        menu, or the Settings button during a fight): what the spells do still shows, without the effects.</p>
    <table>
        <tr>
            <td><a href="../../">Return to the game info page</a></td>
            <?php

            if (TEAM::has_room_for_new_team($db, $userid))
                echo "<td><a href=\"manage_team.php\">Create a new team</a></td>\n";
            if ($has_admin_rights)
                echo '<td><a href="admin/">Go to the game admin menu</a></td>';
            echo '<td><a href="account.php">Your account</a></td>';
            echo $profile_link !== null
                ? '<td><a href="' . htmlspecialchars($profile_link) . '">Your public profile</a></td>'
                : '<td><a href="account.php#profile">Set up a public profile</a></td>';
            echo '<td><a href="players.php">Find players</a></td>';
            echo '<td><a href="logout.php">Log out</a></td>';
            ?>
        </tr>
    </table>
    <p>Monster tokens: <?php echo (int) $token_balance; ?>
        <small>(earned by fighting as the monsters; spent on alliances)</small>
        &mdash; <a href="monster_queue.php">Fight as the monsters</a></p>
    <table>
        <?php
        foreach ($teams as $index => $team) {
            $style = $team->state == ObjectState::INACTIVE ? ' style="background-color:yellow"' : '';
            echo "<tr{$style}><td>";
            display_team($teams[$index], FIGHTER_IMAGES_DIR);
            if ($team->state == ObjectState::INACTIVE) {
                echo <<<EOT
</td><td>
<table height="100%">
  <tr><td>&nbsp;</td></tr>
  <tr><td><a href="pick_team.php?teamid={$team->teamid}">Restore this deleted team</a></td></tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td>&nbsp;</td></tr>
</table></td></tr>
EOT;
            } else {
                echo <<<EOT
</td><td>
<table height="100%">
  <tr><td><a href="app.php?teamid={$team->teamid}">Play this team</a></td></tr>
  <tr><td><a href="fight_history.php?teamid={$team->teamid}">Fight history</a></td></tr>
  <tr><td><a href="delete_team.php?teamid={$team->teamid}">Delete this team</a></td></tr>
  <tr><td>&nbsp;</td></tr>
</table></td></tr>
EOT;
            }
        }
        ?>
    </table>