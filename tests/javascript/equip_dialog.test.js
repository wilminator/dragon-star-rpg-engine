'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

// Items as ITEM::all_to_js() gives them (only what the model reads).
const ITEMS = {
    1: { name: 'Potion', menu_pic: 'potion.png', equip_type: [], ammo_type: '' },
    2: { name: 'Sword', menu_pic: 'sword.png', equip_type: ['hand'], ammo_type: '' },
    3: { name: 'Armor', menu_pic: 'armor.png', equip_type: ['body'], ammo_type: '' },
    4: { name: 'Long Bow', menu_pic: 'bow.png', equip_type: ['lhand', 'rhand'], ammo_type: 'arrow' },
    5: { name: 'Arrows', menu_pic: 'arrows.png', equip_type: ['ammo'], ammo_type: 'arrow' },
    6: { name: 'Sling', menu_pic: 'sling.png', equip_type: ['hand'], ammo_type: 'stone' },
};

// Values from the scripts' own context: compare by content, not realm.
const eq = (actual, expected, message) => assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected, message);

const SLOTS = ['lhand', 'lammo', 'rhand', 'rammo', 'larm', 'rarm', 'body', 'head', 'back', 'feet'];

function hero(worn = {}, inventory = [2, 3, 4, 5, 1, 6]) {
    const equipment = Object.fromEntries(SLOTS.map((slot) => [slot, null]));
    return { equipment: { ...equipment, ...worn }, inventory: inventory.map((item) => ({ item, qty: item === 5 ? 20 : 1 })) };
}

function context(extra = {}) {
    return loadBrowserScripts(['dialogs.js', 'equip_dialog.js', 'status_dialog.js'], extra);
}

function model(ctx, h, using) {
    return new ctx.EquipModel(h, ITEMS, using);
}

// --- the model ---

test('a one-handed weapon can go in either hand, and each hand is its own side', () => {
    const ctx = context();
    const m = model(ctx, hero(), 0);
    eq(SLOTS.filter((s) => m.can_choose(s)), ['lhand', 'rhand']);
    eq(m.fills('rhand'), ['rhand']);
    assert.equal(m.target('lhand'), 0, 'the server reads 0 as the left');
    assert.equal(m.target('rhand'), 1, 'and 1 as the right (the old menu sent these the wrong way round)');
});

test('putting something where something is worn takes that off', () => {
    const ctx = context();
    const m = model(ctx, hero({ lhand: 0 }), 5); // a sling into the left hand
    eq(m.empties('lhand'), ['lhand']);
    eq(m.empties('rhand'), []);
});

test('moving a worn item to the other hand takes it off the first', () => {
    const ctx = context();
    const m = model(ctx, hero({ lhand: 0 }), 0);
    eq(m.empties('rhand'), ['lhand']);
});

test('a two-handed weapon fills both hands and takes off whatever is in either', () => {
    const ctx = context();
    const m = model(ctx, hero({ rhand: 0, body: 1 }), 2);
    eq(m.fills('lhand'), ['lhand', 'rhand']);
    eq(m.empties('lhand'), ['rhand']);
});

test('ammo only goes where a weapon that takes it is held, and a two-handed weapon keeps it on the left', () => {
    const ctx = context();
    const none = model(ctx, hero(), 3);
    eq(SLOTS.filter((s) => none.can_choose(s)), [], 'no bow, nowhere for arrows');
    const sling = model(ctx, hero({ rhand: 5 }), 3);
    eq(SLOTS.filter((s) => sling.can_choose(s)), [], 'a sling takes stones, not arrows');
    const bow = model(ctx, hero({ lhand: 2, rhand: 2 }), 3);
    assert.ok(bow.can_choose('rammo'));
    eq(bow.fills('rammo'), ['lammo']);
});

test('taking off a weapon takes its ammo too', () => {
    const ctx = context();
    const m = model(ctx, hero({ lhand: 2, rhand: 2, lammo: 3, body: 1 }), -1);
    eq(SLOTS.filter((s) => m.can_choose(s)), ['lhand', 'lammo', 'rhand', 'body']);
    eq(m.empties('rhand'), ['lhand', 'rhand', 'lammo']);
    eq(m.fills('rhand'), []);
    assert.equal(m.target('body'), 1, 'taking off names the inventory item');
});

test('a weapon that takes ammo asks for it next; ammo itself does not', () => {
    const ctx = context();
    assert.equal(model(ctx, hero(), 2).needs_ammo(), true);
    assert.equal(model(ctx, hero(), 3).needs_ammo(), false);
    assert.equal(model(ctx, hero(), 0).needs_ammo(), false);
    assert.equal(model(ctx, hero(), -1).needs_ammo(), false);
});

test('nothing but equipment can be put on', () => {
    const ctx = context();
    eq(SLOTS.filter((s) => model(ctx, hero(), 4).can_choose(s)), []);
});

// --- the dialog ---

function recordingView() {
    const view = { effect: null, description: null, renders: 0 };
    view.render = () => { view.renders++; };
    view.show_effect = (slot, fills, empties) => { view.effect = { slot, fills, empties }; };
    view.clear_effect = () => { view.effect = null; };
    view.set_description = (text) => { view.description = text; };
    return view;
}

function dialog(ctx, m, extra = {}) {
    const view = recordingView();
    const chosen = [];
    const highlights = [];
    let cancelled = 0;
    const d = new ctx.EquipDialog({
        model: m, view,
        on_choose: (slot) => chosen.push(slot),
        on_cancel: () => cancelled++,
        on_highlight: (slot) => highlights.push(slot),
        ...extra,
    });
    return { d, view, chosen, highlights, cancelled: () => cancelled };
}

test('highlighting shows what a pick fills and empties, and says where it is', () => {
    const ctx = context();
    const { d, view, highlights } = dialog(ctx, model(ctx, hero({ lhand: 5 }), 0));
    d.highlight('lhand');
    eq(view.effect, { slot: 'lhand', fills: ['lhand'], empties: ['lhand'] });
    assert.equal(view.description, 'Left hand: Sling');
    d.highlight('body');
    assert.equal(d.highlighted, 'lhand', "a slot that can't be picked isn't highlighted");
    d.unhighlight('rhand');
    assert.equal(d.highlighted, 'lhand', 'leaving another row changes nothing');
    d.unhighlight('lhand');
    assert.equal(view.effect, null);
    eq(highlights, ['lhand', null]);
});

test('arrow keys move to the nearest pickable slot that way; Enter picks', () => {
    const ctx = context();
    const { d, chosen } = dialog(ctx, model(ctx, hero({ rhand: 0, body: 1, rammo: 4 }), -1));
    assert.equal(ctx.dialog_nearest(ctx.EQUIP_LAYOUT_DEFAULT, 'rhand', 'ArrowDown', ['rhand', 'rammo', 'body']), 'rammo');
    assert.ok(d.handle_key('ArrowDown'));
    assert.equal(d.highlighted, 'rhand', 'the first key highlights the first pickable slot');
    d.handle_key('ArrowDown');
    assert.equal(d.highlighted, 'rammo');
    d.handle_key('ArrowDown');
    assert.equal(d.highlighted, 'rammo', 'nothing further down: stays');
    d.handle_key('ArrowRight');
    assert.equal(d.highlighted, 'body');
    d.handle_key('Enter');
    eq(chosen, ['body']);
    assert.equal(d.handle_key('x'), false);
});

test('a slot that cannot be picked is refused; Escape through the stack cancels', () => {
    const ctx = context();
    const { d, chosen, cancelled } = dialog(ctx, model(ctx, hero(), 0));
    assert.equal(d.choose('body'), false);
    eq(chosen, []);
    ctx.dialog_open(d);
    ctx.dialog_key('Escape');
    assert.equal(cancelled(), 1);
});

// --- status and benefits ---

// Just enough of the DOM for the dialogs to build into.
function fakeDocument() {
    const make = (tag) => {
        const el = {
            tagName: tag.toUpperCase(), children: [], style: {}, attributes: {}, className: '', textContent: '',
            appendChild(child) { this.children.push(child); child.parentNode = this; return child; },
            removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parentNode = null; },
            get firstChild() { return this.children[0] || null; },
            setAttribute(name, value) { this.attributes[name] = value; },
            focus() {},
        };
        return el;
    };
    return { createElement: make, body: make('body') };
}

// Lets every pending promise callback run (answers cross from the test's
// realm into the scripts' one, which takes a few turns).
function settled() {
    return new Promise((resolve) => setImmediate(resolve));
}

function allText(el) {
    return [el.textContent, ...el.children.map(allText)].join(' ');
}

function find(el, predicate) {
    if (predicate(el)) return el;
    for (const child of el.children) {
        const found = find(child, predicate);
        if (found) return found;
    }
    return null;
}

function button(el, label) {
    return find(el, (e) => e.tagName === 'BUTTON' && e.textContent === label);
}

const SHEET = {
    charid: 7, name: 'Sir Test', job: 'Fighter', level: 3, exp: 40, need: 100,
    stats: { HP: [20, 30], MP: [5, 5], Strength: 12 },
    equipment: { lhand: 'Sword', body: null },
};

test('the status dialog shows the sheet the server sends, and closes', async () => {
    const document = fakeDocument();
    const ctx = context({ document });
    const calls = [];
    const status = new ctx.StatusDialog({ charid: 7, call: (...args) => { calls.push(args); return Promise.resolve([SHEET]); } });
    await status.open();
    eq(calls, [['character_sheet', 7]]);
    const text = allText(status.frame.element);
    for (const expected of ['Sir Test', 'Level 3 Fighter', 'Experience 40 / 100', '20 / 30', 'Left hand', 'Sword']) {
        assert.ok(text.includes(expected), `shows ${expected}`);
    }
    assert.equal(ctx.dialog_top(), status);
    button(status.frame.element, 'Close').onclick();
    assert.equal(ctx.dialog_top(), null);
    assert.equal(status.frame.element.parentNode, null, 'removed from the page');
});

test("an answer that comes after the dialog closed is dropped", async () => {
    const document = fakeDocument();
    const ctx = context({ document });
    let answer;
    const status = new ctx.StatusDialog({ charid: 7, call: () => new Promise((resolve) => { answer = resolve; }) });
    const opened = status.open();
    status.cancel();
    answer([SHEET]);
    await opened;
    assert.ok(!allText(status.frame.element).includes('Sir Test'));
});

test('benefit rows compare the most HP and MP and each stat', () => {
    const ctx = context();
    const rows = ctx.benefit_rows({ before: { HP: [20, 30], Strength: 12 }, after: { HP: [20, 35], Strength: 10 } });
    eq(rows, [
        { stat: 'HP', before: 30, after: 35, change: 5 },
        { stat: 'Strength', before: 12, after: 10, change: -2 },
    ]);
});

test('the equip flow out of combat: pick, see the benefits, confirm', async () => {
    const document = fakeDocument();
    const ctx = context({ document });
    const preview = { before: { Strength: 12 }, after: { Strength: 17 }, fills: ['body'], empties: ['body'] };
    const calls = [];
    const committed = [];
    let answered;
    const call = (...args) => { calls.push(args); answered = Promise.resolve([7, args[2], args[3], preview]); return answered; };
    const m = model(ctx, hero({ body: 1 }), 1);
    const d = new ctx.EquipDialog({ view: recordingView() });
    let shown = 0;
    ctx.equip_out_of_combat({ dialog: d, model: m, charid: 7, call, show: () => shown++, hide: () => shown--, on_commit: (slot, mm) => committed.push([slot, mm.target(slot)]) });
    assert.equal(shown, 1);
    assert.equal(ctx.dialog_top(), d);

    d.choose('body');
    await settled();
    eq(calls, [['preview_equipment', 7, 1, 'body']]);
    const benefits = ctx.dialog_top();
    assert.notEqual(benefits, d, 'the benefits dialog is on top');
    const text = allText(benefits.frame.element);
    assert.ok(text.includes('+5'), 'shows the change');
    assert.ok(text.includes('Comes off: Armor'), 'names what comes off');

    ctx.dialog_key('Escape');
    assert.equal(ctx.dialog_top(), d, 'Back returns to the equip dialog');
    eq(committed, []);

    d.choose('body');
    await settled();
    ctx.dialog_key('Enter');
    eq(committed, [['body', 0]]);
    assert.equal(ctx.dialog_top(), null, 'both dialogs closed');
    assert.equal(shown, 0);
});

test("the benefits dialog won't confirm a pick the server refuses", async () => {
    const document = fakeDocument();
    const ctx = context({ document });
    const confirmed = [];
    const b = new ctx.BenefitsDialog({
        charid: 7, slot: 'body', model: model(ctx, hero(), 0),
        call: () => Promise.resolve([7, 0, 'body', null]),
        on_confirm: () => confirmed.push(1),
    });
    await b.open();
    assert.ok(allText(b.frame.element).includes("can't go there"));
    assert.equal(b.confirm(), false);
    assert.equal(b.handle_key('Enter'), false);
    eq(confirmed, []);
});
