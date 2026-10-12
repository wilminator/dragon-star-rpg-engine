<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_membership_access('dse',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

//Check for admin permissions
$edit_items=check_permission($userid,'dse','EDIT_ITEM');
$edit_ability=check_permission($userid,'dse','EDIT_ABILITY');
$edit_monster=check_permission($userid,'dse','EDIT_MONSTER');
$edit_job=check_permission($userid,'dse','EDIT_JOB');
$reset_db=check_permission($userid,'dse','RESET_DB');
if(($edit_items||$edit_ability||$edit_job||$edit_monster)===false)
    {
    header("Location: ../");
    exit;
    }

require_once INCLUDE_GAME.'job.php';
require_once INCLUDE_GAME.'ability.php';
require_once INCLUDE_GAME.'item.php';
require_once INCLUDE_GAME.'monster.php';
require_once INCLUDE_GAME.'personality.php';

require_once INCLUDE_GAME.'database.php';
$db = get_database();

if(isset($_GET['confirm']) && $_GET['confirm']=="YES")
    {
    //delete all tables in the databse
    ITEM::write_cache_file($db)  ;
    ABILITY::write_cache_file($db);
    JOB::write_cache_file($db);
    PERSONALITY::write_cache_file($db);
    MONSTER::write_cache_file($db);
    header ('Location: ./');
    exit;
    }
?>
<table>
  <tr><th>
    <table>
      <caption><b>Are you sure you want to reinitialize the game variable files?</b></caption>
      <tr>
        <td><a href="init_vars.php?confirm=YES">Yes, commit the files.</a></td>
        <td><a href="./">Do not touch a thing!</a></td>
      </tr>
    </table>
  </th></tr>
</table>
