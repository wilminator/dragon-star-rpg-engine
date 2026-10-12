'use strict';

// Remappable controls (controls.js) and the Controls tab of Settings.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');
const { fakeDocument, findAll, button } = require('./helpers/fake_dom.js');

function context(settings = {}) {
    const document = fakeDocument();
    const calls = [];
    const ctx = loadBrowserScripts(['object_api.js', 'dialogs.js', 'controls.js', 'settings.js'], { document, player_settings: settings });
    return { ctx, document, calls };
}
const plain = (v) => JSON.parse(JSON.stringify(v));

test('keys pass through unchanged until the player remaps them', () => {
    const { ctx } = context();
    for (const k of ['ArrowUp', 'Enter', 'Escape', 'c', 'x', ' ', 'Tab']) assert.equal(ctx.controls_key(k), k);
    assert.equal(ctx.controls_key('C'), 'c', 'the commit key, either case');
});

test('a remapped key does the action, and the key it replaced does nothing', () => {
    const { ctx } = context({ key_map: { up: 'w', commit: 'x' } });
    assert.equal(ctx.controls_key('w'), 'ArrowUp');
    assert.equal(ctx.controls_key('W'), 'ArrowUp', 'either case');
    assert.equal(ctx.controls_key('x'), 'c');
    assert.equal(ctx.controls_key('ArrowUp'), null, 'freed');
    assert.equal(ctx.controls_key('c'), null, 'freed');
    assert.equal(ctx.controls_key('ArrowDown'), 'ArrowDown', 'untouched ones stay');
});

test('giving an action a key another action has swaps them, and only changes are kept', () => {
    const { ctx } = context();
    assert.deepEqual(plain(ctx.controls_assign('key_map', 'up', 'ArrowDown')), { up: 'ArrowDown', down: 'ArrowUp' });
    ctx.player_settings.key_map = { up: 'ArrowDown', down: 'ArrowUp' };
    assert.deepEqual(plain(ctx.controls_assign('key_map', 'up', 'ArrowUp')), {}, 'back as it was: nothing to keep');
    assert.deepEqual(plain(ctx.controls_assign('pad_map', 'commit', 9)), { commit: 9 });
    assert.deepEqual(plain(ctx.controls_assign('pad_map', 'confirm', 1)), { confirm: 1, back: 0 });
});

test('Settings has tabs, and Left and Right move between them', () => {
    const { ctx, document } = context();
    const dialog = ctx.open_settings({ parent: document.body });
    const tabs = findAll(dialog.element, (e) => e.getAttribute('role') === 'tab');
    assert.deepEqual(tabs.map((t) => t.textContent), ['Sound', 'Fight screen', 'Round time', 'Controls']);
    const panels = findAll(dialog.element, (e) => e.getAttribute('role') === 'tabpanel');
    assert.equal(panels.filter((p) => p.style.display !== 'none').length, 1, 'one showing');
    tabs[0].focus();
    assert.equal(dialog.handle_key('ArrowRight'), true);
    assert.equal(tabs[1].getAttribute('aria-selected'), 'true');
    assert.equal(document.activeElement, tabs[1]);
    dialog.handle_key('ArrowLeft');
    dialog.handle_key('ArrowLeft');
    assert.equal(tabs[3].getAttribute('aria-selected'), 'true', 'round to the last');
    dialog.close();
    const again = ctx.open_settings({ parent: document.body });
    assert.equal(findAll(again.element, (e) => e.getAttribute('role') === 'tab')[3].getAttribute('aria-selected'), 'true',
        'opens where it was left');
});

test('in the Controls tab, choose an action, press the new key, and it is saved', async () => {
    const calls = [];
    const { ctx, document } = context();
    const dialog = ctx.open_settings({ parent: document.body, call: (...a) => { calls.push(a); return Promise.resolve([null]); } });
    const panel = dialog.controls_panel;
    const upKey = panel.cells['key_map:up'];
    assert.equal(upKey.textContent, '↑');
    upKey.click();
    assert.match(upKey.textContent, /Press a key/);
    assert.equal(dialog.capture_key('w'), true);
    assert.equal(upKey.textContent, 'W');
    assert.equal(ctx.controls_key('w'), 'ArrowUp');
    assert.deepEqual(plain(calls.at(-1)), ['save_settings', { key_map: { up: 'w' } }]);
    assert.equal(dialog.capture_key('q'), false, 'not listening any more');
});

test('Esc while waiting cancels the change, not the dialog; Reset brings the defaults back', () => {
    const { ctx, document } = context({ key_map: { commit: 'x' }, pad_map: { commit: 9 } });
    const dialog = ctx.open_settings({ parent: document.body });
    const panel = dialog.controls_panel;
    panel.cells['key_map:back'].click();
    dialog.cancel();
    assert.equal(ctx.dialog_top(), dialog, 'still open');
    assert.equal(panel.cells['key_map:back'].textContent, 'Esc');
    button(dialog.element, 'Reset to defaults').click();
    assert.equal(ctx.controls_key('c'), 'c');
    assert.equal(panel.cells['pad_map:commit'].textContent, 'Y');
});
