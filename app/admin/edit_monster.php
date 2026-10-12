<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_MONSTER', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'constants.php';
require_once INCLUDE_GAME . 'functions.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'monster.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'character.php';

$db = get_database();
$abilities = ABILITY::load_all_from_database($db, true);
$personalities = PERSONALITY::load_all_from_database($db, true);

if (isset($_POST['OP'])) {
  //Get the item id number.
  if (!array_key_exists('monsterid', $_REQUEST)) {
    header('Location: monsters.php');
    exit;
  }

  //Get the monster id number. 0 is a new monster.
  $monsterindex = $_REQUEST['monsterid'];

  //Get the ability count
  $ability_count = $_POST['ability_count'];
  $m_abilities = array();

  for ($count = 0; $count <= $ability_count; $count++)
    if (
      !isset($_POST["del_ability_$count"])
      && $_POST["ability_$count"] > 0
    )
      $m_abilities[] = $_POST["ability_$count"];

  //Make a new monster
  #($name,$stats,$abilities,$items,$equipment,$gold,$personalityid)
  if ($monsterindex > 0) {
    try {
      $monster = MONSTER::load($db, $monsterindex);
    } catch (Exception $e) {
      # This itemid does not exist. Abort.
      header('Location: monsters.php');
      exit;
    }
    $inventory = $monster->items;
    $equipment = $monster->equipment;
  } else {
    $inventory = array();
    $equipment = array();
  }

  $this_monster = new MONSTER(
    name: $_POST['name'],
    personalityid: $_POST['personalityid'],
    gold: $_POST['gold'],
    stats: array(
      'HP' => $_POST['HP'],
      'MP' => $_POST['MP'],
      'Speed' => $_POST['Speed'],
      'Accuracy' => $_POST['Accuracy'],
      'Strength' => $_POST['Strength'],
      'Dodge' => $_POST['Dodge'],
      'Block' => $_POST['Block'],
      'Power' => $_POST['Power'],
      'Resistance' => $_POST['Resistance'],
      'Focus' => $_POST['Focus']
    ),
    abilities: $m_abilities,
    items: $inventory,
    equipment: $equipment,
    ai_action: $_POST['ai_action'],
    ai_goal: $_POST['ai_goal'],
    ai_target: $_POST['ai_target'],
    ai_experience: $_POST['ai_experience']
  );

  // Update the monster id.
  $this_monster->monsterid = $monsterindex;
  // Set the object state.
  if ($monsterindex == 0) {
    $this_monster->state = ObjectState::ACTIVE;
  } else {
    $this_monster->state = $monster->state;
  }

  //update that monster in the databse
  $this_monster->save($db);
  $monsterindex = $this_monster->monsterid;

  //If this is Update Inventory, then switch pages.
  if ($_POST['OP'] == "Update Inventory") {
    header("Location: equip_monster.php?monsterid=$monsterindex");
    exit;
  }
} else
  $monsterindex = $_REQUEST['monsterid'];

$monsters = MONSTER::load_all_from_database($db, true);
if ($monsterindex > 0)
  $monster = $monsters[$monsterindex];
else
  $monster = new MONSTER(
    name: 'New Monster',
    stats: array('HP' => 12, 'MP' => 6, 'Speed' => 8, 'Accuracy' => 8, 'Strength' => 8, 'Dodge' => 7, 'Block' => 8, 'Power' => 8, 'Resistance' => 8, 'Focus' => 8),
    abilities: array(),
    items: array(),
    equipment: array(),
    gold: 0,
    personalityid: 1,
    ai_action: 0,
    ai_goal: 0,
    ai_target: 0,
    ai_experience: 50
  );

$inactive_resources = $monster->get_inactive_resources($db);

// The AI this monster should have for its strength and abilities
// (AiProfile, the same rule heroes get), offered beside the settings.
require_once INCLUDE_GAME . 'ai_profile.php';
require_once INCLUDE_GAME . 'job.php';
$ai_level = AiProfile::level_for_pxp((int) $monster->pxp);
$ai_recommended = AiProfile::for_level($ai_level, $monster->abilities, false, 0);

//Setup HTML display variables.
$ability_list = array(0 => '');
foreach ($abilities as $abilityid => $ability) {
  $name = htmlspecialchars($ability->name);
  $ability_list[$abilityid] = "{$name} ({$ability->mp_used})";
  $ability_desc[$abilityid] = htmlspecialchars($ability->description);
}

$personality_list = array();
//$result=mysql_do_query("select abilityid,name,description from abilities");
//while($data=mysql_do_fetch_assoc($result))

foreach ($personalities as $index => $personality) {
  if ($personality->state == ObjectState::ACTIVE || $personality->personalityid == $monster->personalityid) {
    $personality_list[$index] = $personality->name;
    $personality_pic[$index] = '../' . FIGHTER_IMAGES_DIR . $personality->base->images[0];
  }
}
asort($personality_list);

//Default input box size
$def_size = array('size' => 7);
//Default input box size
$def_textarea = array('rows' => 3, 'cols' => 50);
?>
<script>
  abilities = <?php echo json_encode($ability_desc); ?>;

  function show_ability_description(object) {
    var ability = object.value;
      document.form_data.ability_desc.value = ability == "0" ? '' : abilities[ability];
  }

  personalities = <?php echo json_encode($personality_pic); ?>;

  function show_personality(object) {
    var ability = object.value;
    document.form_data.pic.src = personalities[ability];
  }
</script>
<form name="form_data" method="post" action="edit_monster.php">
  <?php make_input('monsterid', $monsterindex, ['hidden' => 'hidden']); ?>
  <table>
    <tr>
      <th>
        <table>
          <tr>
            <?php
            $tindex = $monsterindex;
            do {
              $tindex--;
            } while ($tindex > 0 && !array_key_exists($tindex, $monsters));
            if ($tindex > 0)
              echo "<td><a href=\"edit_monster.php?monsterid=$tindex\">Previous monster</a></td>"
            ?>
            <td><a href="monsters.php">Return to monster list</a></td>
            <?php
            echo "<td><a href=\"delete_monster.php?monsterid=$monsterindex\">Delete this monster</a></td>"
            ?>
            <td><a href="edit_monster.php?monsterid=0">New monster</a></td>
            <?php
            $tindex = $monsterindex;
            $keys = array_keys($monsters);
            sort($keys);
            $last = end($keys);
            do {
              $tindex++;
            } while ($tindex <= $last && !array_key_exists($tindex, $monsters));
            if ($tindex <= $last)
              echo "<td><a href=\"edit_monster.php?monsterid=$tindex\">Next monster</a></td>"
            ?>
          </tr>
        </table>
      </th>
    </tr>
    <tr>
      <th>
        <table>
          <tr>
            <th>Monster Name</th>
            <td>
              <?php make_input('name', $monster->name); ?>
              <?php make_input('monster', $monsterindex, array('type' => 'hidden')); ?>
            </td>
            <th>XP Worth</th>
            <td>
              <?php make_input('PXP', $monster->pxp, array('size' => 7, 'disabled' => 'disabled')); ?>
            </td>
            <th>Gold</th>
            <td>
              <?php make_input('gold', $monster->gold, $def_size); ?>
            </td>
            <th>Personality</th>
            <td>
              <?php make_select('personalityid', $monster->personalityid, $personality_list, array('onkeyup' => "show_personality(this);", 'onclick' => "show_personality(this);")); ?>
              <?php if ($inactive_resources['personality']) echo '<span style="color:#900;"> &#9888; inactive</span>'; ?>
            </td>
          </tr>
        </table>
      </th>
      <td rowspan="6" valign="top">
        <img name="pic" src="<?php echo $personality_pic[$monster->personalityid]; ?>">
      <td>
    </tr>
    <tr>
      <th>
        <table>
          <tr>
            <th>
              <table>
                <?php
                echo "<tr>\n";
                foreach ($monster->stats as $key => $value)
                  echo "<th>$key</th>\n";
                echo "</tr>\n<tr>\n";
                foreach ($monster->stats as $key => $value) {
                  echo "<th>";
                  make_input($key, $monster->stats[$key], $def_size);
                  echo "</th>\n";
                }
                echo "</tr>\n";
                ?>
              </table>
            </th>
          </tr>
          <tr>
            <th>
              <table>
                <tr>
                  <th>AI Action</th>
                  <td>
                    <?php make_select('ai_action', $monster->ai_action, $GLOBALS['ai_action'], array()); ?>
                  </td>
                  <th>AI Goal</th>
                  <td>
                    <?php make_select('ai_goal', $monster->ai_goal, $GLOBALS['ai_goal'], array()); ?>
                  </td>
                  <th>AI Target</th>
                  <td>
                    <?php make_select('ai_target', $monster->ai_target, $GLOBALS['ai_target'], array()); ?>
                  </td>
                  <th>AI Experience</th>
                  <td>
                    <?php make_select('ai_experience', $monster->ai_experience, $GLOBALS['ai_experience'], array()); ?>
                  </td>
                </tr>
                <tr>
                  <td colspan="8">
                    Recommended for PXP <?php echo (int) $monster->pxp; ?> (about hero level <?php echo $ai_level; ?>) and its abilities:
                    <?php echo htmlspecialchars($GLOBALS['ai_action'][$ai_recommended['ai_action']] . ' / ' . $GLOBALS['ai_goal'][$ai_recommended['ai_goal']]
                        . ' / ' . $GLOBALS['ai_target'][$ai_recommended['ai_target']] . ' / ' . $GLOBALS['ai_experience'][$ai_recommended['ai_experience']]); ?>
                    <button type="button" onclick="use_ai_recommendation()">Use this</button>
                    (Save to keep it. Change abilities and save to update it.)
                    <script>
                      function use_ai_recommendation() {
                        var recommended = <?php echo json_encode($ai_recommended); ?>;
                        for (var field in recommended) document.form_data[field].value = String(recommended[field]);
                      }
                    </script>
                  </td>
                </tr>
              </table>
            </th>
          </tr>
          <tr>
            <th>
              <table>
                <tr>
                  <th>Ability</th>
                  <th>Delete?</th>
                  <th>Desc</th>
                </tr>
                <?php
                for ($count = 0; $count < count($monster->abilities); $count++) {
                  $is_inactive = in_array($monster->abilities[$count], $inactive_resources['abilities']);
                  echo $is_inactive ? "      <tr style=\"background-color:#fdd;\">\n        <td>" : "      <tr>\n        <td>";
                  echo htmlspecialchars($ability_list[$monster->abilities[$count]]);
                  if ($is_inactive) echo " <span style=\"color:#900;\">&#9888; inactive</span>";
                  make_input("ability_$count", $monster->abilities[$count], array('type' => 'hidden'));
                  echo "</td>\n        <td>";
                  make_checkbox("del_ability_$count", false);
                  echo "</td>\n        <td>";
                  echo $ability_desc[$monster->abilities[$count]];
                  echo "</td>\n      </tr>\n";
                }
                ?>
                <tr>
                  <td><?php 
                  $selectable_ability_list = array_filter($ability_list, fn($key)=>!in_array($key, $inactive_resources['abilities']),ARRAY_FILTER_USE_KEY);
                  make_select("ability_$count", '', $selectable_ability_list, array('onkeypress' => "show_ability_description(this);", 'onclick' => "show_ability_description(this);")); 
                  ?></td>
                  <td><?php make_input("ability_count", count($monster->abilities), array('type' => 'hidden')); ?></td>
                  <td><?php make_textarea("ability_desc", '', $def_textarea); ?></td>
                </tr>
              </table>
            </th>
          </tr>
          <tr>
            <th>
              <table>
                <tr>
                  <td><input type="submit" name="OP" value="Update Database"></td>
                  <td>
                    <input type="submit" name="OP" value="Update Inventory">
                    <?php if (count($inactive_resources['items']) > 0): ?>
                      <span style="color:#900;"> &#9888; Uses inactive items</span>
                    <?php endif; ?>
                  </td>
                </tr>
              </table>
            </th>
          </tr>
          <tr>
            <th>
              <table>
                <tr>
                  <?php
                  $tindex = $monsterindex;
                  do {
                    $tindex--;
                  } while ($tindex > 0 && !array_key_exists($tindex, $monsters));
                  if ($tindex > 0)
                    echo "<td><a href=\"edit_monster.php?monsterid=$tindex\">Previous monster</a></td>"
                  ?>
                  <td><a href="monsters.php">Return to monster list</a></td>
                  <?php
                  echo "<td><a href=\"delete_monster.php?monsterid=$monsterindex\">Delete this monster</a></td>"
                  ?>
                  <td><a href="edit_monster.php?monsterid=0">New monster</a></td>
                  <?php
                  $tindex = $monsterindex;
                  $keys = array_keys($monsters);
                  sort($keys);
                  $last = end($keys);
                  do {
                    $tindex++;
                  } while ($tindex <= $last && !array_key_exists($tindex, $monsters));
                  if ($tindex <= $last)
                    echo "<td><a href=\"edit_monster.php?monsterid=$tindex\">Next monster</a></td>"
                  ?>
                </tr>
              </table>
            </th>
          </tr>
        </table>
</form>