<?php
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';

#Prep the game objects.
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'party.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'pointer_index.php';
require_once INCLUDE_GAME . 'field_functions.php';

session_start();

#Do LOGIN stuff :)
require_once INCLUDE_GAME . 'auth.php';
require_once INCLUDE_GAME . 'party_state.php';
$userid = authenticate_membership_access('dse', AUTH_LOGIN, 'index.php');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

# The party the player has set up (PartyState).
$db = get_database();
$party = PartyState::party($db, (int) $userid);
$teamid = PartyState::lead_teamid($db, (int) $userid);
if ($party === null || $teamid === null) {
    header('Location: pick_team.php');
    exit;
}

#Check for a cancel
if (isset($_POST['GO_BACK'])) {
    header('Location: setup_fight.php');
    exit;
}

$team=TEAM::load_for_gameplay($db, $teamid);

$status = '';
$items = ITEM::load_all_from_cache();

//Build an actor list
$actor_list = array();
foreach ($team->characters as $index => $member)
    $actor_list[$index] = $member->name;

//If actor is not set, then dont do the action!
if (isset($_REQUEST['actorid'])) {
    #Break down the hero's items and equipment.
    $actorid = (int)$_REQUEST['actorid'];
    $character = $team->characters[$actorid];
    $actor_target = new ActionTarget(0, ...$party->get_character_position_by_id($character->charid));
    $character = $actor_target->get_character($party);
    $job_name = JOB::get($character->jobid)->name;

    // Grab valid player options
    list($item_list, $full_item_list, $qty_list, $skill_list, $spell_list, $action_list) = generate_ui_options($character);

    //Now process an action, if needed.
    if (isset($_POST['DO_ACTION'])) {
        $GLOBALS['output'] = '';
        $action = (int)$_POST['action'];
        if (!array_key_exists($action, $action_list)) {
            throw new Exception("Trying to use action $action for hero $actorid, but it's not an option. " . json_encode($action_list));
        }
        $list_index = (int)$_POST['list_index'];
        $qty = null;
        if (isset($_POST['qty']))
            $qty = (int)$_POST['qty'];
        $target_group_index = (isset($_POST['group'])) ? (int)$_POST['group'] : null;
        $target_char_index = ((isset($_POST['character']))) ? (int)$_POST['character'] : null;
        $recipient_target = new ActionTarget(0, $target_group_index, $target_char_index);

        switch ($_POST['action']) {
            case 2: //Use item
            case 4: //Use skill
            case 5: //Cast spell
            case 9: //Give item
                $status = perform_field_action($party, $action, $list_index, $actor_target, $recipient_target);
                break;
            case 1001: //Sell item
                $itemid = $character->inventory[$list_index]['item'];
                $sold = $character->remove_item($list_index, $qty);
                if ($sold) {
                    $item = $items[$itemid];
                    $worth = round($sold * $item->price * .75);
                    $team->gold += $worth;
                    $team->save($db);
                    $status = "{$character->name} sold $sold {$item->name}(s) for $worth gold.";
                }
                break;
        }
        // Save the characters.
        $party->store_party($db);
    }
    // Grab currently valid player options. Things may have changed above.
    list($item_list, $full_item_list, $qty_list, $skill_list, $spell_list, $action_list) = generate_ui_options($character);
} else
    $actorid = '';

//Build fake list
$fake_list = [];

?>
<html>

<head>
    <script type="text/javascript" src="javascript/constants.js"></script>
    <script type="text/javascript">
        var item_list = <?php echo json_encode($item_list); ?>;
        var full_item_list = <?php echo json_encode($full_item_list); ?>;
        var spell_list = <?php echo json_encode($spell_list); ?>;
        var skill_list = <?php echo json_encode($skill_list); ?>;
        var qty_list = <?php echo json_encode($qty_list); ?>;

        var abilities = <?php echo ABILITY::all_to_js(); ?>;
        var items = <?php echo ITEM::all_to_js(); ?>;
        var party = <?php echo json_encode($party); ?>;
        <?php
        if (isset($character)) {
            list($group_index, $char_index) = $party->get_character_position_by_id($character->charid);
            echo "var character=party.groups[{$group_index}].characters[{$char_index}];\n";
        }
        ?>

        var action = null;
        var list_index = null;
        var range = null;
        var effect = null;
        var group = null;

        function select_action(object) {
            action = parseInt(object.value);
            document.form_data.desc.value = '';
            switch (action) {
                case COMMAND_USE_ITEM: //Use Item
                    build_list(document.form_data.list_index, item_list);
                    break;
                case COMMAND_CAST_SPELL: //Cast Spell
                    build_list(document.form_data.list_index, spell_list);
                    break;
                case COMMAND_USE_SKILL: //Use Skill
                    build_list(document.form_data.list_index, skill_list);
                    break;
                case COMMAND_GIVE_ITEM: //Give Item
                case COMMAND_SELL_ITEM: //Sell Item
                    build_list(document.form_data.list_index, full_item_list);
                    break;
            }
            document.form_data.list_index.style.visibility = 'visible';
            document.getElementById('qty_line').style.visibility = 'hidden';
            document.form_data.group.style.visibility = 'hidden';
            document.form_data.character.style.visibility = 'hidden';
            document.form_data.DO_ACTION.style.visibility = 'hidden';
        }

        function build_list(object, data_list) {
            while (object.firstChild)
                object.removeChild(object.firstChild);

            var select = null;
            for (index = 0; index < data_list.length; index++) {
                select = document.createElement('option');
                select.style.color = (data_list[index][2] ? 'black' : 'red');
                select.value = data_list[index][0];
                select.appendChild(document.createTextNode(data_list[index][1]));
                object.appendChild(select);
            }
        }

        function select_ability(object) {
            list_index = parseInt(object.value);
            switch (action) {
                case COMMAND_USE_ITEM: //Use Item
                    document.form_data.desc.value = items[character.inventory[list_index].item].description;
                    range = items[character.inventory[list_index].item].use_targets;
                    effect = items[character.inventory[list_index].item].effect;
                    break;
                case COMMAND_CAST_SPELL: //Cast Spell
                case COMMAND_USE_SKILL: //Use Skill
                    document.form_data.desc.value = abilities[character.abilities[list_index]].description;
                    range = abilities[character.abilities[list_index]].targets;
                    effect = abilities[character.abilities[list_index]].effect;
                    break;
                case COMMAND_GIVE_ITEM: //Give Item
                    //Show the qty line
                    document.form_data.qty.value = character.inventory[list_index].qty;
                    document.getElementById('qty_line').style.visibility = 'visible';
                    document.form_data.desc.value = items[character.inventory[list_index].item].description;
                    range = 0;
                    break;
                case COMMAND_SELL_ITEM: //Sell Item
                    //Show the qty line
                    document.form_data.qty.value = character.inventory[list_index].qty;
                    document.getElementById('qty_line').style.visibility = 'visible';
                    document.form_data.desc.value = items[character.inventory[list_index].item].description;
                    range = -2;
                    break;
            }
            if (action == COMMAND_GIVE_ITEM ||
                (range > -2 && is_field_effect(effect) == true && (action == COMMAND_USE_ITEM || character.current.MP >= abilities[character.abilities[list_index]].mp_used))) {
                var data_list = [];
                for (index = 0; index < party.groups.length; index++)
                    data_list.push([index, party.groups[index].name, (action == COMMAND_GIVE_ITEM | must_be_alive(effect) == false | group_is_alive(index))]);
                build_list(document.form_data.group, data_list);
                document.form_data.group.style.visibility = 'visible';
                document.form_data.DO_ACTION.style.visibility = 'hidden';
            } else if (action == COMMAND_SELL_ITEM ||
                (is_field_effect(effect) == true && (action == COMMAND_USE_ITEM || character.current.MP >= abilities[character.abilities[list_index]].mp_used))) {
                document.form_data.group.style.visibility = 'hidden';
                document.form_data.DO_ACTION.style.visibility = 'visible';
            } else {
                document.form_data.group.style.visibility = 'hidden';
                document.form_data.DO_ACTION.style.visibility = 'hidden';
            }
            document.form_data.character.style.visibility = 'hidden';
        }

        function must_be_alive(effect) {
            switch (effect) {
                case 1: //Heal
                case 2: //Hurt
                case 4: //Slay
                case 5: //Increase Stats
                case 6: //Decrease Stats
                case 7: //Steal Stats
                case 8: //Cause Good Status
                case 9: //Remove Good Status
                case 10: //Cause Bad Status
                case 11: //Remove Bad Status
                case 12: //Restore MP
                    return true;
            }
            return false;
        }

        function is_field_effect(effect) {
            switch (effect) {
                case 1: //Heal
                case 3: //Revive
                case 11: //Remove Bad Status
                case 12: //Restore MP
                    return true;
            }
            return false;
        }

        function group_is_alive(group) {
            sum = 0;
            for (index = 0; index < party.groups[group].characters.length; index++)
                sum += party.groups[group].characters[index].current.HP;
            return sum > 0;
        }

        function select_group(object) {
            if (action != COMMAND_GIVE_ITEM && must_be_alive(effect) && group_is_alive(parseInt(object.value)) == false)
                return false;
            group = parseInt(object.value);
            if (action == COMMAND_GIVE_ITEM || (range > -1 && is_field_effect(effect) == true)) {
                var data_list = [];
                for (index = 0; index < party.groups[group].characters.length; index++)
                    data_list.push([index, party.groups[group].characters[index].name, (action == COMMAND_GIVE_ITEM | must_be_alive(effect) == false | party.groups[group].characters[index].current.HP > 0)]);
                build_list(document.form_data.character, data_list);
                document.form_data.character.style.visibility = 'visible';
                document.form_data.DO_ACTION.style.visibility = 'hidden';
            } else if (is_field_effect(effect) == true) {
                document.form_data.character.style.visibility = 'hidden';
                document.form_data.DO_ACTION.style.visibility = 'visible';
            } else {
                document.form_data.character.style.visibility = 'hidden';
                document.form_data.DO_ACTION.style.visibility = 'hidden';
            }
        }

        function select_character(object) {
            if (action != COMMAND_GIVE_ITEM && must_be_alive(effect) && party.groups[group].characters[parseInt(object.value)].current.HP == 0)
                return false;
            document.form_data.DO_ACTION.style.visibility = 'visible';
        }
    </script>
</head>

<body>
    <h1 style="color:orange"><?php echo $status; ?></h1>
    <form method="POST">
        <table>
            <tr>
                <td><?php make_select("actorid", $actorid, $actor_list, array('size' => 4)); ?></td>
            </tr>
            <tr>
                <td><input type="submit" name="PICK_ACTOR" value="Select a hero"></td>
            </tr>
        </table>
    </form>
    <form method="post" name="form_data">
        <table>
            <?php if (isset($character)) { ?>
                <caption><b><?php echo $character->name; ?> - Level <?php echo $character->level; ?> <?php echo $job_name; ?></b></caption>
            <?php
            } ?>
            <tr>
                <?php if (isset($character)) { ?>
                    <td>
                        <table width="100%">
                            <tr>
                                <th align="left">Action</th>
                            </tr>
                            <tr>
                                <td><?php make_select("action", '', $action_list, array('size' => 5, 'onkeypress' => 'select_action(this);', 'onclick' => 'select_action(this);')); ?></td>
                                <td><?php make_select("list_index", '', $fake_list, array('size' => 5, 'style' => 'visibility:hidden', 'onkeypress' => 'select_ability(this);', 'onclick' => 'select_ability(this);')); ?></td>
                                <td><?php make_select("group", '', $fake_list, array('size' => 3, 'style' => 'visibility:hidden', 'onkeypress' => 'select_group(this);', 'onclick' => 'select_group(this);')); ?></td>
                                <td><?php make_select("character", '', $fake_list, array('size' => 4, 'style' => 'visibility:hidden', 'onkeypress' => 'select_character(this);', 'onclick' => 'select_character(this);')); ?></td>
                            </tr>
                        </table>
                    </td>
            </tr>
            <tr id="qty_line" style="visibility:hidden">
                <td>
                    <table width="100%">
                        <tr>
                            <th>Quantity</th>
                            <td><input name="qty" value="1"></td>
                    </table>
                </td>
            </tr>
            <tr>
                <td>
                    <table width="100%">
                        <tr>
                            <th align="left">Description:</th>
                            <td><input type="submit" name="DO_ACTION" value="Do action" style="visibility:hidden"></td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr>
                <td><textarea name="desc" cols="35" rows="4"></textarea></td>
            </tr>
        </table>
        </th>
        </tr>
        <tr>
        <?php
                } ?>
        <td>
            <table>
                <tr>
                    <th></th>
                    <th>HP</th>
                    <th>MP</th>
                </tr>
                <?php
                foreach (array_keys($team->characters) as $index) {
                    $mychar = $team->characters[$index];
                    $hp = $mychar->get_current('HP');
                    $mhp = $mychar->get_base('HP');
                    $mp = $mychar->get_current('MP');
                    $mmp = $mychar->get_base('MP');
                    echo "<tr><th>{$mychar->name}</th><td align=\"center\">{$hp} / {$mhp}</td><td align=\"center\">{$mp} / {$mmp}</td></tr>";
                }
                ?>
            </table>
        </td>
        </tr>
        </table>
        <?php if (isset($character)) {
            echo <<<EOL
            <input type="hidden" name="actorid" value="{$actorid}" />
EOL;
        } ?>
        <input type="submit" name="GO_BACK" value="Return To Last Menu">
    </form>
</body>

</html>