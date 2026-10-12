<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','include/');

require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_membership_access('dse',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../index.php');

require_once INCLUDE_GAME.'errorlog.php';
require_once INCLUDE_GAME.'database.php';
require_once INCLUDE_GAME.'team.php';

if (!array_key_exists('teamid',$_REQUEST)) {
  header ('Location: pick_team.php');
  exit;
}

//Get the item id number. 0 is a new item.
$teamindex=$_REQUEST['teamid'];
$db = get_database();
$teams = TEAM::load_all_by_playerid($db, $userid);

if (!array_key_exists($teamindex, $teams)) {
  header ('Location: pick_team.php');
  exit;
}

$team = $teams[$teamindex];

if(isset($_GET['confirm']) && $_GET['confirm']=="YES")
    {
    //delete that team in the databse
    $team->change_object_state($db,ObjectState::INACTIVE);
    header ('Location: pick_team.php');
    exit;
    }
?>
<table>
  <tr><th>
    <table>
      <caption><b>Are you sure you want to delete your team <?= $team->name; ?>?</b></caption>
      <tr>
        <td>
          <?php
          echo "<a href=\"delete_team.php?teamid=$teamindex&confirm=YES\">Yes, delete this team.</a>";
          ?>
        </td>
        <td><a href="pick_team.php">Do not delete this team.</a></td>
      </tr>
    </table>
  </th></tr>
</table>