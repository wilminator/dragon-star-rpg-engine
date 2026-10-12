function display_fight_dialog(state = true){
    const fight_dialog_obj = document.getElementById('fightdialog');
    if (state) {
        object_show(fight_dialog_obj);
    }
    else {
        object_hide(fight_dialog_obj);
    }
}

async function process_fight_dialog(text) {
    display_fight_dialog();
    await render_dialog('fightdialogtext', 'fightdialognext', text);
    display_fight_dialog(false);
}