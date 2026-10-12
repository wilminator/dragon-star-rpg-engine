<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_ABILITY', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'effects.php';

$db = get_database();
$abilities = ABILITY::load_all_from_database($db, true);

echo "
<center>
  <table cellpadding=\"4\" cellspacing=\"0\">
    
    <thead>
      <tr><td colspan=\"2\"><a href=\"edit_ability.php?ability=0\">Add new ability</a></td><td colspan=\"2\"><a href=\"./\">Return to the admin menu</a></td><tr>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>State</th>
          <th>Description</th>
          <th></th>
        </tr>
      </thead>      
    ";
foreach ($abilities as $index => $ability)
  if ($index != 0) {
    $name = htmlspecialchars($ability->name);
    $output = "<tr>"
      . "<td><a href=\"edit_ability.php?ability={$index}\">{$index}</td>"
      . "<td><a href=\"edit_ability.php?ability={$index}\">{$name}</a></td>"
      . "<td>" . $ability->state->name . "</td>"
      . "<td>" . $ability->describe_use() . "</td>"
      . "<td><a href=\"delete_ability.php?ability={$index}\">delete</a></td>"
      . "</tr>\n";
    echo $output;
  }
echo "
  </table>
</center>";
