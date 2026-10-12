<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_MONSTER', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'monster.php';
require_once INCLUDE_GAME . 'effects.php';

$db = get_database();
$monsters = MONSTER::load_all_from_database($db, true);

//Get the monster id number. 
if (array_key_exists('monsterid', $_REQUEST)) {
  $monsterindex = $_REQUEST['monsterid'];
  if (array_key_exists($monsterindex, $monsters)) {
    $monster = $monsters[$monsterindex];
    if ($monster->state == ObjectState::INACTIVE) {
      $monster->change_object_state($db, ObjectState::ACTIVE);
    }
  }
}

if ($_GET['sort'] == 'name')
  uasort($monsters, function ($a, $b) {
    return strcmp($a->name, $b->name);
  });

if ($_GET['sort'] == 'pxp')
  uasort($monsters, function ($a, $b) {
    return $a->pxp - $b->pxp;
  });

echo "
<center>
  <table cellpadding=\"4\" cellspacing=\"0\">
    <tr><td colspan=\"2\"><a href=\"edit_monster.php?monsterid=0\">Add new monster</a></td><td colspan=\"3\"><a href=\"./\">Return to the admin menu</a></td><tr>
    <tr><th align=\"left\"><a href=\"monsters.php\">ID</a></th><th align=\"left\"><a href=\"monsters.php?sort=name\">Name</a></th><th align=\"left\" colspan = \"2\"><a href=\"monsters.php?sort=pxp\">PXP</a></th><th align=\"left\"></th><tr>
    ";;
foreach ($monsters as $index => $monster)
  if ($index != 0) {
    $name = htmlspecialchars($monster->name);
    $state_toggle = $monster->state == ObjectState::ACTIVE
      ? "<a href=\"delete_monster.php?monsterid={$index}\">Deactivate</a>"
      : "<a href=\"monsters.php?monsterid={$index}\">Activate</a>";
    $warning = $monster->has_inactive_resources($db)
      ? "<span style=\"color:#900;font-weight:bold;\">&#9888; Uses inactive resources</span>"
      : "";
    $output = "<tr>"
      . "<td><a href=\"edit_monster.php?monsterid={$index}\">{$index}</td>"
      . "<td><a href=\"edit_monster.php?monsterid={$index}\">{$name}</a></td>"
      . "<td>" . $monster->describe_stats() . "</td>"
      . "<td>{$warning}</td>"
      . "<td>{$state_toggle}</td>"
      . "</tr>\n";
    echo $output;
  }
echo "
  </table>
</center>";
