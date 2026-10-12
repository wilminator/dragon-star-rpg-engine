<?php
#Prep the game objects.
define ('INCLUDE_GAME','include/');
require_once INCLUDE_GAME.'errorlog.php';

require_once INCLUDE_GAME.'team.php';
require_once INCLUDE_GAME.'party.php';
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'party_state.php';

session_start();

#Do LOGIN stuff :)
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_membership_access('dse',AUTH_LOGIN,'index.php');
redirect_on_hold($userid,'dse','on_hold.php','../index.php');

#Check for a cancel
if(isset($_POST['GO_BACK']))
    {
    unset($_SESSION['max_gold']);
    header('Location: setup_fight.php');
    exit;
    }

# The team the player is playing (PartyState).
$db = get_database();
$teamid = PartyState::lead_teamid($db, (int) $userid);
if ($teamid === null)
    {
    header('Location: pick_team.php');
    exit;
    }
$team=TEAM::load_for_gameplay($db, $teamid);

if(!isset($_SESSION['max_gold']) || $team->gold>$_SESSION['max_gold'])
    $_SESSION['max_gold']=$team->gold;

#List heroes and appraise their equipment.
foreach(array_keys($team->characters) as $index)
    {
    $recipient_list[$team->characters[$index]->charid]=$team->characters[$index]->name;
    foreach($team->characters[$index]->inventory as $inventory_slot){
        $itemid = $inventory_slot['item'];
        $item = ITEM::get($itemid);
        if($_SESSION['max_gold']<$item->price) {
            $_SESSION['max_gold']=$item->price;
            }
        }
    }

#Do purchase and set onload
if(isset($_POST['buy']))
    {
    // The hero is looked up on $team itself, so store_team() saves the
    // item along with the gold. (It used to be put on the session's copy
    // of the hero, which store_team() never saw: the gold went and the
    // item didn't stay.)
    $qty=max(0,(int)$_POST['buy']);
    $index=(int)$_POST['recipient'];
    $itemid=(int)$_POST['item'];
    $character=null;
    foreach($team->characters as $member)
        if($member->charid===$index)
            $character=$member;
    try { $item = ITEM::get($itemid); } catch (\Throwable $e) { $item = null; }
    if($character!==null && $item!==null && $qty>0 && $item->price*$qty<=$team->gold)
        {
        $remaining=$character->add_item($itemid,$qty);
        $team->gold-=$item->price*($qty-$remaining);
        $team->store_team($db);
        }
    $onload="onload=\"show_ware($itemid);document.form_data.recipient.value=$index;select_recipient(document.form_data.recipient);\"";
    }
else
    $onload='';

# For the page's hero list, after any purchase above.
$party = PartyState::party($db, (int) $userid); // for the page's hero list

function filter_items_by_price(int $key)
    {
    if($key==0)
        return false;
    return ($_SESSION['max_gold']>=ITEM::get($key)->price);
    }

function order_items_by_price_then_name(int $a, int $b): int
    {
    $item_a= ITEM::get($a);
    $item_b= ITEM::get($b);
    $retval= $item_a->price-$item_b->price;
    if($retval!=0) return $retval;
    return strcasecmp($item_a->name,$item_b->name);
    }

$saleable=array_filter(array_keys(ITEM::load_all_from_cache()),'filter_items_by_price');
usort($saleable,'order_items_by_price_then_name');

$equip_list=[];
$use_list=[];
foreach($saleable as $itemid)
    {
    $item = ITEM::get($itemid);
    $equip_list[$itemid]=$item->describe_equip();
    $use_list[$itemid]=$item->describe_use();
    }
?>
<html>
<head>
<script>
var items=<?php echo ITEM::all_to_js(); ?>;
var party=<?php echo json_encode($party); ?>;
var equip_list=<?php echo json_encode($equip_list); ?>;
var use_list=<?php echo json_encode($use_list); ?>;
var gold=<?php echo $team->gold; ?>;

var myware=null;
var recipient=null;

function show_ware(ware)
    {
    myware=ware;
    document.form_data.item.value=ware;
    document.form_data.itemname.value=items[ware].name;
    document.form_data.desc.value=items[ware].description;
    document.getElementById('item_data').style.visibility='visible';
    document.getElementById('hero_data').style.visibility='hidden';
    if(items[ware].equip_type)
        {
        document.form_data.equip.value=equip_list[ware];
        document.getElementById('equip_table').style.display='block';
        }
    else
        document.getElementById('equip_table').style.display='none';
    if(items[ware].effect!=0)
        {
        document.form_data.use.value=use_list[ware];
        document.getElementById('use_table').style.display='block';
        }
    else
        document.getElementById('use_table').style.display='none';

    document.getElementById('recipient').style.visibility=(items[ware].price<=gold?'visible':'hidden');
    toggle_buy_buttons();
    }

function toggle_buy_buttons()
    {
    if(!document.form_data.recipient.value)
        {
        document.getElementById('buy1').style.visibility='hidden';
        document.getElementById('buy10').style.visibility='hidden';
        document.getElementById('buy100').style.visibility='hidden';
        }
    else
        {
        document.getElementById('buy1').style.visibility=(items[myware].price<=gold?'visible':'hidden');
        document.getElementById('buy10').style.visibility=(items[myware].price*10<=gold?'visible':'hidden');
        document.getElementById('buy100').style.visibility=(items[myware].price*100<=gold?'visible':'hidden');
        }
    }
    
function purchase_qty(qty)
    {
    document.form_data.buy.value = qty;
    document.form_data.submit();
    }

function get_character(charid)
    {
    for(group=0;group<party.groups.length;group++)
        for(character=0;character<party.groups[group].characters.length;character++)
            if(party.groups[group].characters[character].charid==charid)
                return party.groups[group].characters[character];
    return null;
    }

function list_inventory(hero)
    {
    var character=get_character(hero);
    var inventory=character.inventory;
    for(index=0;index<12;index++)
        if(inventory && inventory[index])
            {
            if(inventory[index].item>1)
                document.getElementById('item'+index.toString()).value=items[inventory[index].item].name+' ('+inventory[index].qty.toString()+')';
            else
                document.getElementById('item'+index.toString()).value=items[inventory[index].item].name;
            }
        else
            document.getElementById('item'+index.toString()).value='';
    }

function select_recipient(object)
    {
    recipient=parseInt(object.value);
    toggle_buy_buttons();
    list_inventory(recipient);
    document.getElementById('hero_data').style.visibility='visible';
    }
</script>
</head>
<body <?php echo $onload; ?>>
<form name="form_data" method="post">
<table width="90%">
  <tr><td colspan="2"><b>Gold: <?php echo $team->gold; ?></b></td></tr>
  <tr>
      <td valign="top">
        <table>
        <?php
        foreach($saleable as $ware){
            $item = ITEM::get($ware);
            echo "
          <tr onclick=\"show_ware($ware);\">
            <td>{$item->price} gold</td>
            <td><img src=\"".ITEM_IMAGES_DIR."{$item->menu_pic}\"></td>
            <td>{$item->name}</td>
          </tr>\n";
        }
        ?>
        </table>
      </td>
      <td valign="top">
        <table id="item_data" style="visibility:hidden;border:1px solid">
          <tr>
            <td colspan="4" align="center"><b>Item</b> <input style="border:0px none" name="itemname"></td>
          </tr>
          <tr><th colspan="4">Description</th></tr>
          <tr><td colspan="4"><textarea style="border:0px none" name="desc" cols="50" rows="3"></textarea></td></tr>
          <tr>
            <td colspan="4">
              <table id="equip_table" style="display:none">
                <tr><th>Equipment Bonuses</th></tr>
                <tr><td><textarea style="border:0px none" name="equip" cols="50" rows="3"></textarea></td></tr>
              </table>
            </td>
          </tr>
          <tr>
            <td colspan="4">
              <table id="use_table" style="display:none">
                <tr><th>Use Effect</th></tr>
                <tr><td><textarea style="border:0px none" name="use" cols="50" rows="3"></textarea></td></tr>
              </table>
            </td>
          </tr>
          <tr>
            <td align="center" id="recipient">
              <?php make_select("recipient",'',$recipient_list,array('size'=>4,'onkeypress'=>'select_recipient(this);','onclick'=>'select_recipient(this);'));?>
              <input type="hidden" name="item">
              <input type="hidden" name="buy">
            </td>
            <td align="center" id="buy1"><img src="<?= MENU_IMAGES_DIR ?>buy1.png" alt="Buy 1" onclick="purchase_qty(1);"></td>
            <td align="center" id="buy10"><img src="<?= MENU_IMAGES_DIR ?>buy10.png" alt="Buy 10" onclick="purchase_qty(10);"></td>
            <td align="center" id="buy100"><img src="<?= MENU_IMAGES_DIR ?>buy100.png" alt="Buy 100" onclick="purchase_qty(100);"></td>
          </tr>
        </table>
        <table id="hero_data" style="visibility:hidden;border:1px solid">
          <?php
          for($index=0;$index<ceil(CHARACTER_MAX_ITEMS/2);$index++)
              {
              echo "<tr>\n";
              echo '<td><input id="item'.$index.'" readonly="readonly" size="30" style="border:0px none"></td>';
              if($index+ceil(CHARACTER_MAX_ITEMS/2)<CHARACTER_MAX_ITEMS)
                 echo '<td><input id="item'.($index+ceil(CHARACTER_MAX_ITEMS/2)).'" readonly="readonly" size="30" style="border:0px none"></td>';
              echo "\n</tr>\n";
              }
          ?>
        </table>
      </td>
  </tr>
  <tr><td colspan="2"><input type="submit" name="GO_BACK" value="Finished Shopping"></td></tr>
</table>
</form>
</body>
</html>