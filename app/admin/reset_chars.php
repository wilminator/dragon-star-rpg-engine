<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_membership_access('dse',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

//Check for admin permissions
$reset_db=check_permission($userid,'dse','RESET_DB');
if($reset_db===false)
    {
    header("Location: ../");
    exit;
    }

require_once INCLUDE_GAME.'hero.php';

if(isset($_GET['confirm']) && $_GET['confirm']=="YES")
    {
    echo 'Regenerating characters.<br>';
    require_once INCLUDE_GAME.'jobs.php';
    require_once INCLUDE_GAME.'item.php';
    require_once INCLUDE_GAME.'ability.php';
    require_once INCLUDE_GAME.'personalities.php';
    //regenerate all PCs.
    $db = get_database();
    $chars=HERO::load_all($db);
    foreach(array_keys($chars) as $index)
        {
        echo "Processing character #$index {$chars[$index]->name} XP={$chars[$index]->exp}<br>\n";
        $results=$chars[$index]->regenerate_stats();
        //var_dump($results);
        echo implode('<br>',$results['data']).'<br>';
        HERO::save_character($db, $chars[$index]);
        }
    //header ('Location: ./');
    echo "<a href=\"./\">Return to the menu.</a>";
    exit;
    }
?>
<table>
  <tr><th>
    <table>
      <caption><b>Are you sure you want to regenerate all player characters in the game?  They will not lose any items, gold, or experience, but may gain or lose abilities.</b></caption>
      <tr>
        <td>
          <?php
          echo "<a href=\"reset_chars.php?confirm=YES\">Yes, regenerate all PCs.</a>";
          ?>
        </td>
        <td><a href="./">Do not touch a thing!</a></td>
      </tr>
    </table>
  </th></tr>
</table>