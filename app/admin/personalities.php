<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_PERSONALITY', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'personality.php';

$db = get_database();
$personalities = PERSONALITY::load_all_from_database($db, true);

//Get the job id number. 
if (array_key_exists('personality', $_REQUEST)) {
  $personalityindex = $_REQUEST['personality'];
  if (array_key_exists($personalityindex, $personalities)) {
    $personality = $personalities[$personalityindex];
    if ($personality->state == ObjectState::INACTIVE) {
      $personality->change_object_state($db, ObjectState::ACTIVE);
    }
  }
}

echo "
<center>
  <table cellpadding=\"4\" cellspacing=\"0\">
    <tr><td colspan=\"2\"><a href=\"edit_personality.php?personality=0\">Add new personality</a></td><td colspan=\"3\"><a href=\"./\">Return to the admin menu</a></td><tr>
    ";
foreach ($personalities as $index => $personality) {
  $name = htmlspecialchars($personality->name);
  $state_toggle = $personality->state == ObjectState::ACTIVE
    ? "<a href=\"delete_personality.php?personality={$index}\">Deactivate</a>"
    : "<a href=\"personalities.php?personality={$index}\">Activate</a>";
  $output = "<tr>"
    . "<td><a href=\"edit_personality.php?personality={$index}\">{$index}</td>"
    . "<td><a href=\"edit_personality.php?personality={$index}\">{$name}</a></td>"
    . "<td>" . $personality->state->name . "</td>"
    . "<td><a href=\"edit_personality.php?personality={$index}\"><img style=\"max-width:64px;max-height:64px\" src=\"../" . FIGHTER_IMAGES_DIR . "{$personality->base->images[0]}\"></a></td>"
    . "<td>{$state_toggle}</td>"
    . "</tr>\n";
  echo $output;
}
echo "
  </table>
</center>";
