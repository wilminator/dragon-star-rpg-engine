<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_permission_access('dse','EDIT_MONSTER',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

require_once INCLUDE_GAME.'database.php';
require_once INCLUDE_GAME.'monster.php';

//Get the item id number.
if (!array_key_exists('monsterid',$_REQUEST)) {
  header ('Location: monsters.php');
  exit;
}

//Get the monster id number. 0 is a new monster.
$monsterindex=$_REQUEST['monsterid'];     

try {
  $db = get_database();
  $monster = MONSTER::load($db, $monsterindex);
}
catch (Exception $e)
{
  # This itemid does not exist. Abort.
  header ('Location: monsters.php');
  exit;
}


if(isset($_GET['confirm']) && $_GET['confirm']=="YES")
    {
    //delete that monster in the databse
    $monster->change_object_state($db, ObjectState::INACTIVE);
    header ('Location: monsters.php');
    exit;
    }

//Snag the name
$name=$monster->name;

?>
<table>
  <tr><th>
    <table>
      <caption><b>Are you sure you want to delete the <?php echo $name; ?>?</b></caption>
      <tr>
        <td>
          <?php
          echo "<a href=\"delete_monster.php?monsterid={$monsterindex}&confirm=YES\">Yes, delete this monster.</a>";
          ?>
        </td>
        <td><a href="monsters.php">Do not delete this.</a></td>
      </tr>
    </table>
  </th></tr>
</table>

