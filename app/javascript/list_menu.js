// Combat's list of spells, skills and items: a SelectionList (dialogs.js)
// drawn into the list window html_list_menu.php puts on the fight screen
// (list_window.js; the hub uses the same window).
// These functions keep the names the page's buttons and the rest of the
// combat code call. list_menu_items and list_menu_position mirror the
// list's rows and page (equip_menu.js reads them).
var list_menu_items=[];
var list_menu_position=0;
var _combat_list=null;

function combat_list()
    {
    if(_combat_list===null)
        _combat_list=new SelectionList({
            page_size:8,
            view:list_window_view, // list_window.js
            sounds:{hover:'hover',select:'select',cancel:'cancel'},
            on_choose:function(row,index){ list_menu_sync(); list_menu_selection(index); },
            on_cancel:list_menu_cancelled,
            on_change:list_menu_sync
            });
    return _combat_list;
    }

function list_menu_sync()
    {
    list_menu_items=combat_list().rows;
    list_menu_position=combat_list().position;
    }

function list_menu_selection(choice)
    {
    var hero=get_hero(player_party,curr_hero[0],curr_hero[1]);
    //choice is the index number from the list menu.
    //Based on new_command, we now determine how to handle targeting.
    switch(new_command)
        {
        case COMMAND_USE_ITEM: //Items
            new_using=list_menu_items[choice].value
            var item=items[hero.inventory[new_using].item];
            list_menu_target_effect(item.use_targets,item.effect);
            break;
        case COMMAND_EQUIP_ITEM: //Equip new weapon
            new_using=list_menu_items[choice].value
            //Now pick a location for the equipment
            set_menu_state(MENU_STATE_FIGHT_EQUIP);
            break;
        case COMMAND_EQUIP_AMMO: //Equipping weapon and ammo!!
            hero.command=new_command;
            hero.using=new_using;
            new_command=null;
            new_using=null;
            hero.target=[new_equip_location,list_menu_items[choice].value,0];
            display_action(curr_hero[0],curr_hero[1]);
            display_target(curr_hero[0],curr_hero[1]);
            relay_player_commands(player_party,curr_hero[0],curr_hero[1]);
            set_menu_state(MENU_STATE_FIGHT_PLAYER);
            break;
        case COMMAND_USE_SKILL: //Spells and skills are the same.
        case COMMAND_CAST_SPELL:
            new_using=list_menu_items[choice].value
            var ability=abilities[hero.abilities[new_using]];
            list_menu_target_effect(ability.targets,ability.effect);
            break;
        }
    }

function list_menu_target_effect(targets,effect)
    {
    var hero=get_hero(player_party,curr_hero[0],curr_hero[1]);
    if(targets==-2)
        {
        hero.command=new_command;
        hero.using=new_using;
        hero.target=[(is_effect_bad(effect)?1-player_party:player_party),0,0];
        new_command=null;
        new_using=null;
        set_menu_state(MENU_STATE_FIGHT_PLAYER);
        relay_player_commands(player_party,curr_hero[0],curr_hero[1]);
        }
    else
        {
        target_range=targets;
        target_dead=only_living_targets(effect)?0:1;
        find_target=is_effect_bad(effect)?2:1;
        set_menu_state(MENU_STATE_FIGHT_TARGET);
        }
    display_action(curr_hero[0],curr_hero[1]);
    display_target(curr_hero[0],curr_hero[1]);
    return true;
    }

function list_menu_render()
    {
    combat_list().render();
    list_menu_sync();
    }

function list_menu_highlight(index)
    {
    combat_list().highlight(index);
    }

function list_menu_unhighlight(index)
    {
    combat_list().unhighlight(index);
    }

// The list's Cancel button (and Escape).
function list_menu_cancel()
    {
    combat_list().cancel();
    }

function list_menu_cancelled()
    {
    new_command=null;
    new_using=null;
    set_menu_state(MENU_STATE_FIGHT_PLAYER);
    }

function list_menu_next()
    {
    combat_list().next_page();
    list_menu_sync();
    }

function list_menu_last()
    {
    combat_list().previous_page();
    list_menu_sync();
    }

function list_menu_select(choice)
    {
    if (menu_state!=MENU_STATE_FIGHT_LIST)
        set_menu_state(MENU_STATE_FIGHT_PLAYER);
    return combat_list().choose(choice);
    }

function list_menu_reset()
    {
    combat_list().clear();
    list_menu_sync();
    }

function list_menu_add_item(value,pic,item,qty,qtycolor,desc,selectable)
    {
    combat_list().add_row({value:value,pic:pic,cells:[item,qty],colors:[null,qtycolor],desc:desc,selectable:selectable});
    list_menu_sync();
    }

function list_menu_add_spells(person)
    {
    list_menu_reset();
    var index;
    var ability;

    for(index in person.abilities)
        {
        ability=abilities[person.abilities[index]];
        if(ability.type==0)
            {
            if(person.current.MP<ability.mp_used)
                list_menu_add_item(index,ability_images+ability.menu_pic,ability.name,ability.mp_used.toString()+' MP','red',ability.description,false);
            else
                list_menu_add_item(index,ability_images+ability.menu_pic,ability.name,ability.mp_used.toString()+' MP',null,ability.description,true);
            }
        }
    list_menu_render();
    }

function list_menu_add_skills(person)
    {
    list_menu_reset();
    var index;
    var ability;

    for(index in person.abilities)
        {
        ability=abilities[person.abilities[index]];
        if(ability.type==1)
            if(person.current.MP<ability.mp_used)
                list_menu_add_item(index,ability_images+ability.menu_pic,ability.name,ability.mp_used.toString()+' MP','red',ability.description,false);
            else
                list_menu_add_item(index,ability_images+ability.menu_pic,ability.name,ability.mp_used.toString()+' MP',null,ability.description,true);
        }
    list_menu_render();
    }

function list_menu_add_useable_items(person)
    {
    list_menu_reset();
    var index;
    var item,qty;

    for(index in person.inventory)
        {
        item=items[person.inventory[index].item];
        if (item.effect!=0)
            {
            qty=person.inventory[index].qty;
            if(qty==0)
                list_menu_add_item(index,item_images+item.menu_pic,item.name,qty.toString(),'red',item.description,false);
            else
                list_menu_add_item(index,item_images+item.menu_pic,item.name,qty.toString(),null,item.description,true);
            }
        }
    list_menu_render();
    }

function list_menu_add_equipable_items(person)
    {
    list_menu_reset();
    var index;
    var item,qty;

    for(index in person.inventory)
        {
        item=items[person.inventory[index].item];
        if (item.equip_type!=null)
            {
            qty=person.inventory[index].qty;
            if(qty==0)
                list_menu_add_item(index,item_images+item.menu_pic,item.name,qty.toString(),'red',item.description,false);
            else
                list_menu_add_item(index,item_images+item.menu_pic,item.name,qty.toString(),null,item.description,true);
            }
        }
    //Now add nothing as a possible equipment choice.
    item=items[0];
    list_menu_add_item(-1,item_images+item.menu_pic,item.name,'',null,item.description,true);
    list_menu_render();
    }

function list_menu_add_equipable_ammo(person,ammo_type)
    {
    list_menu_reset();
    var index;
    var item,qty;

    for(index in person.inventory)
        {
        item=items[person.inventory[index].item];
        if (item.equip_type && item.equip_type.join(',')=='ammo' && item.ammo_type==ammo_type)
            {
            qty=person.inventory[index].qty;
            if(qty==0)
                list_menu_add_item(index,item_images+item.menu_pic,item.name,qty.toString(),'red',item.description,false);
            else
                list_menu_add_item(index,item_images+item.menu_pic,item.name,qty.toString(),null,item.description,true);
            }
        }
    //Now add nothing as a possible equipment choice.
    item=items[0];
    list_menu_add_item(-1,item_images+item.menu_pic,item.name,'',null,item.description,true);
    list_menu_render();
    }

// highlight_list_menu_icon() and unhighlight_list_menu_icon(): list_window.js.

