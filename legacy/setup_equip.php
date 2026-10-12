<?php
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';

#Prep the game objects.
require_once INCLUDE_GAME . 'party.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'field_functions.php';

session_start();

#Do LOGIN stuff :)
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_membership_access('dse', AUTH_LOGIN, 'index.php');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

//New way!
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'party_state.php';
$db = get_database();
$fight_communicator = new FightCommunicator($db, $userid);
if ($fight_communicator->is_in_active_fight()) {
    header('Location: rpg.php');
    exit;
}

# The party the player has set up (PartyState).
$party = PartyState::party($db, (int) $userid);
if ($party === null) {
    header('Location: pick_team.php');
    exit;
}

#Check for a cancel
if (isset($_POST['GO_BACK'])) {
    header('Location: setup_fight.php');
    exit;
}



#Break down the hero's items and equipment.
$group = (int)$_GET['group'];
$position = (int)$_GET['position'];

$status = '';

#Break down the hero's items and equipment.
$actor_target = new ActionTarget(0, $group, $position);
$character = $actor_target->get_character($party);
$job_name = JOB::get($character->jobid)->name;
$personality_name = PERSONALITY::get($character->personalityid)->name;

//Check for equipping.
for ($index = 0; $index < count($character->inventory); $index++) {
    if (isset($_POST["equip$index"])) {
        switch ($_POST["equip$index"]) {
            case 'Unequip':
                $recipient_target = new ActionTarget($index, null, null);
                $actionindex = -1;
                break;
            case 'Equip':
            case 'Equip Left':
            case 'Equip Right':
                $recipient_target = new ActionTarget($_POST["equip$index"] == 'Equip Left' ? 0 : 1, null, null);
                $actionindex = $index;
                break;
        }
        $status = perform_field_action($party, 3, $actionindex, $actor_target, $recipient_target);
        // Save every character in the party, in case anything changed above.
        $party->store_party($db);
        break;
    }
}


//Refresh, since equip/action handling above may have changed things.
$character = $actor_target->get_character($party);

//Build item list
$item_list = array();
$full_item_list = array();
$qty_list = array();
foreach ($character->inventory as $index => $inventory_slot) {
    $itemid = $inventory_slot['item'];
    $item = ITEM::get($itemid);
    if ($item->use_effect_spec->effect != 0)
        $item_list[] = array($index, $item->name, $item->usable_in_field());
    $full_item_list[] = array($index, $item->name, true);
    $qty_list[] = $inventory_slot['qty'];
}

//Build spell list
$spell_list = array();
$skill_list = array();
foreach ($character->abilities as $index => $ability) {
    $myability = ABILITY::get($ability);
    if ($myability->type == ABILITY::TYPE_SKILL) //is skill
        $skill_list[] = array($index, "{$myability->name} ($myability->mp_used)", $myability->usable_in_field() & ($character->current['MP'] >= $myability->mp_used));
    else //is spell
        $spell_list[] = array($index, "{$myability->name} ($myability->mp_used)", $myability->usable_in_field() & ($character->current['MP'] >= $myability->mp_used));
}

//Build fake list
$fake_list = array();
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
        var character = party.groups[<?php echo $group; ?>].characters[<?php echo $position; ?>];

        var action = null;
        var ability = null;
        var range = null;
        var effect = null;
        var group = null;
    </script>
</head>

<body>
    <h1 style="color:orange"><?php echo $status; ?></h1>
    <form method="post" name="form_data">
        <table>
            <tr>
                <th valign="top">
                    <table width="100%">
                        <caption><b><?php echo $character->name; ?> - Level <?php echo $character->level; ?> <?php echo $job_name; ?></b></caption>
                        <tr>
                            <th>XP Earned</th>
                            <td><?php echo $character->exp; ?>/<?php echo $character->need; ?></td>
                            <th>PXP Rating</th>
                            <td><?php echo $character->pxp; ?></td>
                        </tr>
                    </table>
                    <table width="100%">
                        <?php
                        echo "<tr>\n";
                        foreach ($GLOBALS['character_stats'] as $key)
                            echo "<th>$key</th>\n";
                        echo "</tr>\n<tr>\n";
                        foreach ($GLOBALS['character_stats'] as $key) {
                            $value = $character->base[$key];
                            echo "<td align=\"center\">";
                            if (in_array($key, array('HP', 'MP')))
                                echo $character->current[$key] . '/' . $character->get_base($key);
                            else
                                echo $value;
                            echo "</td>\n";
                        }
                        echo "</tr>\n<tr>\n";
                        foreach ($GLOBALS['character_stats'] as $key) {
                            $value = $character->base[$key];
                            echo "<td align=\"center\">";
                            if (count(array_keys_strict($character->equipment, null)) != count($character->equipment)) {
                                $character->command = 1;
                                $base = $character->get_current($key);
                                $stat1 = $base - $value;
                                if ($stat1 >= 0) $stat1 = '+' . $stat1;
                                if (!in_array($key, array('HP', 'MP')))
                                    echo "$base ($stat1)";
                                else {
                                    $stat1 = $character->get_base($key) - $value;
                                    if ($stat1 >= 0) $stat1 = '+' . $stat1;
                                    echo "($stat1)";
                                }
                                if (
                                    in_array($key, array('Accuracy', 'Strength', 'Speed'))
                                    && $character->equipment['lhand'] !== $character->equipment['rhand']
                                ) {
                                    $character->command = 0;
                                    $base = $character->get_current($key);
                                    $stat2 = $base - $value;
                                    if ($stat2 >= 0) $stat2 = '+' . $stat2;
                                    echo "<br>$base ($stat2)";
                                }
                            }

                            echo "</td>\n";
                        }
                        echo "</tr>\n";
                        ?>
                    </table>
                    <table>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Where Equipped</th>
                            <th>Action</th>
                        </tr>
                        <?php
                        foreach (array_keys($character->inventory) as $index) {
                            $inventory_slot = $character->inventory[$index];
                            $itemid = $inventory_slot['item'];
                            $item = ITEM::get($itemid);
                            $qty = $inventory_slot['qty'];
                        ?>
                            <tr>
                                <td><?php echo ($qty > 0 ? $item->name : ''); ?></td>
                                <td align="center"><?php echo ($qty > 1 ? $qty : ''); ?></td>
                                <td align="center"><?php
                                                    #This here will list where an item is equipped.
                                                    $keys = array_keys_strict($character->equipment, $index);
                                                    echo implode(', ', $keys);
                                                    ?></td>
                                <td align="center"><?php
                                                    #This here will list button(s) or link(s) if the item is equippable.
                                                    if (count($keys) > 0)
                                                        echo "<input type=\"submit\"name=\"equip$index\" value=\"Unequip\">";
                                                    elseif (!is_null($item->equip_type) && count($item->equip_type) > 0) {
                                                        if (count(array_intersect($item->equip_type, array('hand', 'arm'))) > 0)
                                                            echo "<input type=\"submit\" name=\"equip$index\" value=\"Equip Left\"><input type=\"submit\" name=\"equip$index\" value=\"Equip Right\">";
                                                        elseif (count(array_intersect($item->equip_type, array('ammo'))) > 0) {
                                                            if (
                                                                !is_null($character->equipment['lhand'])
                                                                && $item->ammo_type == ITEM::get($character->inventory[$character->equipment['lhand']]['item'])->ammo_type
                                                                && $character->equipment['lhand'] != $character->equipment['rhand']
                                                            )
                                                                echo "<input type=\"submit\" name=\"equip$index\" value=\"Equip Left\">";
                                                            if (
                                                                !is_null($character->equipment['rhand'])
                                                                && $item->ammo_type == ITEM::get($character->inventory[$character->equipment['rhand']]['item'])->ammo_type
                                                            )
                                                                echo "<input type=\"submit\" name=\"equip$index\" value=\"Equip Right\">";
                                                        } else
                                                            echo "<input type=\"submit\" name=\"equip$index\" value=\"Equip\">";
                                                    }
                                                    ?></td>
                            </tr>
                        <?php
                        }
                        ?>
                    </table>
                </th>
            </tr>
            <tr>
                <td>
                    <input type="submit" name="GO_BACK" value="Return To Last Menu">
                </td>
            </tr>
        </table>
    </form>
</body>

</html>