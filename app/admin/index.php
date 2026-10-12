<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_membership_access('dse',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

//Check for admin permissions
$edit_items=check_permission($userid,'dse','EDIT_ITEM');
$edit_ability=check_permission($userid,'dse','EDIT_ABILITY');
$edit_personality=check_permission($userid,'dse','EDIT_PERSONALITY');
$edit_monster=check_permission($userid,'dse','EDIT_MONSTER');
$edit_job=check_permission($userid,'dse','EDIT_JOB');
$reset_db=check_permission($userid,'dse','RESET_DB');
$spectate_fights=check_permission($userid,'dse','SPECTATE_FIGHTS');
$manage_users=check_permission($userid,'dse','MANAGE_USERS');
$review_logs=check_permission($userid,'dse','REVIEW_LOGS');
#$=check_permission($userid,'dse','');

echo  "<center>\n<table>\n";
if($edit_items)
    echo '<tr><td><a href="items.php">Edit Items</a></td></tr>';
if($edit_ability)
    echo '<tr><td><a href="abilities.php">Edit Abilities</a></td></tr>';
if($edit_job)
    echo '<tr><td><a href="jobs.php">Edit Jobs</a></td></tr>';
if($edit_personality)
    echo '<tr><td><a href="personalities.php">Edit Personalities</a></td></tr>';
if($edit_monster)
    echo '<tr><td><a href="monsters.php">Edit Monsters</a></td></tr>';
echo "<tr><td></td></tr>";
if($edit_items||$edit_ability||$edit_job||$edit_monster)
    echo '<tr><td><b><a href="init_vars.php">Commit changes to the game variable files</a></b></td></tr>';
echo "<tr><td></td></tr>";
if($manage_users)
    echo '<tr><td><a href="users.php">Manage accounts and rights</a></td></tr>';
if($review_logs)
    echo '<tr><td><a href="logs.php">Logs: errors, security, players\' browsers</a></td></tr>';
if($spectate_fights)
    {
    echo '<tr><td><a href="active_fights.php">Watch active fights</a></td></tr>';
    echo '<tr><td><a href="fight_playback.php">Search, replay and retain fights</a></td></tr>';
    }
if($reset_db)
    {
    echo '<tr><td><b><a href="reset_chars.php">Reset (regenerate) charaters</a></b></td></tr>';
    echo '<tr><td><a href="mail.php">Mail settings</a></td></tr>';
    echo '<tr><td><a href="housekeeping.php">Housekeeping: clearing out old data</a></td></tr>';
    echo '<tr><td><b><a href="export_db.php">Export the DB</a></b></td></tr>';
    echo '<tr><td><b><a href="import_db.php">Import the DB</a></b></td></tr>';
    echo '<tr><td><b><a href="init_db.php">Reset the DB</a></b></td></tr>';
    }
echo "<tr><td></td></tr>\n<tr><td><a href=\"../\">Return to the prior menu</a></td>\n</table>\n</canter>\n";
?>
