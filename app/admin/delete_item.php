<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_permission_access('dse','EDIT_ITEM',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

require_once INCLUDE_GAME.'database.php';
require_once INCLUDE_GAME.'item.php';
require_once INCLUDE_GAME.'monster.php';

//Get the item id number.
if (!array_key_exists('item',$_REQUEST)) {
  header ('Location: items.php');
  exit;
}

$itemindex=$_REQUEST['item'];     

try {
  $db = get_database();
  $item = ITEM::load($db, $itemindex);
}
catch (Exception $e)
{
  # This itemid does not exist. Abort.
  header ('Location: items.php');
  exit;
}

$affected_monsters = MONSTER::find_monsters_using_item($db, $itemindex);

if(isset($_GET['confirm']) && $_GET['confirm']=="YES")
    {
    //delete that item in the databse
    $item->change_object_state($db, ObjectState::INACTIVE);
    header ('Location: items.php');
    exit;
    }


//Snag the name
$name=$item->name;

if (count($affected_monsters) > 0) {
  $data = array_map(fn($x)=>['id'=>$x->monsterid,'name'=>htmlspecialchars($x->name)],$affected_monsters);
  $warning = implode("\n", array_map(fn($m) => "<li><a href=\"edit_monster.php?monsterid={$m['id']}\">{$m['name']}</a></li>", $data));
  $count = count($affected_monsters);
  echo <<<EOL
<p style="background-color:#fee;border:1px solid #c00;padding:8px;color:#900;">
  <b>&#9888; Heads up:</b> $count monster(s) currently use this item. They'll continue to work correctly, but this will no longer be offered for new or edited monsters going forward.
</p>
<ul>
$warning
</ul>
EOL;
}
?>
<table>
  <tr><th>
    <table>
      <caption><b>Are you sure you want to delete the <?= htmlspecialchars($name) ?>?</b></caption>
      <tr>
        <td>
          <?php
          echo "<a href=\"delete_item.php?item=$itemindex&confirm=YES\">Yes, delete this item.</a>";
          ?>
        </td>
        <td><a href="items.php">Do not delete this.</a></td>
      </tr>
    </table>
  </th></tr>
</table>

