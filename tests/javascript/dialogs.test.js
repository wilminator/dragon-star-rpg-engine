'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

// A view that records what the list asked it to draw.
function recordingView() {
    const view = { shown: {}, hidden: new Set(), highlighted: null, description: null, paging: null, calls: [] };
    view.show_row = (slot, row) => { view.shown[slot] = row; view.hidden.delete(slot); };
    view.hide_row = (slot) => { delete view.shown[slot]; view.hidden.add(slot); };
    view.highlight_row = (slot) => { view.highlighted = slot; };
    view.unhighlight_row = (slot) => { if (view.highlighted === slot) view.highlighted = null; };
    view.set_description = (text) => { view.description = text; };
    view.set_paging = (previous, next) => { view.paging = [previous, next]; };
    return view;
}

function rows(n, greyed = []) {
    return Array.from({ length: n }, (_, i) => ({ value: i, cells: [`Row ${i}`, `${i}`], desc: `About ${i}`, selectable: !greyed.includes(i) }));
}

function list(options = {}) {
    const ctx = loadBrowserScripts(['dialogs.js']);
    const view = recordingView();
    const chosen = [];
    let cancelled = 0;
    const selection = new ctx.SelectionList({ view, on_choose: (row, index) => chosen.push(index), on_cancel: () => cancelled++, ...options });
    return { ctx, view, selection, chosen, cancelled: () => cancelled };
}

test('a list shows its first page and says whether there are more', () => {
    const { view, selection } = list({ page_size: 4 });
    selection.set_rows(rows(6));
    assert.deepEqual(Object.keys(view.shown).map(Number), [0, 1, 2, 3]);
    assert.deepEqual(view.paging, [false, true]);
    selection.next_page();
    assert.deepEqual(Object.keys(view.shown).map(Number), [0, 1]);
    assert.equal(view.shown[0].cells[0], 'Row 4');
    assert.ok(view.hidden.has(2) && view.hidden.has(3));
    assert.deepEqual(view.paging, [true, false]);
    selection.next_page();
    assert.equal(selection.position, 4, 'no page past the last');
    selection.previous_page();
    assert.equal(selection.position, 0);
});

test('choosing a row reports its index across pages; a greyed row refuses', () => {
    const { selection, chosen } = list({ page_size: 4 });
    selection.set_rows(rows(6, [5]));
    selection.next_page();
    assert.equal(selection.choose(0), true);
    assert.equal(selection.choose(1), false, 'greyed');
    assert.equal(selection.choose(3), false, 'nothing there');
    assert.deepEqual(chosen, [4]);
});

test('highlighting shows the description; arrows, paging and Enter work from the keyboard', () => {
    const { view, selection, chosen } = list({ page_size: 3 });
    selection.set_rows(rows(5));
    assert.equal(selection.handle_key('ArrowDown'), true);
    assert.equal(view.highlighted, 0);
    assert.equal(view.description, 'About 0');
    selection.handle_key('ArrowDown');
    selection.handle_key('ArrowDown');
    selection.handle_key('ArrowDown'); // off the bottom: next page
    assert.equal(selection.position, 3);
    assert.equal(view.highlighted, 0);
    selection.handle_key('ArrowUp');   // off the top: back a page, last row
    assert.equal(selection.position, 0);
    assert.equal(view.highlighted, 2);
    selection.handle_key('Enter');
    assert.deepEqual(chosen, [2]);
    assert.equal(selection.handle_key('x'), false, 'other keys are not the list\'s');
});

test('the stack gives keys to the top dialog, and Escape cancels it', () => {
    const { ctx, selection, cancelled } = list();
    selection.set_rows(rows(3));
    const below = { keys: [], handle_key(key) { this.keys.push(key); return true; } };
    ctx.dialog_open(below);
    ctx.dialog_open(selection);
    assert.equal(ctx.dialog_top(), selection);
    assert.equal(ctx.dialog_key('Escape'), true);
    assert.equal(cancelled(), 1);
    assert.deepEqual(below.keys, [], 'the dialog underneath heard nothing');

    ctx.dialog_close(selection);
    assert.equal(ctx.dialog_top(), below);
    ctx.dialog_key('ArrowDown');
    assert.deepEqual(below.keys, ['ArrowDown']);
    assert.equal(ctx.dialog_close(selection), false, 'already closed');
    ctx.dialog_close(below);
    assert.equal(ctx.dialog_key('Escape'), false, 'nothing open');
});

test('opening a dialog that is already open moves it to the top without reopening it', () => {
    const ctx = loadBrowserScripts(['dialogs.js']);
    let opened = 0, closed = 0;
    const a = { on_open() { opened++; }, on_close() { closed++; } };
    const b = {};
    ctx.dialog_open(a);
    ctx.dialog_open(b);
    ctx.dialog_open(a);
    assert.equal(ctx.dialog_top(), a);
    assert.equal(opened, 1);
    ctx.dialog_close(a);
    assert.equal(closed, 1);
    assert.equal(ctx.dialog_top(), b);
});

test('sounds play through play_sound when asked for', () => {
    const played = [];
    const ctx = loadBrowserScripts(['dialogs.js'], { play_sound: (clip) => played.push(clip), audio_clips: { select: 'S', cancel: 'C', hover: 'H' } });
    const selection = new ctx.SelectionList({ sounds: { select: 'select', cancel: 'cancel', hover: 'hover' }, page_size: 2 });
    selection.set_rows(rows(3));
    selection.next_page();
    selection.choose(0);
    selection.cancel();
    assert.deepEqual(played, ['H', 'S', 'C']);
});

// --- combat's list, on top of SelectionList ---

function combatList() {
    const dom = {};
    const el = (id) => (dom[id] ??= { id, firstChild: { data: '', replaceData(o, n, t) { this.data = t; } }, bg: '', color: '', visible: null, image: null });
    const state = { menu_state: null, selected: [] };
    const ctx = loadBrowserScripts(['dialogs.js', 'list_window.js', 'list_menu.js'], {
        object_get: el,
        object_default_visibility: (o) => { o.visible = true; },
        object_hide: (o) => { o.visible = false; },
        object_set_image: (o, src) => { o.image = src; },
        object_color: (o, c) => { o.color = c; },
        object_background_color: (o, c) => { o.bg = c; },
        MENU_STATE_FIGHT_LIST: 3, MENU_STATE_FIGHT_PLAYER: 1,
        set_menu_state: (s) => { state.menu_state = s; },
    });
    ctx.menu_state = 3;
    return { ctx, dom, state };
}

test('combat\'s list draws into the fight screen\'s rows and keeps its globals in step', () => {
    const { ctx, dom } = combatList();
    ctx.list_menu_reset();
    for (let i = 0; i < 10; i++) {
        ctx.list_menu_add_item(i, `pic${i}.png`, `Spell ${i}`, `${i} MP`, i === 1 ? 'red' : null, `Does ${i}`, i !== 1);
    }
    ctx.list_menu_render();
    assert.equal(ctx.list_menu_items.length, 10);
    assert.equal(dom.menuitem0.firstChild.data, 'Spell 0');
    assert.equal(dom.menuqty1.firstChild.data, '1 MP');
    assert.equal(dom.menuqty1.color, 'red');
    assert.equal(dom.menupic2.image, 'pic2.png');
    assert.equal(dom.menulast.visible, false);
    assert.equal(dom.menunext.visible, true);

    ctx.list_menu_next();
    assert.equal(ctx.list_menu_position, 8, 'equip_menu.js reads this');
    assert.equal(dom.menuitem0.firstChild.data, 'Spell 8');
    assert.equal(dom.menurow2.visible, false);

    ctx.list_menu_highlight(1);
    assert.equal(dom.menudesc.firstChild.data, 'Does 9');
    assert.equal(dom.menurow1.bg, 'rgb(0,96,0)');
});

test('choosing in combat\'s list hands the absolute index to list_menu_selection', () => {
    const { ctx } = combatList();
    const picked = [];
    ctx.list_menu_selection = (index) => picked.push([index, ctx.list_menu_items[index].value]);
    ctx.list_menu_reset();
    for (let i = 0; i < 10; i++) ctx.list_menu_add_item(`v${i}`, '', `Item ${i}`, '1', null, '', i !== 9);
    ctx.list_menu_render();
    ctx.list_menu_next();
    assert.equal(ctx.list_menu_select(0), true);
    assert.equal(ctx.list_menu_select(1), false, 'greyed');
    assert.deepEqual(picked, [[8, 'v8']]);
});

test('Escape in combat\'s list cancels back to choosing a hero', () => {
    const { ctx, state } = combatList();
    ctx.new_command = 4;
    ctx.new_using = 2;
    ctx.list_menu_reset();
    ctx.list_menu_add_item(0, '', 'Potion', '3', null, '', true);
    ctx.list_menu_render();
    ctx.dialog_open(ctx.combat_list());
    ctx.dialog_key('Escape');
    assert.equal(state.menu_state, 1);
    assert.equal(ctx.new_command, null);
    assert.equal(ctx.new_using, null, 'the old typo left this set');
});

test('paging combat\'s list from the keyboard keeps list_menu_position in step', () => {
    const { ctx } = combatList();
    ctx.list_menu_reset();
    for (let i = 0; i < 10; i++) ctx.list_menu_add_item(i, '', `Item ${i}`, '1', null, '', true);
    ctx.list_menu_render();
    ctx.dialog_open(ctx.combat_list());
    ctx.dialog_key('PageDown');
    assert.equal(ctx.list_menu_position, 8);
});
