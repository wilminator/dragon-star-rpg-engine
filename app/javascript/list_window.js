// The list window: html_list_menu.php's eight rows with a picture, a name
// and a number, a description box and last / next / cancel buttons.
// Combat's spell, skill and item lists draw into it (list_menu.js), and so
// do the hub's item, spell and ability lists (hub.js).
//
//   list_window_view   a SelectionList view (dialogs.js) for the window
//   list_window_list   the SelectionList using the window now; the
//                      window's own rows and buttons call it through
//                      list_window_*()
//
// Needs object_api.js and dialogs.js.

var list_window_list = null;

function list_window_set_text(id, text)
    {
    var node = object_get(id).firstChild;
    node.replaceData(0, node.data.length, text == null ? '' : String(text));
    }

var list_window_view = {
    show_row: function (slot, row)
        {
        object_default_visibility(object_get('menurow' + slot));
        object_set_image(object_get('menupic' + slot), row.pic);
        list_window_set_text('menuitem' + slot, row.cells[0]);
        list_window_set_text('menuqty' + slot, row.cells[1]);
        object_color(object_get('menuqty' + slot), row.colors[1] != null ? row.colors[1] : '');
        },
    hide_row: function (slot)
        {
        object_hide(object_get('menurow' + slot));
        },
    highlight_row: function (slot, row)
        {
        object_background_color(object_get('menurow' + slot), row.selectable === false ? 'rgb(96,0,0)' : 'rgb(0,96,0)');
        },
    unhighlight_row: function (slot)
        {
        object_background_color(object_get('menurow' + slot), '');
        },
    set_description: function (text)
        {
        list_window_set_text('menudesc', text);
        },
    set_paging: function (has_previous, has_next)
        {
        var object = object_get('menulast');
        if (has_previous) object_default_visibility(object); else object_hide(object);
        object = object_get('menunext');
        if (has_next) object_default_visibility(object); else object_hide(object);
        }
    };

// --- the window's own handlers (html_list_menu.php) ---

function list_window_highlight(slot)
    {
    if (list_window_list) list_window_list.highlight(slot);
    }

function list_window_unhighlight(slot)
    {
    if (list_window_list) list_window_list.unhighlight(slot);
    }

function list_window_select(slot)
    {
    return list_window_list ? list_window_list.choose(slot) : false;
    }

function list_window_next()
    {
    if (list_window_list) list_window_list.next_page();
    }

function list_window_last()
    {
    if (list_window_list) list_window_list.previous_page();
    }

function list_window_cancel()
    {
    if (list_window_list) list_window_list.cancel();
    }

function highlight_list_menu_icon(object)
    {
    object_border(object, 'solid white');
    object_z(object, 30);
    }

function unhighlight_list_menu_icon(object)
    {
    object_border(object, 'solid black');
    object_z(object, 25);
    }
