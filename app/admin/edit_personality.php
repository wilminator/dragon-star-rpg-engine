<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_PERSONALITY', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

// What each animation needs (generated: utils/animation_manifest.js).
require_once INCLUDE_GAME . 'animation_manifest.php';
$animations = AnimationManifest::all();
$animationjs = AnimationManifest::to_js();

require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'constants.php';
require_once INCLUDE_GAME . 'functions.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'effects.php';

$db = get_database();
$personalities = PERSONALITY::load_all_from_database($db, true);

$personalityindex = $_REQUEST['personality'];

if (isset($_POST['OP']) && $_POST['OP'] == "Update Database") {
  //Build an array to create a new PERSONALITY with.
  $builder = [
    'name' => $_POST['name'],
    'overworld' => $personalityindex > 0
      ? $personalities[$personalityindex]->overworld
      : PERSONALITY::empty_overworld_data(),
  ];

  foreach (PERSONALITY::animation_names as $field) {
    $animation_data = AnimationManifest::get((string) $_POST["{$field}_animation"]);
    $images = [];
    for ($i = 0; $i < count($animation_data['images']); $i++) $images[] = $_POST["{$field}_image_$i"];
    $sounds = [];
    for ($i = 0; $i < count($animation_data['sounds']); $i++) $sounds[] = $_POST["{$field}_sound_$i"];
    $times = [];
    for ($i = 0; $i < count($animation_data['times']); $i++) $times[] = $_POST["{$field}_time_$i"];
    $builder[$field] = new AnimationEffect($_POST["{$field}_animation"], $images, $sounds, $times);
  }

  //Make a new PERSONALITY
  $this_personality = new PERSONALITY(...$builder);
  $this_personality->personalityid = $personalityindex;

  if ($personalityindex > 0) {
    $this_personality->state = $personalities[$personalityindex]->state;
  }
  else {
    $this_personality->state = ObjectState::ACTIVE;
  }

  //update that personality in the databse
  $this_personality->save($db);
  $personalityindex = $this_personality->personalityid;
  $personalities[$personalityindex] = $this_personality;
}


if ($personalityindex > 0) {
  $personality = $personalities[$personalityindex];
} else {
  $personality = new PERSONALITY(
    'New Personality',
    new AnimationEffect('base_animation', [''], [''], [0]),
    new AnimationEffect('pose_animation', [''], [''], [500, 500]),
    new AnimationEffect('flee_animation', [''], [''], [0]),
    new AnimationEffect('pose_animation', [''], ['Earth3.mp3'], [500, 500]),
    new AnimationEffect('die_animation', [''], ['Earth3.mp3', 'die.mp3'], [500, 10, 5]),
    new AnimationEffect('attack_close_animation', ['', '', ''], ['', '', ''], [25, 500, 500]),
    new AnimationEffect('pose_animation', [''], [''], [500, 500]),
    new AnimationEffect('pose_animation', [''], [''], [500, 500]),
    new AnimationEffect('pose_animation', [''], ['skill.mp3'], [500, 500]),
    new AnimationEffect('pose_animation', [''], ['cast_2.mp3'], [500, 500]),
    new AnimationEffect('pose_animation', [''], [''], [500, 500]),
    PERSONALITY::empty_overworld_data()
  );
}

//Setup HTML display variables.

//Default input box size
$def_size = array('size' => 7);
//Default input box size
$def_textarea = array('rows' => 3, 'cols' => 50);
//Make list of animations
foreach (array_keys($animations) as $value)
  $animation_list[$value] = $value;

//ammo types

$var_header_js = json_encode($GLOBALS['effect_var_names']);
$personality_js = $personality->to_js();
?>
<html>

<head>
  <script type="text/javascript" src="../javascript/soundmanager2-nodebug-jsmin.js"></script>
  <script>
    var personality = <?php echo $personality_js; ?>;
    var headers = <?php echo $var_header_js; ?>;
    var animations = <?php echo $animationjs; ?>;
    var sound_dir = '../<?php echo SOUND_DIR; ?>';
    var image_dir = '../<?php echo IMAGES_DIR; ?>';
    var swfdir = '../<?php echo SWF_DIR; ?>';

    function fix_all(
      base_animation, base_defaults,
      equip_animation, equip_defaults,
      flee_animation, flee_defaults,
      hit_animation, hit_defaults,
      die_animation, die_defaults,
      attack_close_animation, attack_close_defaults,
      attack_throw_animation, attack_throw_defaults,
      attack_shoot_animation, attack_shoot_defaults,
      skill_animation, skill_defaults,
      spell_animation, spell_defaults,
      item_animation, item_defaults) {
      fix_animation_options('base_', base_animation, base_defaults);
      fix_animation_options('equip_', equip_animation, equip_defaults);
      fix_animation_options('flee_', flee_animation, flee_defaults);
      fix_animation_options('hit_', hit_animation, hit_defaults);
      fix_animation_options('die_', die_animation, die_defaults);
      fix_animation_options('attack_close_', attack_close_animation, attack_close_defaults);
      fix_animation_options('attack_throw_', attack_throw_animation, attack_throw_defaults);
      fix_animation_options('attack_shoot_', attack_shoot_animation, attack_shoot_defaults);
      fix_animation_options('skill_', skill_animation, skill_defaults);
      fix_animation_options('spell_', spell_animation, spell_defaults);
      fix_animation_options('item_', item_animation, item_defaults);

      init_audio(function() {
        alert('No Audio.')
      });
    }

    window.SM2_DEFER = true;
  </script>
  <script type="text/javascript" src="anim_prep.js"></script>
</head>

<body onload="return fix_all(
    personality.base_animation,personality.base_data,
    personality.equip_animation,personality.equip_data,
    personality.flee_animation,personality.flee_data,
    personality.hit_animation,personality.hit_data,
    personality.die_animation,personality.die_data,
    personality.attack_close_animation,personality.attack_close_data,
    personality.attack_throw_animation,personality.attack_throw_data,
    personality.attack_shoot_animation,personality.attack_shoot_data,
    personality.skill_animation,personality.skill_data,
    personality.spell_animation,personality.spell_data,
    personality.item_animation,personality.item_data
    );">
  <form method="post" action="edit_personality.php" name="formdata">
    <table>
      <tr>
        <th>
          <table>
            <tr>
              <?php
              $tindex = $personalityindex;
              do {
                $tindex--;
              } while ($tindex > 0 && !array_key_exists($tindex, $personalities));
              if ($tindex > 0)
                echo "<td><a href=\"edit_personality.php?personality=$tindex\">Previous personality</a></td>"
              ?>
              <td><a href="personalities.php">Return to personality list</a></td>
              <?php
              echo "<td><a href=\"delete_personality.php?personality=$personalityindex\">Delete this personality</a></td>"
              ?>
              <td><a href="edit_personality.php?personality=0">New personality</a></td>
              <?php
              $tindex = $personalityindex;
              $keys = array_keys($personalities);
              sort($keys);
              end($keys);
              $last = end($keys);
              do {
                $tindex++;
              } while ($tindex <= $last && !array_key_exists($tindex, $personalities));
              if ($tindex <= $last)
                echo "<td><a href=\"edit_personality.php?personality=$tindex\">Next personality</a></td>"
              ?>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th>Item Name</th>
              <td>
                <?php make_input('name', $personality->name); ?>
                <?php make_input('personality', $personalityindex, array('type' => 'hidden')); ?>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Base animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('base_animation', $personality->base->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'base_\',this.value);')); ?></td>
              <td>
                <table id="base_images"></table>
              </td>
              <td>
                <table id="base_sounds"></table>
              </td>
              <td>
                <table id="base_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Equip animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('equip_animation', $personality->equip->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'equip_\',this.value);')); ?></td>
              <td>
                <table id="equip_images"></table>
              </td>
              <td>
                <table id="equip_sounds"></table>
              </td>
              <td>
                <table id="equip_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Flee animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('flee_animation', $personality->flee->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'flee_\',this.value);')); ?></td>
              <td>
                <table id="flee_images"></table>
              </td>
              <td>
                <table id="flee_sounds"></table>
              </td>
              <td>
                <table id="flee_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Hit animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('hit_animation', $personality->hit->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'hit_\',this.value);')); ?></td>
              <td>
                <table id="hit_images"></table>
              </td>
              <td>
                <table id="hit_sounds"></table>
              </td>
              <td>
                <table id="hit_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Die animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('die_animation', $personality->die->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'die_\',this.value);')); ?></td>
              <td>
                <table id="die_images"></table>
              </td>
              <td>
                <table id="die_sounds"></table>
              </td>
              <td>
                <table id="die_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Close-up attack animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('attack_close_animation', $personality->attack_close->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'attack_close_\',this.value);')); ?></td>
              <td>
                <table id="attack_close_images"></table>
              </td>
              <td>
                <table id="attack_close_sounds"></table>
              </td>
              <td>
                <table id="attack_close_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Throwing attack animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('attack_throw_animation', $personality->attack_throw->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'attack_throw_\',this.value);')); ?></td>
              <td>
                <table id="attack_throw_images"></table>
              </td>
              <td>
                <table id="attack_throw_sounds"></table>
              </td>
              <td>
                <table id="attack_throw_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Shooting attack animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('attack_shoot_animation', $personality->attack_shoot->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'attack_shoot_\',this.value);')); ?></td>
              <td>
                <table id="attack_shoot_images"></table>
              </td>
              <td>
                <table id="attack_shoot_sounds"></table>
              </td>
              <td>
                <table id="attack_shoot_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Skill animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('skill_animation', $personality->skill->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'skill_\',this.value);')); ?></td>
              <td>
                <table id="skill_images"></table>
              </td>
              <td>
                <table id="skill_sounds"></table>
              </td>
              <td>
                <table id="skill_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Spell animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('spell_animation', $personality->spell->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'spell_\',this.value);')); ?></td>
              <td>
                <table id="spell_images"></table>
              </td>
              <td>
                <table id="spell_sounds"></table>
              </td>
              <td>
                <table id="spell_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <th colspan="3">Item animation data</th>
            </tr>
            <tr>
              <th>Animation</th>
              <th>Images</th>
              <th>Sounds</th>
              <th>Times and data</th>
            </tr>
            <tr>
              <td><?php make_select('item_animation', $personality->item->animation, $animation_list, array('onchange' => 'return fix_animation_options(\'item_\',this.value);')); ?></td>
              <td>
                <table id="item_images"></table>
              </td>
              <td>
                <table id="item_sounds"></table>
              </td>
              <td>
                <table id="item_times"></table>
              </td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <td><input type="submit" name="OP" value="Update Database"></td>
            </tr>
          </table>
        </th>
      </tr>
      <tr>
        <th>
          <table>
            <tr>
              <?php
              $tindex = $personalityindex;
              do {
                $tindex--;
              } while ($tindex > 0 && !array_key_exists($tindex, $personalities));
              if ($tindex > 0)
                echo "<td><a href=\"edit_personality.php?personality=$tindex\">Previous personality</a></td>"
              ?>
              <td><a href="personalities.php">Return to personality list</a></td>
              <?php
              echo "<td><a href=\"delete_personality.php?personality=$personalityindex\">Delete this personality</a></td>"
              ?>
              <td><a href="edit_personality.php?personality=0">New personality</a></td>
              <?php
              $tindex = $personalityindex;
              $keys = array_keys($personalities);
              sort($keys);
              end($keys);
              $last = end($keys);
              do {
                $tindex++;
              } while ($tindex <= $last && !array_key_exists($tindex, $personalities));
              if ($tindex <= $last)
                echo "<td><a href=\"edit_personality.php?personality=$tindex\">Next personality</a></td>"
              ?>
            </tr>
          </table>
        </th>
      </tr>
    </table>
  </form>
  <!--<applet name="Jukebox" id="Jukebox" code="Jukebox.class"  archive="../java/Jukebox.jar" width="700" height="20"></applet>-->
</body>

</html>