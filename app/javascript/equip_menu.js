// Combat's use of the equip dialog (equip_dialog.js): the hero picked
// "Equip" and an item (new_using, or -1 to take something off); the
// dialog picks the slot, and this turns the pick into the hero's
// command -- or asks for ammo first, for a weapon that takes it.
//
// The functions below keep the names the fight page's HTML and
// menu_control.js call.

var new_equip_location;
var combat_equip = null;

function combat_equip_dialog()
    {
    if (combat_equip === null)
        {
        combat_equip = new EquipDialog({
            view: new EquipDomView(typeof item_images !== 'undefined' ? item_images : '', typeof equip_points !== 'undefined' ? equip_points : undefined),
            layout: typeof equip_layout !== 'undefined' ? equip_layout : EQUIP_LAYOUT_DEFAULT,
            on_choose: equip_menu_selection,
            on_cancel: equip_menu_cancelled,
            sounds: {select: 'select', cancel: 'cancel'},
        });
        }
    return combat_equip;
    }

// Builds the dialog for the current hero and item (menu_control.js).
function equip_menu_render()
    {
    var hero = get_hero(player_party, curr_hero[0], curr_hero[1]);
    combat_equip_dialog().set_model(new EquipModel(hero, items, new_using, typeof equip_points !== 'undefined' ? equip_points : undefined));
    equip_window_dialog = combat_equip_dialog(); // the window's rows call it
    }

// A slot was picked.
function equip_menu_selection(slot, model)
    {
    var hero = get_hero(player_party, curr_hero[0], curr_hero[1]);
    new_equip_location = model.target(slot);
    if (model.needs_ammo())
        {
        list_menu_add_equipable_ammo(hero, model.item().ammo_type);
        new_command = COMMAND_EQUIP_AMMO;
        set_menu_state(MENU_STATE_FIGHT_LIST);
        }
    else
        {
        hero.command = new_command;
        hero.using = new_using;
        hero.target = [new_equip_location, 0, 0];
        display_action(curr_hero[0], curr_hero[1]);
        display_target(curr_hero[0], curr_hero[1]);
        set_menu_state(MENU_STATE_FIGHT_PLAYER);
        }
    }

function equip_menu_cancelled()
    {
    target_cancel();
    set_menu_state(MENU_STATE_FIGHT_PLAYER);
    }

// The equip window's rows and cancel button call equip_window_*()
// (equip_dialog.js), which reach combat_equip through equip_window_dialog.
