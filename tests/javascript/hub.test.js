'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');
const { fakeDocument, allText, button, settled } = require('./helpers/fake_dom.js');

const eq = (actual, expected, message) => assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected, message);

function hero(charid, name) {
    return { charid, name, job: 'Fighter', level: 2, hp: [10, 12], mp: [3, 3], picture: '', team: 1 };
}

function state(groups, teams = [{ teamid: 1, name: 'Wolves', gold: 100, inn_price: 7 }], needs = {}) {
    return { teams, groups, alliance: null, can_rest: true, needs };
}

// A pretend server holding the formation, answering the hub's calls.
const SLOTS = ['lhand', 'lammo', 'rhand', 'rammo', 'larm', 'rarm', 'body', 'head', 'back', 'feet'];

function sheet(charid, name, worn = {}) {
    return { charid, name, job: 'Fighter', level: 2, exp: 0, need: 100, stats: { Strength: 5 },
        equipment: {}, worn: { ...Object.fromEntries(SLOTS.map((slot) => [slot, null])), ...worn },
        inventory: [{ item: 2, qty: 1 }, { item: 1, qty: 3 }, { item: 3, qty: 1 }] };
}

function field_options(charid) {
    return { actor: { charid, name: 'Ann', hp: [10, 12], mp: [3, 3] },
        items: [
            { index: 0, name: 'Draught', qty: 2, desc: 'Heals.', use: { usable: true, why: '', aim: 'hero', living: true } },
            { index: 1, name: 'Rock', qty: 1, desc: 'A rock.', use: null },
        ],
        spells: [
            { kind: 'spell', index: 1, name: 'Mend All', mp: 5, desc: '', usable: true, why: '', aim: 'party', living: true },
            { kind: 'spell', index: 0, name: 'Fire', mp: 5, desc: '', usable: false, why: "Can't be used outside a fight.", aim: 'hero', living: true },
        ],
        abilities: [] };
}

// What Store::hub_dialog() writes for a shop selling potions, swords and armor.
const SHOP_TEXT = 'Welcome to the shop!\n'
    + '`label,start``switch,Buy,buy,Sell,sell,Leave,bye`What can I do for you?\n`jump,bye`'
    + '`label,buy``vend,1,,2,,3,,start`Take a look.\n'
    + '`label,sell``hawk,75,start`What have you got?\n'
    + '`label,bye`Come back soon!';

function server(initial) {
    let goods = [
        { charid: 2, hero: 'Bo', teamid: 1, index: 0, itemid: 2, name: 'Sword', qty: 1, each: 30 },
        { charid: 1, hero: 'Ann', teamid: 1, index: 1, itemid: 1, name: 'Potion', qty: 4, each: 7.5 },
    ];
    let alliance = { balance: 3, status: '', hint: '', max_join: 2, minutes: 60,
        choices: [{ action: 'form', label: 'Form an alliance', teamid: null, desc: 'Bring more of your teams along.' }],
        formable: [[2, 'Owls'], [3, 'Hawks'], [4, 'Bears']] };
    const manage = {
        heroes: [
            { charid: 1, name: 'Ann', level: 12, job: 'Marine', job_ok: true, look: 'Puffin', look_ok: true, picture: 'puffin.png', can_change_job: true, kept: 12, can_change_look: false },
            { charid: 2, name: 'Bo', level: 1, job: 'No longer offered', job_ok: false, look: 'No longer offered', look_ok: false, picture: '', can_change_job: true, kept: 100, can_change_look: true },
        ],
        jobs: [[3, 'Banshee'], [4, 'Marine']],
        looks: [[1008, 'Snuggles', 'snuggles.png'], [1009, 'Puffin', 'puffin.png']],
    };
    const sheets = { 1: sheet(1, 'Ann'), 2: sheet(2, 'Bo', { body: 2 }), 3: sheet(3, 'Cy') };
    const calls = [];
    let current = initial;
    const call = (method, ...args) => {
        calls.push([method, ...args]);
        if (method === 'hub_state') return Promise.resolve([current]);
        if (method === 'move_hero') {
            const [g, p, direction] = args;
            const groups = current.groups.map((group) => group.slice());
            const [moved] = groups[g].splice(p, 1);
            if (direction === 'l') groups[g].splice(p - 1, 0, moved);
            if (direction === 'r') groups[g].splice(p + 1, 0, moved);
            if (direction === 'd') (groups[g + 1] ||= []).splice(p, 0, moved);
            if (direction === 'u') groups[g - 1].splice(p, 0, moved);
            current = state(groups.filter((group) => group.length > 0), current.teams);
            return Promise.resolve([current]);
        }
        if (method === 'rest_at_inn') return Promise.resolve([null, current]);
        if (method === 'store_open') return Promise.resolve([SHOP_TEXT]);
        if (method === 'store_goods') return Promise.resolve([goods]);
        if (method === 'store_buy') return Promise.resolve([null, 'Ann bought 3 Potion for 30 gold.', current]);
        if (method === 'store_sell') {
            goods = goods.filter((good) => !(good.charid === args[0] && good.index === args[1]));
            return Promise.resolve([null, 'Bo sold 1 Sword for 30 gold.', current]);
        }
        if (method === 'alliance_view') return Promise.resolve([alliance]);
        if (method === 'alliance_act') {
            alliance = { balance: 1, status: 'Allied: Wolves, Owls \u2014 60 minutes left.', hint: '', choices: [], formable: [], max_join: 2, minutes: 60 };
            return Promise.resolve([null, 'Your alliance is formed.', alliance, current]);
        }
        if (method === 'manage_options') return Promise.resolve([manage]);
        if (method === 'change_hero') {
            manage.heroes[0].job = 'Banshee';
            return Promise.resolve([null, "Ann's new job: Banshee.", manage, current]);
        }
        if (method === 'field_options') return Promise.resolve([field_options(args[0])]);
        if (method === 'use_thing') return Promise.resolve([null, 'Ann uses a Draught.', current, field_options(args[0])]);
        if (method === 'character_sheet') return Promise.resolve([sheets[args[0]] || null]);
        if (method === 'preview_equipment') {
            const [charid, index, slot] = args;
            return Promise.resolve([charid, index, slot, { before: { Strength: 5 }, after: { Strength: 9 }, fills: [slot], empties: [] }]);
        }
        if (method === 'change_equipment') {
            const [charid, index, slot] = args;
            const sheet = JSON.parse(JSON.stringify(sheets[charid]));
            sheet.worn[slot] = index === -1 ? null : index;
            sheets[charid] = sheet;
            return Promise.resolve([null, 'Ann equips the Sword.', sheet]);
        }
        return Promise.reject(new Error('unexpected ' + method));
    };
    return { call, calls, current: () => current };
}

// html_list_menu.php's window: eight rows of picture, name and number,
// with text nodes like the page's '.' placeholders.
function listWindow(document) {
    const text = (el) => {
        const node = { data: '', children: [], textContent: '', replaceData(start, length, value) { this.data = value; } };
        Object.defineProperty(el, 'firstChild', { get: () => node });
        return el;
    };
    const add = (id, tag = 'div', withText = false) => {
        const el = document.createElement(tag);
        el.id = id;
        if (withText) text(el);
        win.appendChild(el);
        return el;
    };
    const win = document.createElement('div');
    win.id = 'menuwindow';
    document.body.appendChild(win);
    for (let i = 0; i < 8; i++) {
        add('menurow' + i);
        add('menupic' + i, 'img');
        add('menuitem' + i, 'div', true);
        add('menuqty' + i, 'div', true);
    }
    add('menudesc', 'div', true);
    add('menulast', 'img');
    add('menunext', 'img');
    add('menucanc', 'img');
}

// The name shown in each visible row of the list window.
function windowRows(document) {
    const rows = [];
    for (let i = 0; i < 8; i++) {
        if (document.getElementById('menurow' + i).style.visibility !== 'hidden') {
            rows.push(document.getElementById('menuitem' + i).firstChild.data);
        }
    }
    return rows;
}

async function started(initial = state([[hero(1, 'Ann'), hero(2, 'Bo')], [hero(3, 'Cy')]])) {
    const document = fakeDocument();
    listWindow(document);
    const ctx = loadBrowserScripts(
        ['object_api.js', 'dialogs.js', 'dialog_text.js', 'status_dialog.js', 'equip_dialog.js', 'quantity_dialog.js', 'conversation_dialog.js', 'menu_dialog.js', 'list_window.js', 'hub.js'],
        { document, getComputedStyle: () => ({ fontSize: '16px', lineHeight: '24px', width: '560px', height: '216px' }),
          push_image: () => {},
          items: { 1: { name: 'Potion', equip_type: [], ammo_type: '', price: 10, menu_pic: 'potion.png', description: 'Heals.' },
                   2: { name: 'Sword', equip_type: ['hand'], ammo_type: '', price: 40, menu_pic: 'sword.png', description: 'Sharp.' },
                   3: { name: 'Armor', equip_type: ['body'], ammo_type: '', price: 500, menu_pic: 'armor.png', description: 'Heavy.' } } });
    const make = (id) => { const el = document.createElement('div'); el.id = id; document.body.appendChild(el); return el; };
    const srv = server(initial);
    const visited = [];
    await ctx.hub_start({
        stage: make('stage'), party: make('party'), menu: make('menu'), status: make('status'),
        call: srv.call, navigate: (url) => visited.push(url), text_delay: 0,
    });
    return { ctx, document, srv, visited };
}

const names = (ctx) => ctx.hub.state.groups.map((group) => group.map((h) => h.name));

test('the hub draws the party in its groups, with the purse', async () => {
    const { ctx, document } = await started();
    const party = document.getElementById('party');
    const text = allText(party);
    assert.ok(text.includes('Wolves: 100 gold'));
    assert.ok(text.includes('Ann') && text.includes('Cy'));
    eq(Object.keys(ctx.hub.view.tiles), ['0;0', '0;1', '1;0']);
    assert.equal(ctx.hub.view.tiles['0;0'].getAttribute('aria-label'), 'Ann, level 2 Fighter, HP 10 of 12, MP 3 of 3');
});

test('with no team picked, the hub sends the player to pick one', async () => {
    const { visited } = await started(null);
    eq(visited, ['pick_team.php']);
});

test('menu options that leave the app go to their pages', async () => {
    const { ctx, visited } = await started();
    ctx.hub.menu.choose_id('team_select');
    ctx.hub.menu.choose_id('fight_history');
    eq(visited, ['pick_team.php', 'fight_history.php?teamid=1']);
});

// The fight frame's page, as the hub reads it after each load.
function landed(frame, path, search = '') {
    frame.contentWindow = { location: { pathname: '/dse/' + path, search } };
    frame.onload();
}

test('Fight opens the fight in a frame over the hub, and its end brings the hub back', async () => {
    const { ctx, srv, document, visited } = await started();
    ctx.hub.menu.choose_id('fight');
    const frame = ctx.hub.fight_frame;
    assert.equal(frame.tagName, 'IFRAME');
    assert.equal(frame.src, 'init_fight.php');
    assert.equal(frame.parentNode, document.body);
    landed(frame, 'rpg.php');
    assert.equal(ctx.hub.fight_frame, frame, 'the fight page stays');
    const before = srv.calls.length;
    await ctx.hub_fight_over('setup_fight.php');
    assert.equal(ctx.hub.fight_frame, null);
    assert.equal(frame.parentNode, null, 'the frame is gone');
    assert.deepEqual(srv.calls.slice(before), [['hub_state']], 'the party is read again (HP, gold, XP)');
    eq(visited, []);
});

test('a fight frame sent anywhere but a fight page closes, and team selection opens in the whole window', async () => {
    const { ctx, visited } = await started();
    ctx.hub.menu.choose_id('public_fight');
    assert.equal(ctx.hub.fight_frame.src, 'init_fight.php?public');
    landed(ctx.hub.fight_frame, 'setup_fight.php');
    await settled();
    assert.equal(ctx.hub.fight_frame, null, 'no fight could start: back to the hub');
    assert.equal(ctx.hub.menu.choose_id('monster_fight'), false, 'fighting as the monsters is on team selection now');
    ctx.hub.menu.choose_id('fight');
    landed(ctx.hub.fight_frame, 'monster_queue.php');
    await settled();
    assert.equal(ctx.hub.fight_frame, null, 'the queue is no fight page for the hub');
    eq(visited, ['monster_queue.php']);
    visited.length = 0;
    await ctx.hub_fight_over('pick_team.php');
    eq(visited, ['pick_team.php']);
});

test('a player who comes back mid-fight gets the fight over the hub', async () => {
    const document = fakeDocument();
    const ctx = loadBrowserScripts(['dialogs.js', 'dialog_text.js', 'status_dialog.js', 'equip_dialog.js', 'quantity_dialog.js', 'conversation_dialog.js', 'menu_dialog.js', 'hub.js'], { document });
    const make = (id) => { const el = document.createElement('div'); el.id = id; document.body.appendChild(el); return el; };
    const visited = [];
    await ctx.hub_start({ stage: make('stage'), party: make('party'), menu: make('menu'), status: make('status'),
        call: () => Promise.resolve([null]), navigate: (url) => visited.push(url), resume_fight: true });
    assert.equal(ctx.hub.fight_frame.src, 'rpg.php');
    eq(visited, [], 'no team picked (a monster player) is fine while the fight is on');
});

test('the hero list goes where the arrows say', async () => {
    const { ctx } = await started();
    ctx.hub.menu.choose_id('status');
    const picker = ctx.dialog_top();
    eq(picker.list.rows.map((r) => r.cells[0]), ['Ann', 'Bo', 'Cy']);
    ctx.dialog_key('ArrowDown');
    assert.equal(picker.list.highlighted, 1);
    ctx.dialog_key('Escape');
    assert.equal(ctx.dialog_top(), null);
});

test('Status opens the status dialog for the hero picked', async () => {
    const { ctx, srv } = await started();
    ctx.hub.menu.choose_id('status');
    ctx.dialog_key('Enter');
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['character_sheet', 1]);
    assert.equal(ctx.dialog_top().constructor.name, 'StatusDialog');
});

test('Equip: a hero, an item, a slot, the benefits, then the change', async () => {
    const { ctx, srv, document } = await started();
    ctx.hub.menu.choose_id('equip');
    ctx.dialog_key('Enter'); // Ann
    await settled();
    const box = ctx.dialog_top();
    eq(box.list.rows.map((r) => r.cells[0]), ['Sword', 'Armor'], 'only what can be worn');
    ctx.dialog_key('Enter'); // Sword
    const equip = ctx.dialog_top();
    assert.equal(equip, ctx.hub.equip_dialog);
    ctx.dialog_key('ArrowDown'); // the first slot it can go: lhand
    assert.equal(equip.highlighted, 'lhand');
    ctx.dialog_key('Enter');
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['preview_equipment', 1, 0, 'lhand']);
    ctx.dialog_key('Enter'); // Equip
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['change_equipment', 1, 0, 'lhand']);
    assert.equal(document.getElementById('status').textContent, 'Ann equips the Sword.');
    assert.equal(ctx.dialog_top(), box, 'back to the list, redrawn');
    eq(box.list.rows.map((r) => r.cells), [['Sword', 'Left hand'], ['Armor', ''], ['Take something off', '']]);
});

test('formation by keyboard: pick a hero up, move them, and the highlight follows', async () => {
    const { ctx, srv } = await started();
    ctx.hub.menu.choose_id('formation');
    assert.equal(ctx.hub.formation.key, '0;0');
    ctx.dialog_key('ArrowRight');
    assert.equal(ctx.hub.formation.key, '0;1', 'not held: the highlight moves');
    ctx.dialog_key('Enter');
    assert.ok(ctx.hub.formation.held);
    ctx.dialog_key('ArrowLeft');
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['move_hero', 0, 1, 'l']);
    eq(names(ctx), [['Bo', 'Ann'], ['Cy']]);
    assert.equal(ctx.hub.formation.key, '0;0', 'Bo is highlighted where Bo went');
    ctx.dialog_key('ArrowDown');
    await settled();
    eq(names(ctx), [['Ann'], ['Bo', 'Cy']]);
    assert.equal(ctx.hub.formation.key, '1;0');
    ctx.dialog_key('Escape');
    assert.equal(ctx.hub.formation.held, false, 'Escape puts the hero down first');
    ctx.dialog_key('Escape');
    assert.equal(ctx.hub.formation, null);
    assert.equal(ctx.dialog_top(), null);
});

test('formation by mouse: click a hero, then its arrows', async () => {
    const { ctx, srv } = await started();
    ctx.hub.menu.choose_id('formation');
    ctx.hub.view.tiles['1;0'].click();
    assert.ok(ctx.hub.formation.held);
    button(ctx.hub.view.tiles['1;0'], '▲').click();
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['move_hero', 1, 0, 'u']);
    eq(names(ctx), [['Cy', 'Ann', 'Bo']]);
});

test('outside formation, clicking a hero shows their status', async () => {
    const { ctx, srv } = await started();
    ctx.hub.view.tiles['0;1'].click();
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['character_sheet', 2]);
});

test('the inn names the price, and resting reports back', async () => {
    const { ctx, srv, document } = await started();
    assert.ok(ctx.hub_inn_text(ctx.hub.state).includes("A night's rest is 7 gold."));
    const two = state([[hero(1, 'Ann')]], [{ teamid: 1, name: 'A', gold: 1, inn_price: 5 }, { teamid: 2, name: 'B', gold: 1, inn_price: 3 }]);
    assert.ok(ctx.hub_inn_text(two).includes('5 gold for A and 3 gold for B'));
    const inn = ctx.hub_inn();
    await settled();
    ctx.dialog_key('Enter'); // Yes
    await inn.done;
    assert.deepEqual(srv.calls.at(-1), ['rest_at_inn']);
    assert.ok(allText(inn.text).includes('You wake refreshed'));
    assert.equal(document.getElementById('status').textContent, 'Your team has rested.');
});

test('Items: use one on a hero; the list stays for the next', async () => {
    const { ctx, srv, document } = await started();
    ctx.hub.menu.choose_id('items');
    ctx.dialog_key('Enter'); // Ann's items
    await settled();
    const box = ctx.dialog_top();
    eq(box.list.rows.map((r) => r.cells), [['Draught', '\u00d72'], ['Rock', '\u00d71']]);
    ctx.dialog_key('Enter'); // Draught
    const what = ctx.dialog_top();
    eq(what.list.rows.map((r) => [r.cells[0], r.selectable]), [['Use', true], ['Transfer', true], ['Discard', true]]);
    ctx.dialog_key('Enter'); // Use
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // on Bo
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['use_thing', 1, 'item', 0, 0, 1, 0]);
    assert.equal(document.getElementById('status').textContent, 'Ann uses a Draught.');
    assert.equal(ctx.dialog_top(), box, 'back to the items');
});

test('Items: something with no use can still be transferred or discarded', async () => {
    const { ctx, srv } = await started();
    ctx.hub.menu.choose_id('items');
    ctx.dialog_key('Enter');
    await settled();
    ctx.dialog_top().list.choose(1); // Rock
    const what = ctx.dialog_top();
    eq(what.list.rows.map((r) => [r.cells[0], r.selectable]), [['Use', false], ['Transfer', true], ['Discard', true]]);
    assert.equal(what.list.highlighted, 1, 'starts on Transfer');
    ctx.dialog_key('Enter'); // Transfer
    const to = ctx.dialog_top();
    eq(to.list.rows.map((r) => [r.cells[0], r.selectable]), [['Ann', false], ['Bo', true], ['Cy', true]]);
    ctx.dialog_key('Enter'); // Bo
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['use_thing', 1, 'give', 1, 0, 1, 0]);
});

test('Items: discarding asks how many of a stack, or to confirm just one', async () => {
    const { ctx, srv } = await started();
    ctx.hub.menu.choose_id('items');
    ctx.dialog_key('Enter');
    await settled();
    ctx.dialog_top().list.choose(0); // Draught x2
    ctx.dialog_top().list.choose(2); // Discard
    const qty = ctx.dialog_top();
    assert.equal(qty.value, 2, 'all of them to start');
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter');
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['use_thing', 1, 'discard', 0, null, null, 1]);

    ctx.dialog_top().list.choose(1); // Rock
    ctx.dialog_top().list.choose(2); // Discard
    const sure = ctx.dialog_top();
    eq(sure.list.rows.map((r) => r.cells[0]), ['Yes, throw it away', 'No, keep it']);
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // No
    await settled();
    assert.deepEqual(srv.calls.at(-1)[2], 'discard', 'nothing new sent');
    assert.equal(srv.calls.filter((c) => c[2] === 'discard').length, 1);
});

test('Spells: a spell for the whole party needs no target; one that can\'t be cast is greyed', async () => {
    const { ctx, srv } = await started();
    ctx.hub.menu.choose_id('spells');
    ctx.dialog_key('Enter');
    await settled();
    const box = ctx.dialog_top();
    eq(box.list.rows.map((r) => [r.cells[0], r.cells[1], r.selectable]), [['Mend All', '5 MP', true], ['Fire', '5 MP', false]]);
    ctx.dialog_key('Enter'); // Mend All
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['use_thing', 1, 'spell', 1, null, null, 0]);
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Fire: refused
    assert.equal(ctx.dialog_top(), box);
});

test('Abilities: a hero with none says so', async () => {
    const { ctx, document } = await started();
    ctx.hub.menu.choose_id('abilities');
    ctx.dialog_key('Enter');
    await settled();
    assert.ok(document.getElementById('menudesc').firstChild.data.includes('knows none.'));
});

test("the lists use combat's list window, and close it on cancel", async () => {
    const { ctx, document } = await started();
    ctx.hub.menu.choose_id('items');
    ctx.dialog_key('Enter');
    await settled();
    const win = document.getElementById('menuwindow');
    assert.equal(win.style.visibility, 'visible');
    eq(windowRows(document), ['Draught', 'Rock']);
    assert.equal(document.getElementById('menuqty0').firstChild.data, '\u00d72');
    ctx.list_window_select(1); // a click on the window's second row: Rock
    assert.equal(ctx.dialog_top().list.rows[0].cells[0], 'Use', 'the item menu opened');
    ctx.dialog_key('Escape');
    ctx.list_window_cancel(); // the window's own Cancel
    assert.equal(win.style.visibility, 'hidden');
    assert.equal(ctx.dialog_top(), null);
});

test('the shop: buy from the vend list, for a hero whose team can pay, several at once', async () => {
    const { ctx, srv, document } = await started();
    await ctx.hub_shop();
    await settled();
    ctx.dialog_key('Enter'); // Buy
    await settled();
    eq(windowRows(document), ['Potion', 'Sword', 'Armor']);
    const vend = ctx.dialog_top();
    eq(vend.list.rows.map((r) => [r.cells[1], r.selectable]), [['10 g', true], ['40 g', true], ['500 g', false]], 'armor is more than 100 gold');
    ctx.dialog_key('Enter'); // Potion
    ctx.dialog_key('Enter'); // for Ann
    const qty = ctx.dialog_top();
    assert.equal(qty.max, 10, '100 gold buys ten at 10');
    ctx.dialog_key('3');
    ctx.dialog_key('Enter');
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['store_buy', 1, 1, 3]);
    assert.equal(document.getElementById('status').textContent, 'Ann bought 3 Potion for 30 gold.');
    assert.equal(ctx.dialog_top(), vend, 'back to the wares');

    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Sword: equipment, one at a time
    ctx.dialog_key('Enter'); // for Ann
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['store_buy', 2, 1, 1]);

    ctx.dialog_key('Escape'); // done buying: the talk goes on
    await settled();
    assert.equal(ctx.dialog_top(), ctx.hub.shop);
    assert.ok(ctx.hub.shop.choices, 'Buy, Sell or Leave again');
});

test('the shop: sell from the hawk list, asking how many of a stack', async () => {
    const { ctx, srv, document } = await started();
    await ctx.hub_shop();
    await settled();
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Sell
    await settled();
    eq(windowRows(document), ['Bo: Sword', 'Ann: Potion \u00d74']);
    const hawk = ctx.dialog_top();
    eq(hawk.list.rows.map((r) => r.cells[1]), ['30 g', '8 g']);
    ctx.dialog_key('Enter'); // Bo's sword: are you sure?
    ctx.dialog_key('Enter'); // Yes
    await settled();
    assert.deepEqual(srv.calls.slice(-2), [['store_sell', 2, 0, 1], ['store_goods']]);
    eq(windowRows(document), ['Ann: Potion \u00d74'], 'the list is read again');
    ctx.dialog_key('Enter'); // Ann's potions: how many?
    const qty = ctx.dialog_top();
    assert.equal(qty.value, 4);
    assert.ok(allText(qty.frame.element).includes('Total: 30 gold'));
    ctx.dialog_key('Escape');
    ctx.dialog_key('Escape'); // done selling
    await settled();
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Leave
    await ctx.hub.shop.done;
    assert.ok(allText(ctx.hub.shop.text).includes('Come back soon!'));
    assert.equal(ctx.dialog_top(), null);
});

test('Manage team: a new job, confirmed with what it costs', async () => {
    const { ctx, srv, document } = await started();
    ctx.hub.menu.choose_id('manage_team');
    await settled();
    const box = ctx.dialog_top();
    eq(box.list.rows.map((r) => [r.cells[0], r.cells[1], r.colors[1]]), [['Ann', 'Lv 12 Marine', null], ['Bo', 'Lv 1 No longer offered', 'red']]);
    ctx.dialog_key('Enter'); // Ann
    const what = ctx.dialog_top();
    eq(what.list.rows.map((r) => [r.cells[0], r.selectable]), [['New job', true], ['New look', false]]);
    ctx.dialog_key('Enter'); // New job
    const jobs = ctx.dialog_top();
    eq(jobs.list.rows.map((r) => [r.cells[0], r.selectable]), [['Banshee', true], ['Marine', false]]);
    ctx.dialog_key('Enter'); // Banshee
    const sure = ctx.dialog_top();
    assert.equal(sure.list.rows[0].desc, 'Back to level 1, keeping 12% of their stats.');
    ctx.dialog_key('Enter'); // Yes
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['change_hero', 1, 'job', 3]);
    assert.equal(document.getElementById('status').textContent, "Ann's new job: Banshee.");
    assert.equal(ctx.dialog_top(), box);
    assert.equal(box.list.rows[0].cells[1], 'Lv 12 Banshee', 'redrawn');
});

test('Manage team: a hero whose job and look are gone picks new ones, with no cost to confirm', async () => {
    const { ctx, srv } = await started();
    ctx.hub.menu.choose_id('manage_team');
    await settled();
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Bo
    ctx.dialog_key('Enter'); // New job
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Marine: free, sent at once
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['change_hero', 2, 'job', 4]);
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Bo again
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // New look
    const looks = ctx.dialog_top();
    eq(looks.list.rows.map((r) => [r.cells[0], r.pic]), [['Snuggles', 'snuggles.png'], ['Puffin', 'puffin.png']]);
    ctx.dialog_key('Enter');
    await settled();
    assert.deepEqual(srv.calls.at(-1), ['change_hero', 2, 'look', 1008]);
});

test('the hub says who needs a new job or look', async () => {
    const { document } = await started(state([[hero(1, 'Ann')]], undefined, { Ann: ['job', 'look'] }));
    assert.equal(document.getElementById('status').textContent, 'Ann needs a new job and look \u2014 see Manage team.');
});

test('no fighting while a hero needs a new job or look', async () => {
    const { ctx, visited } = await started(state([[hero(1, 'Ann')]], undefined, { Ann: ['job'] }));
    assert.equal(ctx.hub.menu.choose_id('fight'), false);
    assert.equal(ctx.hub.menu.choose_id('public_fight'), false);
    assert.ok(!ctx.hub.fight_frame, 'no fight opened');
    eq(visited, []);
});

test('Alliance: pick up to two teams, then form it', async () => {
    const { ctx, srv, document } = await started();
    ctx.hub.menu.choose_id('alliance');
    await settled();
    const box = ctx.dialog_top();
    assert.ok(allText(box.element).includes('Tokens: 3'));
    ctx.dialog_key('Enter'); // Form an alliance
    const chooser = ctx.dialog_top();
    eq(chooser.list.rows.map((r) => [r.cells[0], r.selectable]), [['Owls', true], ['Hawks', true], ['Bears', true], ['Form the alliance', false]]);
    ctx.dialog_key('Enter'); // Owls
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Hawks
    eq(chooser.list.rows.map((r) => [r.cells.join(' '), r.selectable]),
        [['Owls \u2713', true], ['Hawks \u2713', true], ['Bears ', false], ['Form the alliance 2 tokens', true]], 'two is the most');
    ctx.dialog_key('Enter'); // Hawks again: leaves
    assert.equal(chooser.list.rows[3].cells[1], '1 token');
    chooser.list.choose(3); // Form it
    await settled();
    eq(srv.calls.at(-1), ['alliance_act', 'form', [2]]);
    assert.equal(document.getElementById('status').textContent, 'Your alliance is formed.');
    assert.equal(ctx.dialog_top(), box);
    assert.ok(allText(box.element).includes('Allied: Wolves, Owls'));
});
