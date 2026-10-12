<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_ITEM', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'effects.php';

$db = get_database();
$items = ITEM::load_all_from_database($db, true);

//Get the job id number. 
if (array_key_exists('item', $_REQUEST)) {
  $itemindex = $_REQUEST['item'];
  if (array_key_exists($itemindex, $items)) {
    $item = $items[$itemindex];
    if ($item->state == ObjectState::INACTIVE) {
      $item->change_object_state($db, ObjectState::ACTIVE);
    }
  }
}

echo "
<center>
  <table cellpadding=\"4\" cellspacing=\"0\">
    <tr><td colspan=\"2\"><a href=\"edit_item.php?item=0\">Add new item</a></td><td colspan=\"3\"><a href=\"./\">Return to the admin menu</a></td><tr>
    ";
foreach ($items as $index => $item)
  if ($index != 0) {
    $name = htmlspecialchars($item->name);
    $state_toggle = $item->state == ObjectState::ACTIVE
      ? "<a href=\"delete_item.php?item={$index}\">Deactivate</a>"
      : "<a href=\"items.php?item={$index}\">Activate</a>";
    $output = "<tr>"
      . "<td><a href=\"edit_item.php?item={$index}\">{$index}</td>"
      . "<td><a href=\"edit_item.php?item={$index}\">{$name}</a></td>"
      . "<td>{$item->price} gold</td>"
      . "<td>" . $item->describe_use() . "</td>"
      . "<td>" . $item->describe_equip() . "</td>"
      . "<td>{$state_toggle}</td>"
      . "</tr>\n";
    echo $output;
  }
echo "
  </table>
</center>";
