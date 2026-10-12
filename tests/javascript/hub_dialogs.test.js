'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');
const { fakeDocument, findAll, allText, button, settled } = require('./helpers/fake_dom.js');

const eq = (actual, expected, message) => assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected, message);

function context(extra = {}) {
    const document = fakeDocument();
    const played = [];
    const ctx = loadBrowserScripts(
        ['object_api.js', 'dialogs.js', 'dialog_text.js', 'equip_dialog.js', 'status_dialog.js', 'quantity_dialog.js', 'conversation_dialog.js', 'menu_dialog.js'],
        { document, play_sound: (clip) => played.push(clip), audio_clips: {}, ...extra });
    return { ctx, document, played };
}

// --- the stack's base and focus ---

test('the base gets keys only when no dialog is open', () => {
    const { ctx } = context();
    const keys = [];
    const base = { handle_key: (key) => { keys.push(['base', key]); return true; } };
    const top = { handle_key: (key) => { keys.push(['top', key]); return true; } };
    ctx.dialog_set_base(base);
    ctx.dialog_key('a');
    ctx.dialog_open(top);
    ctx.dialog_key('b');
    ctx.dialog_close(top);
    ctx.dialog_key('c');
    eq(keys, [['base', 'a'], ['top', 'b'], ['base', 'c']]);
    assert.equal(ctx.dialog_top(), null, 'the base is not on the stack');
});

test('closing the dialog on top puts focus back where it was', () => {
    const { ctx, document } = context();
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    const dialog = {};
    ctx.dialog_open(dialog);
    const inside = document.createElement('button');
    document.body.appendChild(inside);
    inside.focus();
    ctx.dialog_close(dialog);
    assert.equal(document.activeElement, opener);
});

// --- quantity ---

test('the quantity stays between the least and the most, and gold caps the most', () => {
    const { ctx } = context();
    const q = new ctx.QuantityDialog({ min: 1, max: 20, price: 30, gold: 100 }).open();
    assert.equal(q.max, 3, '100 gold buys three at 30');
    assert.equal(q.value, 1);
    q.handle_key('ArrowUp');
    q.handle_key('ArrowUp');
    q.handle_key('ArrowUp');
    assert.equal(q.value, 3);
    assert.ok(allText(q.frame.element).includes('Total: 90 gold of 100'));
    q.handle_key('PageDown');
    assert.equal(q.value, 1);
    q.handle_key('End');
    assert.equal(q.value, 3);
    q.handle_key('Home');
    assert.equal(q.value, 1);
    assert.equal(q.number.getAttribute('aria-valuenow'), '1');
});

test('typing digits sets the amount; one past the most starts over', () => {
    const { ctx } = context();
    const q = new ctx.QuantityDialog({ min: 1, max: 25 }).open();
    q.handle_key('2');
    q.handle_key('0');
    assert.equal(q.value, 20);
    q.handle_key('7'); // 207 is too many: starts again at 7
    assert.equal(q.value, 7);
    q.handle_key('Backspace');
    assert.equal(q.value, 1, 'nothing typed: the least');
    q.handle_key('3');
    q.handle_key('ArrowUp');
    q.handle_key('1');
    assert.equal(q.value, 1, 'an arrow ends the typing');
});

test('Enter confirms the amount and closes; Escape cancels', () => {
    const { ctx } = context();
    const confirmed = [];
    let cancelled = 0;
    const q = new ctx.QuantityDialog({ min: 1, max: 5, value: 2, on_confirm: (n) => confirmed.push(n) }).open();
    assert.equal(ctx.dialog_top(), q);
    ctx.dialog_key('ArrowUp');
    ctx.dialog_key('Enter');
    eq(confirmed, [3]);
    assert.equal(ctx.dialog_top(), null);
    assert.equal(q.frame.element.parentNode, null);
    new ctx.QuantityDialog({ on_cancel: () => cancelled++ }).open();
    ctx.dialog_key('Escape');
    assert.equal(cancelled, 1);
});

test("with too little gold for even one, nothing can be confirmed", () => {
    const { ctx } = context();
    const confirmed = [];
    const q = new ctx.QuantityDialog({ price: 50, gold: 20, on_confirm: (n) => confirmed.push(n) }).open();
    assert.ok(q.empty());
    assert.ok(allText(q.frame.element).includes('Not enough gold.'));
    assert.equal(button(q.frame.element, 'OK').disabled, true);
    assert.equal(q.confirm(), false);
    eq(confirmed, []);
});

test('the + and - buttons step the amount', () => {
    const { ctx } = context();
    const q = new ctx.QuantityDialog({ max: 9 }).open();
    button(q.frame.element, '+').click();
    button(q.frame.element, '+').click();
    button(q.frame.element, '−').click();
    assert.equal(q.value, 2);
});

// --- conversation ---

// Runs render_dialog for real against the fake page: no typing delay.
function conversation(ctx, text, options = {}) {
    const ended = [];
    const dialog = new ctx.ConversationDialog({ text, delay: 0, on_end: () => ended.push(1), ...options });
    const done = dialog.open();
    return { dialog, done, ended };
}

function withLayout(extra = {}) {
    // new_render_text_environment() measures the text box.
    return context({
        getComputedStyle: () => ({ fontSize: '16px', lineHeight: '24px', width: '560px', height: '216px' }),
        ...extra,
    });
}

test('a question shows its prompts after its text and goes where the answer says', async () => {
    const { ctx } = withLayout();
    const text = 'Welcome.\n`question,Rest,rest,Leave,bye,out`Stay the night?\n`label,rest`You rest.`jump,end``label,bye`Goodbye.`label,out`Maybe later.';
    const { dialog, done, ended } = conversation(ctx, text);
    await settled();
    assert.ok(dialog.choices, 'the answers are showing');
    const shown = allText(dialog.text);
    assert.ok(shown.includes('Stay the night?'));
    assert.ok(!shown.includes('You rest'), 'nothing past the question yet');
    eq(dialog.choices.rows.map((r) => r.cells[0]), ['Rest', 'Leave']);
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter');
    await done;
    const after = allText(dialog.text);
    assert.ok(after.includes('Goodbye.'));
    assert.ok(!after.includes('You rest'));
    eq(ended, [1]);
    assert.equal(ctx.dialog_top(), null);
});

test("cancelling a question goes to its cancel label; a switch without one goes on", async () => {
    const { ctx } = withLayout();
    const q = conversation(ctx, '`question,Yes,y,No,n,later`Well?\n`label,y`Y`label,n`N`label,later`Later.');
    await settled();
    ctx.dialog_key('Escape');
    await q.done;
    assert.ok(allText(q.dialog.text).endsWith('Later.'));

    const s = conversation(ctx, '`switch,A,a,B,b`Pick.\nNeither.`jump,end``label,a`A.`label,b`B.');
    await settled();
    ctx.dialog_key('Escape');
    await s.done;
    const text = allText(s.dialog.text);
    assert.ok(text.includes('Neither.'));
    assert.ok(!text.includes('A.') && !text.includes('B.'));
});

test('tags the dialog does not know go to on_tag, which can send it elsewhere', async () => {
    const { ctx } = withLayout();
    const seen = [];
    const { dialog, done } = conversation(ctx, '`resurrect,30,full`Revival is 30 gold.`jump,end``label,full`We are closed.', {
        on_tag: (command, parts) => { seen.push([command, ...parts]); return Promise.resolve('full'); },
    });
    await done;
    eq(seen, [['resurrect', '30', 'full']]);
    assert.ok(allText(dialog.text).includes('We are closed.'));
});

test('ack waits for Next, which Enter presses', async () => {
    const { ctx } = withLayout();
    const { dialog, done, ended } = conversation(ctx, 'One.`ack`Two.');
    await settled();
    assert.equal(dialog.next.style.visibility, 'visible');
    assert.ok(!allText(dialog.text).includes('Two.'));
    eq(ended, []);
    ctx.dialog_key('Enter');
    await done;
    assert.ok(allText(dialog.text).includes('Two.'));
    eq(ended, [1]);
});

test('an empty tag shows a backtick', async () => {
    const { ctx } = withLayout();
    const { dialog, done } = conversation(ctx, 'a``b');
    await done;
    assert.ok(allText(dialog.text).includes('a`b'));
});

test('Escape leaves the conversation part way through', async () => {
    const { ctx } = withLayout();
    const { dialog, done, ended } = conversation(ctx, 'Hello.`ack`Never shown.');
    await settled();
    ctx.dialog_key('Escape');
    eq(ended, [1]);
    assert.equal(ctx.dialog_top(), null);
    assert.equal(dialog.frame.element.parentNode, null);
    dialog.next.click(); // the renderer, still waiting, lets go
    await done;
});

// --- menu ---

test('the hub menu lists every option in order, greying out those with no action', () => {
    const { ctx } = context();
    const items = ctx.hub_menu_items({ fight: () => {}, equip: { action: () => {}, enabled: false }, team_select: () => {} });
    eq(items.map((i) => i.id), ['fight', 'public_fight', 'inn', 'shop', 'items', 'spells', 'abilities', 'equip', 'status', 'formation', 'alliance', 'manage_team', 'fight_history', 'settings', 'account', 'team_select']);
    eq(items.filter((i) => i.enabled).map((i) => i.id), ['fight', 'team_select']);
});

test('arrows, letters and Enter drive the menu; greyed options refuse', () => {
    const { ctx, document } = context();
    const chosen = [];
    const container = document.createElement('div');
    document.body.appendChild(container);
    const menu = new ctx.MenuDialog({
        container,
        items: ctx.hub_menu_items({
            fight: () => chosen.push('fight'),
            inn: () => chosen.push('inn'),
            account: () => chosen.push('account'),
        }),
    });
    ctx.dialog_set_base(menu);
    ctx.dialog_key('ArrowDown');
    assert.equal(menu.highlighted, 0);
    assert.equal(document.activeElement, menu.buttons[0], 'focus follows the keys');
    ctx.dialog_key('ArrowUp');
    assert.equal(menu.highlighted, 15, 'wraps to the bottom');
    ctx.dialog_key('i');
    assert.equal(menu.highlighted, 2, 'i: Inn');
    ctx.dialog_key('Enter');
    ctx.dialog_key('a');
    assert.equal(menu.highlighted, 6, 'a: Abilities, the next A');
    ctx.dialog_key('Enter');
    ctx.dialog_key('a');
    assert.equal(menu.highlighted, 10, 'then Alliance');
    ctx.dialog_key('a');
    ctx.dialog_key(' ');
    eq(chosen, ['inn', 'account']);
    assert.equal(menu.buttons[7].getAttribute('aria-disabled'), 'true');
    menu.buttons[0].click();
    eq(chosen, ['inn', 'account', 'fight']);
});

test('refresh re-reads which options can be chosen', () => {
    const { ctx, document } = context();
    let gold = 0;
    const container = document.createElement('div');
    const menu = new ctx.MenuDialog({ container, items: ctx.hub_menu_items({ inn: { action: () => {}, enabled: () => gold >= 10 } }) });
    assert.equal(menu.choose_id('inn'), false);
    gold = 20;
    menu.refresh();
    assert.equal(menu.buttons[2].getAttribute('aria-disabled'), 'false');
    assert.equal(menu.choose_id('inn'), true);
});

test('an inn tag asks Yes or No after its text; Yes lets on_tag do the resting', async () => {
    const { ctx } = withLayout();
    const tags = [];
    const text = '`inn,30,bye`A room is 30 gold. Stay?\n`label,rested`You wake refreshed.`jump,end``label,bye`Farewell.';
    const yes = conversation(ctx, text, { on_tag: (command, parts) => { tags.push([command, ...parts]); return Promise.resolve('rested'); } });
    await settled();
    eq(yes.dialog.choices.rows.map((r) => r.cells[0]), ['Yes', 'No']);
    ctx.dialog_key('Enter');
    await yes.done;
    eq(tags, [['inn', '30', 'bye']]);
    assert.ok(allText(yes.dialog.text).includes('You wake refreshed.'));
    assert.ok(!allText(yes.dialog.text).includes('Farewell'));

    const no = conversation(ctx, text, { on_tag: () => { throw new Error('No must not rest'); } });
    await settled();
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter');
    await no.done;
    assert.ok(allText(no.dialog.text).includes('Farewell.'));
});

test('vend and hawk run a shop after their text; when it closes, the talk goes to the cancel label', async () => {
    const { ctx } = withLayout();
    const tags = [];
    const text = '`label,start``switch,Buy,buy,Sell,sell,Leave,bye`What can I do for you?\n`jump,bye`'
        + '`label,buy``vend,1,,2,15,start`Take a look.\n'
        + '`label,sell``hawk,75,start`What have you got?\n'
        + '`label,bye`Come back soon!';
    const shop = conversation(ctx, text, {
        on_tag: (command, parts) => { tags.push([command, ...parts]); return Promise.resolve(null); },
    });
    await settled();
    ctx.dialog_key('Enter'); // Buy
    await settled();
    eq(tags, [['vend', '1', '', '2', '15', 'start']]);
    assert.ok(allText(shop.dialog.text).includes('Take a look.'), 'its text first');
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // back at the switch: Sell
    await settled();
    eq(tags[1], ['hawk', '75', 'start']);
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('ArrowDown');
    ctx.dialog_key('Enter'); // Leave
    await shop.done;
    assert.ok(allText(shop.dialog.text).includes('Come back soon!'));
});
