<?php
define ('INCLUDE_GAME','include/');
require_once INCLUDE_GAME.'errorlog.php';

#Prep the game objects.
require_once INCLUDE_GAME.'fight.php';
require_once INCLUDE_GAME.'monster.php';
require_once INCLUDE_GAME.'fight_communicator.php';
require_once INCLUDE_GAME.'monster_side.php';
require_once INCLUDE_GAME.'ai.php';
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'pointer_index.php';
require_once INCLUDE_GAME.'party_state.php';

#Do LOGIN stuff :)
require_once INCLUDE_GAME.'auth.php';

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();
unset($_SESSION['fightid']);

# Replay links shared before spectate_fight.php existed: send them there.
if (isset($_REQUEST['fight_guid']) && isset($_REQUEST['team_guid'])) {
    header('Location: spectate_fight.php?fight=' . urlencode((string) $_REQUEST['fight_guid'])
        . '&team=' . urlencode((string) $_REQUEST['team_guid']));
    exit;
}

# Okay, this is not a fight viewing request, so we need to authenticate the user.
$userid=authenticate_login(AUTH_LOGIN);
redirect_on_hold($userid,'dse','on_hold.php','../index.php');

# The party the player has set up (PartyState): the lead team, its
# alliance's teams, in formation, fresh from the database.
$db = get_database();
$party = PartyState::party($db, (int) $userid);
$teamid = PartyState::lead_teamid($db, (int) $userid);
if($party === null || $teamid === null)
    {
    header('Location: app.php');
    exit;
    }

# Public fight flag
$public_fight = isset($_GET['public']);

#Define the background and music.
$background='forest.png';
$music = FightSetup::battle_music();
#Make the fight.
$fight=new FIGHT($background,$music,$spectatable=$public_fight);
# Add the hero party.
$fight->add($party);
#Generate the monster party.
$fight->add(MONSTER::encounter_for($party));
#Setup ally and enemy links.
FightSetup::link_as_enemies($fight);
$player_party=0;
$hero_party=$fight->parties[$player_party];
$monster_party=$fight->parties[1-$player_party];
//See which enemies are alive.
$live_heroes=array();
foreach($monster_party->groups as $grpnumber=>$group)
    foreach($group->characters as $chrnumber=>$hero)
        {
        $true_hero=$monster_party->groups[$grpnumber]->characters[$chrnumber];
        if(!$true_hero->is_dead())
            $live_heroes[$grpnumber][]=$chrnumber;
        }
//Target some live enemies.
foreach($hero_party->groups as $grpnumber=>$group)
    foreach($group->characters as $chrnumber=>$hero)
        {
        $true_hero=$hero_party->groups[$grpnumber]->characters[$chrnumber];
        $grp=array_rand($live_heroes);
        $chr=$live_heroes[$grp][array_rand($live_heroes[$grp])];
        $true_hero->command=0;
        $true_hero->using=0;
        $true_hero->target=array(1-$player_party,$grp,$chr);
        }
//Create an array of the player's parties
//$playerid,$teamid,$player_party
$players[]=array('playerid'=>(int) $userid,'teamid'=>$teamid,'player_party'=>$player_party);
# Starting a fight takes you out of the monster queue; and whoever has
# waited longest in it gets this fight's monsters (see MonsterSide).
MonsterQueue::leave($db, (int) $userid);
$monster_playerid = FightSetup::claim_monster_player($db, (int) $userid, $players);
# Longer rounds for anyone who asked, and their cost (settings).
FightSetup::apply_time_settings($db, $fight, $players);
# How well the computer plays the heroes, should it have to.
FightSetup::apply_hero_ai($fight);

unset($_SESSION['party']); # from before PartyState

//Store the fight data in the DB.
$db = get_database();
try {
    $fightid = FightCommunicator::start_fight($db, $fight, $players);
    if ($monster_playerid !== null) {
        MonsterSide::seat($db, $fightid, $monster_playerid, 1);
    }
} catch (\Throwable $e) {
    log_error("Failed to create fight: " . $e->getMessage());
    echo 'Failure creating fight data.';
    exit;
}
$_SESSION['fightid'] = $fightid;
header('Location: rpg.php');