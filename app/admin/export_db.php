<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_permission_access('dse','RESET_DB',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

require_once INCLUDE_GAME.'database.php';
$db = get_database();

//Check for admin permissions
$reset_db=check_permission($userid,'dse','RESET_DB');
if($reset_db===false)
    {
    header("Location: ../");
    exit;
    }

if(count($_POST)>0)
    {
    $data=array();
    if(array_key_exists('items',$_POST))
        {
        require_once INCLUDE_GAME.'item.php';
        $data['items']=ITEM::load_all_from_database($db, true);
        }

    if(array_key_exists('abilities',$_POST))
        {
        require_once INCLUDE_GAME.'ability.php';        
        $data['abilities']=ABILITY::load_all_from_database($db, true);
        }

    if(array_key_exists('personalities',$_POST))
        {
        require_once INCLUDE_GAME.'personality.php';
        $data['personalities']=PERSONALITY::load_all_from_database($db, true);
        }

    if(array_key_exists('jobs',$_POST))
        {
        require_once INCLUDE_GAME.'job.php';
        $data['jobs']=JOB::load_all_from_database($db, true);
        }

    if(array_key_exists('monsters',$_POST))
        {
        require_once INCLUDE_GAME.'monster.php';        
        $data['monsters']=MONSTER::load_all_from_database($db, true);
        }

    if(array_key_exists('heroes',$_POST))
        {
        require_once INCLUDE_GAME.'team.php';
        $data['teams']=TEAM::load_all_from_database($db, true);
        require_once INCLUDE_GAME.'hero.php';
        $data['characters']=HERO::load_all($db);
        }

    if(array_key_exists('fights',$_POST))
        {
        require_once INCLUDE_GAME.'fight_semaphore.php';
        $semaphore = FightSemaphore::load_all_from_database($db);
        $data['fight_semaphores']= array_filter($semaphore, fn($s) => $s->state === FightStatus::Resolved);
        $not_completed = array_map(fn($s) => $s->fightid, array_filter($semaphore, fn($s) => $s->state !== FightStatus::Resolved));
        require_once INCLUDE_GAME.'fight.php';
        $data['fights']=array_filter(FIGHT::load_all_from_database($db, true), fn($f) => in_array($f->id,$not_completed) === false);
        require_once INCLUDE_GAME.'fight_action.php';
        $data['fight_actions']=array_filter(FightAction::load_all_from_database($db), fn($fa) => in_array($fa->fightid, $not_completed) === false);
        require_once INCLUDE_GAME.'fight_save.php';
        $data['fight_saves']=FightSave::load_all_from_database($db);
        }

    $data=serialize($data);

    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"dse_dump\"");
    header("Content-length:".(string)(strlen($data)));
    echo $data;
    exit;
    }
?>
<table>
  <tr><th>
    <form method="post">
      <table>
        <caption><b>Select the data to export:</b></caption>
        <tr>
          <td><input name="items" type="checkbox" value="1">Items</td>
          <td><input name="abilities" type="checkbox" value="1">Abilities</td>
          <td><input name="jobs" type="checkbox" value="1">jobs</td>
          <td><input name="monsters" type="checkbox" value="1">Monsters</td>
        </tr>
        <tr>
          <td><input name="personalities" type="checkbox" value="1">Personalities</td>
          <td><input name="heroes" type="checkbox" value="1">Heroes (Player Data)</td>
          <td><input name="fights" type="checkbox" value="1">Fights (Stored Fight Records)</td>
        </tr>
      </table>
    <input type="submit" value="Export the selected data">
    </form>
    <p><a href="./">Return to the previous menu.</a></p>
  </th></tr>
</table>