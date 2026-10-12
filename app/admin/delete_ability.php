<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_ABILITY', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'monster.php';

// Ensure a ability is specified.
if (!array_key_exists('ability', $_REQUEST)) {
  header('Location: abilities.php');
  exit;
}

//Get the ability id number.
$abilityindex = $_REQUEST['ability'];

try {
  $db = get_database();
  $ability = ABILITY::load($db, $abilityindex);
} catch (Exception $e) {
  # This abilityid does not exist. Abort.
  header('Location: abilities.php');
  exit;
}

$affected_monsters = MONSTER::find_monsters_using_ability($db, $abilityindex);

if (isset($_GET['confirm']) && $_GET['confirm'] == "YES") {
  //delete that ability in the databse
  $ability->change_object_state($db, ObjectState::INACTIVE);
  header('Location: abilities.php');
  exit;
}

//Snag the name
$name = $ability->name;

if (count($affected_monsters) > 0) {
  $data = array_map(fn($x)=>['id'=>$x->monsterid,'name'=>htmlspecialchars($x->name)],$affected_monsters);
  $warning = implode("\n", array_map(fn($m) => "<li><a href=\"edit_monster.php?monsterid={$m['id']}\">{$m['name']}</a></li>", $data));
  $count = count($affected_monsters);
  echo <<<EOL
<p style="background-color:#fee;border:1px solid #c00;padding:8px;color:#900;">
  <b>&#9888; Heads up:</b> $count monster(s) currently use this ability. They'll continue to work correctly, but this will no longer be offered for new or edited monsters going forward.
</p>
<ul>
$warning
</ul>
EOL;
}
?>
<table>
  <tr>
    <th>
      <table>
        <caption><b>Are you sure you want to delete the <?= htmlspecialchars($name) ?>?</b></caption>
        <tr>
          <td>
            <?php
            echo "<a href=\"delete_ability.php?ability=$abilityindex&confirm=YES\">Yes, delete this ability.</a>";
            ?>
          </td>
          <td><a href="abilities.php">Do not delete this.</a></td>
        </tr>
      </table>
    </th>
  </tr>
</table>