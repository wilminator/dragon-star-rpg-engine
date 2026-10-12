<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_permission_access('dse','EDIT_ABILITY',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

// What each animation needs (generated: utils/animation_manifest.js).
require_once INCLUDE_GAME . 'animation_manifest.php';
$animations = AnimationManifest::all();
$animationjs = AnimationManifest::to_js();

require_once INCLUDE_GAME.'database.php';
require_once INCLUDE_GAME.'common.php';
require_once INCLUDE_GAME.'ability.php';
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'constants.php';
require_once INCLUDE_GAME.'functions.php';

$db = get_database();

if(isset($_POST['OP'])&&$_POST['OP']=="Update Database")
    {
    //Get the ability id number. 0 is a new ability.
    $abilityindex=$_REQUEST['ability'];

    //Prep the special variables.
    $impact_animation_data=AnimationManifest::get((string) $_POST['impact_animation']);
    $impact_image_count=count($impact_animation_data['images']);
    $impact_sound_count=count($impact_animation_data['sounds']);
    $impact_time_count=count($impact_animation_data['times']);
    $impact_images=[];
    for($index=0;$index<$impact_image_count;$index++)
        $impact_images[]=$_POST["impact_image_$index"];
    $impact_sounds=[];
    for($index=0;$index<$impact_sound_count;$index++)
        $impact_sounds[]=$_POST["impact_sound_$index"];
    $impact_times=[];
    for($index=0;$index<$impact_time_count;$index++)
        $impact_times[]=$_POST["impact_time_$index"];

    //Make a new ability
    $impact_animation_effect = new AnimationEffect($_POST['impact_animation'], $impact_images, $impact_sounds, $impact_times);
    $effect_spec = new EffectSpec(
      effect:Effect::from($_POST['effect']),
      targets:$_POST['targets'],
      base:$_POST['base'],
      added:$_POST['added'],
      attribute:$_POST['attribute']);
    $this_ability=new ABILITY(
      name:$_POST['name'],
      type:$_POST['type'],
      effect_spec:$effect_spec,
      mp_used:$_POST['mp_used'],
      description:$_POST['description'],
      menu_pic:$_POST['menu_pic'],
      skill_effect_type:$_POST['skill_effect_type'],
      impact:$impact_animation_effect);

    // Update the ability id.
    $this_ability->abilityid=$abilityindex;

    // Set the object state.
    if ($abilityindex == 0) {
        $this_ability->state = ObjectState::ACTIVE;
    } elseif (isset($_POST['reactivate']) && $_POST['reactivate'] == 'on') {
        $this_ability->state = ObjectState::ACTIVE;
    } else {
        $old_ability = ABILITY::load($db, $abilityindex);
        $this_ability->state =$old_ability->state;
    }

    //update that ability in the databse
    $this_ability->save($db);
    $abilityindex=$this_ability->abilityid;
    }
else
    $abilityindex=$_REQUEST['ability'];

$abilities=ABILITY::load_all_from_database($db, true);
if($abilityindex>0)
    $ability=$abilities[$abilityindex];
else {
    $animation_effect = new AnimationEffect('ranged_arc_impact',['','',''],['','',''],[1,100,0.0,30,1]);
    $effect_spec = new EffectSpec(
      effect:Effect::NONE,
      targets:EffectSpec::TARGET_PARTY,
      base:0,
      added:0,
      attribute:0);
    $ability=new ABILITY(
      name:'New Ability',
      type:ABILITY::TYPE_SPELL,
      effect_spec:$effect_spec,
      mp_used:0,
      description:'',
      menu_pic:'none.png',
      skill_effect_type:'none',
      impact:$animation_effect);
}

//Setup HTML display variables.
//Default input box size
$def_size=array('size'=>7);
//Default input box size
$def_textarea=array('rows'=>3,'cols'=>50);
//Make list of effect animation types
$effect_types=array('none','close','throw','shoot');
//Make list of animations
foreach(array_keys($animations) as $value)
    $animation_list[$value]=$value;
foreach($effect_types as $value)
    $effect_types_list[$value]=$value;

$image='../'.ABILITY_IMAGES_DIR.$ability->menu_pic;

$var_header_js=json_encode($GLOBALS['effect_var_names']);
$ability_js=$ability->to_js();
$effect_spec = $ability->effect_spec;
?>
<html>
<head>
<script>
window.SM2_DEFER = true;

var ability=<?php echo $ability_js; ?>;
var headers=<?php echo $var_header_js; ?>;
var animations=<?php echo $animationjs; ?>;
var sound_dir='../<?php echo SOUND_DIR; ?>';
var image_dir='../<?php echo IMAGES_DIR; ?>';
var swfdir='../<?php echo SWF_DIR; ?>';


function fix_all(value,animation,defaults)
    {
    fix_variable_headers(value);


    init_audio(function (){alert('No Audio.')});

    return fix_animation_options('impact_',animation,defaults);
    }

function fix_variable_headers(value)
    {
    var base=document.getElementById('base');
    var added=document.getElementById('added');
    base.firstChild.data=headers[value][0];
    added.firstChild.data=headers[value][1];
    return true;
    }
</script>
<script type="text/javascript" src="../javascript/soundmanager2-nodebug-jsmin.js"></script>
<script type="text/javascript" src="anim_prep.js"></script>
</head>
<body onload="return fix_all(ability.effect,ability.impact_animation,ability.impact_data);">
<form method="post" name="formdata" action="edit_ability.php">
<table>
  <tr><th>
    <table>
      <tr>
        <?php
        $tindex=$abilityindex;
        do  {
           	$tindex--;
            } while($tindex>0 && !array_key_exists($tindex,$abilities));
        if ($tindex>0)
            echo "<td><a href=\"edit_ability.php?ability=$tindex\">Previous ability</a></td>"
        ?>
        <td><a href="abilities.php">Return to ability list</a></td>
        <?php
        echo "<td><a href=\"delete_ability.php?ability=$abilityindex\">Delete this ability</a></td>"
        ?>
        <td><a href="edit_ability.php?ability=0">New ability</a></td>
        <?php
        $tindex=$abilityindex;
        $keys=array_keys($abilities);
        sort($keys);
        end($keys);
        $last=end($keys);
        do  {
           	$tindex++;
            } while($tindex<=$last && !array_key_exists($tindex,$abilities));
        if ($tindex<=$last)
            echo "<td><a href=\"edit_ability.php?ability=$tindex\">Next ability</a></td>"
        ?>
      </tr>
    </table>
  </th></tr>
  <tr><th>
    <table>
      <tr>
        <th>Ability Name</th>
        <td>
          <?php make_input('name',$ability->name); ?>
          <?php make_input('ability',$abilityindex,array('type'=>'hidden')); ?>
        </td>
        <th>Ability Type</th>
        <td>
          <?php make_select('type',$ability->type,$ability_types); ?>
        </td>
      </tr>
    </table>
  </th></tr>
  <tr><th>
    <table>
      <caption><b>Ability effect:</b></caption>
      <tr>
        <th>Effect</th>
        <th>Use Targets</th>
        <th id="base">Base Value</th>
        <th id="added">Added Value</th>
        <th>Attribute</th>
        <th>MP Used</th>
      </tr>
      <tr>
        <td><?php make_select('effect',$effect_spec->effect->value,$effects,array('onchange'=>'return fix_variable_headers(this.value);')); ?></td>
        <td><?php make_select('targets',$effect_spec->targets,$ranges); ?></td>
        <td><?php make_input('base',$effect_spec->base,$def_size); ?></td>
        <td><?php make_input('added',$effect_spec->added,$def_size); ?></td>
        <td><?php make_select('attribute',$effect_spec->attribute,$attributes); ?></td>
        <td><?php make_input('mp_used',$ability->mp_used,$def_size); ?></td>
<?php
        if ($ability->state == ObjectState::INACTIVE) {
            echo "<td><label for=\"reactivate\">Reactivate?</label>";
            make_checkbox('reactivate', false);
            echo "</td>";
        }
?>
      </tr>
    </table>
  </th></tr>
  <tr><th>
    <table>
      <caption><b>Description:</b></caption>
      <tr>
        <td><?php make_textarea('description',$ability->description,$def_textarea); ?></td>
      </tr>
    </table>
  </th></tr>
  <tr><th>
    <table>
      <tr>
        <th>Menu Picture</th>
        <th>Effect Type (Skill Only)</th>
        <th>Impact Animation</th>
      </tr>
      <tr>
        <td><?php make_input('menu_pic',$ability->menu_pic); ?><img align="middle" height="34" width="96" src="<?php echo $image; ?>" id="pic" /></td>
        <td><?php make_select('skill_effect_type',$ability->skill_effect_type,$effect_types_list); ?></td>
        <td><?php make_select('impact_animation',$ability->impact->animation,$animation_list,array('onchange'=>'return fix_animation_options(\'impact_\',this.value);')); ?></td>
      </tr>
    </table>
  </th></tr>
  <tr><th>
    <table>
      <tr>
        <th>Images</th>
        <th>Sounds</th>
        <th>Times and data</th>
      </tr>
      <tr>
        <td><table id="impact_images"></table></td>
        <td><table id="impact_sounds"></table></td>
        <td><table id="impact_times"></table></td>
      </tr>
    </table>
  </th></tr>
  <tr><th>
    <table>
      <tr>
        <td><input type="submit" name="OP" value="Update Database"></td>
      </tr>
    </table>
  </th></tr>
  <tr><th>
    <table>
      <tr>
        <?php
        $tindex=$abilityindex;
        do  {
           	$tindex--;
            } while($tindex>0 && !array_key_exists($tindex,$abilities));
        if ($tindex>0)
            echo "<td><a href=\"edit_ability.php?ability=$tindex\">Previous ability</a></td>"
        ?>
        <td><a href="abilities.php">Return to ability list</a></td>
        <?php
        echo "<td><a href=\"delete_ability.php?ability=$abilityindex\">Delete this ability</a></td>"
        ?>
        <td><a href="edit_ability.php?ability=0">New ability</a></td>
        <?php
        $tindex=$abilityindex;
        $keys=array_keys($abilities);
        sort($keys);
        end($keys);
        $last=end($keys);
        do  {
           	$tindex++;
            } while($tindex<=$last && !array_key_exists($tindex,$abilities));
        if ($tindex<=$last)
            echo "<td><a href=\"edit_ability.php?ability=$tindex\">Next ability</a></td>"
        ?>
      </tr>
    </table>
  </th></tr>
</table>
</form>
<!--<applet name="Jukebox" id="Jukebox" code="Jukebox.class"  archive="../java/Jukebox.jar" width="700" height="20"></applet>-->
</body>
</html>
