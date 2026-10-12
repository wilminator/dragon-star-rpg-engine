<?php
require_once INCLUDE_GAME . 'player_settings.php';
require_once INCLUDE_GAME.'html/html_common.php';
require_once INCLUDE_GAME.'html/html_loader.php';
require_once INCLUDE_GAME.'html/html_action_menu.php';
require_once INCLUDE_GAME.'html/html_list_menu.php';
require_once INCLUDE_GAME.'html/html_fight_dialog.php';
require_once INCLUDE_GAME.'html/html_equip_menu.php';
require_once INCLUDE_GAME.'html/html_javascript.php';
require_once INCLUDE_GAME.'html/html_css.php';
require_once INCLUDE_GAME.'browser.php';
require_once INCLUDE_GAME.'csrf.php';

function html_commit()
    {
    echo <<<EOD
<div class="submit">
    <input class="commit" id="commit" type="submit" value="Commit" onclick="commit_commands();">
</div>
EOD;
    }

// Opens the settings popup (javascript/settings.js) over the fight.
function html_settings_button()
    {
    echo <<<EOD
<div class="settingsbutton">
    <button type="button" id="settingsbutton" onclick="open_settings({call: call_server});">Settings</button>
</div>
EOD;
    }

function html_target_cancel()
    {
    echo <<<EOD
<div class="cancel" id="cancel">
    <input class="cancel" type="submit" value="Cancel Target" onclick="target_cancel();">
</div>
EOD;
    }

function html_fight_message()
    {
    echo <<<EOD
<div id="fight_message" class="fight_message">&nbsp;</div>
EOD;
    }

// $spectate_guid: set only for a spectator page (spectate_fight.php).
// The client then uses the read-only spectator calls, identifying the
// fight by this public guid; $fightid is passed as 0 for spectators,
// since only the participant calls use it. $spectate_team: the team
// guid a spectator was seated by, if any; sent back with every
// spectator call so the seat is re-checked the same way.
// $teamids: every team the player fights with (an alliance brings
// several); defaults to just $teamid. $overlay_html: trusted markup
// placed at the top of the page (an admin's "back to spectating").
function html_combat(int $fightid, int $player_party, ?int $teamid = null, ?string $spectate_guid = null, ?string $spectate_team = null, ?array $teamids = null, string $overlay_html = ''): void
    {
    //Prep the javascript and css stacks
    //Push the entire css directory.
    push_css_directory('',CSS_DIR);

    //Push the entire javascript directory.
    push_js_directory('',JAVASCRIPT_DIR);

    //Push the entire images/icon/menu directory.
    push_image_directory(MENU_IMAGES_DIR);
    //push_image_directory(ABILITY_IMAGES_DIR);
    //push_image_directory(ITEM_IMAGES_DIR);
    //push_image_directory(EFFECT_IMAGES_DIR);

    //Get the list of images
    $image_js=image_stack_to_js();

    //Import objects into js
    $itemsjs=ITEM::all_to_js();
    $abilitiesjs=ABILITY::all_to_js();
    $jobsjs=JOB::all_to_js();
    $personalitiesjs=PERSONALITY::all_to_js();
    $equip_points_js=json_encode(array_keys(get_equip_points()));
    $equip_layout_js=json_encode(get_equip_points());

    $teamid_js = json_encode($teamid);
    $teamids_js = json_encode(array_values($teamids ?? ($teamid === null ? [] : [$teamid])));
    $spectate_guid_js = json_encode($spectate_guid);
    $spectate_team_js = json_encode($spectate_team);

    // The viewer's saved settings; defaults for a visitor who isn't
    // logged in (a spectator).
    $viewer = (int) ($GLOBALS['userid'] ?? 0);
    $settings_js = PlayerSettings::to_js(get_database(), $viewer > 0 ? $viewer : null);

    //Output the HTML now
    echo <<<EOD
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html lang="en">
<head>
<META http-equiv="Content-Type" content="text/html; charset=ISO-8859-1">
<title>HTML RPG</title>
<script>
window.SM2_DEFER = true;
</script>
EOD;

    //display the css and script tags.
    html_css(CSS_DIR);
    html_js(JAVASCRIPT_DIR);
    echo "<script type=\"text/javascript\">\n";

    //output the generated script
    echo "
//public constants
var javadir='".JAVA_DIR."';
var swfdir='".SWF_DIR."';
var images='".IMAGES_DIR."';
var fighter_images='".FIGHTER_IMAGES_DIR."';
var menu_images='".MENU_IMAGES_DIR."';
var ability_images='".ABILITY_IMAGES_DIR."';
var item_images='".ITEM_IMAGES_DIR."';
var rpc_csrf_token=".json_encode(csrf_token()).";
var effect_images='".EFFECT_IMAGES_DIR."';
var background_images='".BACKGROUND_IMAGES_DIR."';
var sound='".SOUND_DIR."';
var music='".MUSIC_DIR."';

//Flying blood and damage data
var FLY_BOUND_Y=".FLY_BOUND_Y.";
var FLY_BOUND_X=".FLY_BOUND_X.";
var FLY_COUNT=".FLY_COUNT.";
var FLY_TIMEOUT=".FLY_TIMEOUT.";
var BLOOD_COUNT=".BLOOD_COUNT.";
var DAMAGE_COUNT=".DAMAGE_COUNT.";
";

    echo <<<EOD

window.SM2_DEFER = true;


//Audio variables
var sound_vol=100;
var sound_voices=16;
var music_vol=100;

//The player's settings (audio, what to show): see include/player_settings.php.
var player_settings=$settings_js;

//Equip menu options
var equip_points=$equip_points_js;
var equip_layout=$equip_layout_js;

//Game objects
var items=$itemsjs;
var abilities=$abilitiesjs;
var jobs=$jobsjs;
var personalities=$personalitiesjs;
var player_party=$player_party;
var teamid=$teamid_js;
var teamids=$teamids_js;
var fightid=$fightid;
var spectate_guid=$spectate_guid_js;
var spectate_team=$spectate_team_js;
var static_images=$image_js;


function init()
    {
    //Init game variables.
    init_game();

    /*
    //Init the audio
    init_audio(init2);
    }

function init2()
    {
    */
    // The player's saved volumes (settings.js), 0 to 1 as Howler wants.
    settings_apply();
    load_static_sounds();

	//Start the proxy
	if(init_tranceiver('processor.php')==null)
        alert('Error starting the background tranceiver thread.');

  	return init_fight();
    }

function kill()
    {
	//Stop the proxy
	kill_tranceiver();
    //Alow the page to unload.
    return true;
    }
</script>
</head>
<body class="body" onload="init();">
EOD;
    echo $overlay_html;
    html_loader();

    //Make an action menu for the heroes
    html_action_menu();

    //Make a list menu
    html_list_menu();

    //Make an equipment menu
    html_equip_menu();

    //<ake a fight dialog object
    html_fight_dialog();

    html_code();

    html_jukebox();

echo <<<EOD
<div id="fight" class="screen">
EOD;
    //Make a commit object
    //html_commit($sequence,$return_url);
    html_commit();
    html_settings_button();

    //Make a target cancel object
    html_target_cancel();

    //Make a fight message object
    html_fight_message();

    //Close HTML page
    echo <<<EOD
    </div>
</body>
</html>
EOD;
    }
?>
