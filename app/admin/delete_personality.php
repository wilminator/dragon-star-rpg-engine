<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_PERSONALITY', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'monster.php';

// Ensure a job is specified.
if (!array_key_exists('personality', $_REQUEST)) {
  header('Location: personalities.php');
  exit;
}

//Get the personality id number. 
$personalityindex = $_REQUEST['personality'];

try {
  $db = get_database();
  $personality = PERSONALITY::load($db, $personalityindex);
} catch (Exception $e) {
  # This personalityid does not exist. Abort.
  header('Location: personalities.php');
  exit;
}

$affected_monsters = MONSTER::find_monsters_using_personality($db, $personalityindex);

if (isset($_GET['confirm']) && $_GET['confirm'] == "YES") {
  //delete that personality in the databse
  $personality->change_object_state($db, ObjectState::INACTIVE);
  header('Location: personalities.php');
  exit;
}

//Snag the name
$name = $personality->name;

if (count($affected_monsters) > 0) {
  $data = array_map(fn($x)=>['id'=>$x->monsterid,'name'=>htmlspecialchars($x->name)],$affected_monsters);
  $warning = implode("\n", array_map(fn($m) => "<li><a href=\"edit_monster.php?monsterid={$m['id']}\">{$m['name']}</a></li>", $data));
  $count = count($affected_monsters);
  echo <<<EOL
<p style="background-color:#fee;border:1px solid #c00;padding:8px;color:#900;">
  <b>&#9888; Heads up:</b> $count monster(s) currently use this personality. They'll continue to work correctly, but this will no longer be offered for new or edited monsters going forward.
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
        <caption><b>Are you sure you want to delete <?= htmlspecialchars($name) ?>?</b></caption>
        <tr>
          <td>
            <?php
            echo "<a href=\"delete_personality.php?personality=$personalityindex&confirm=YES\">Yes, delete this personality.</a>";
            ?>
          </td>
          <td><a href="personalities.php">Do not delete this.</a></td>
        </tr>
      </table>
    </th>
  </tr>
</table>