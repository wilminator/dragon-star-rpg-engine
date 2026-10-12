'use strict';

// Playing with a controller (gamepad.js): buttons and the stick become the
// keys the rest of the game already understands.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');
const { fakeDocument } = require('./helpers/fake_dom.js');

function pad() {
    return { connected: true, axes: [0, 0], buttons: Array.from({ length: 17 }, () => ({ pressed: false, value: 0 })) };
}

function context(extra = {}) {
    const document = fakeDocument();
    const controller = pad();
    const keys = [];
    const ctx = loadBrowserScripts(['dialogs.js', 'controls.js', 'gamepad.js'], {
        document, navigator: { getGamepads: () => [controller] }, ...extra,
    });
    ctx.dialog_key = (k) => { keys.push(k); return true; };
    return { ctx, controller, keys, document };
}

const tap = (ctx, controller, button, at) => {
    controller.buttons[button].pressed = true;
    ctx.gamepad_poll(at);
    controller.buttons[button].pressed = false;
    ctx.gamepad_poll(at + 16);
};

test('the D-pad, A and B are the arrow keys, Enter and Escape', () => {
    const { ctx, controller, keys } = context();
    tap(ctx, controller, 12, 0);
    tap(ctx, controller, 15, 100);
    tap(ctx, controller, 0, 200);
    tap(ctx, controller, 1, 300);
    assert.deepEqual(keys, ['ArrowUp', 'ArrowRight', 'Enter', 'Escape']);
});

test('a button held down presses once; a held direction repeats after a moment', () => {
    const { ctx, controller, keys } = context();
    controller.buttons[0].pressed = true;
    for (let t = 0; t <= 1000; t += 16) ctx.gamepad_poll(t);
    assert.deepEqual(keys, ['Enter'], 'A held for a second: one press');
    keys.length = 0;
    controller.buttons[0].pressed = false;
    ctx.gamepad_poll(1016);
    controller.axes[0] = 0.9; // the stick, pushed right
    for (let t = 2000; t <= 2800; t += 16) ctx.gamepad_poll(t);
    assert.equal(keys[0], 'ArrowRight');
    assert.ok(keys.length >= 3 && keys.length <= 6, `repeats after 400 ms, every 130 ms: ${keys.length}`);
    assert.ok(keys.every((k) => k === 'ArrowRight'));
});

test('a light touch on the stick does nothing', () => {
    const { ctx, controller, keys } = context();
    controller.axes[1] = 0.3;
    ctx.gamepad_poll(0);
    assert.deepEqual(keys, []);
});

test('A presses a focused button or ticks a focused checkbox', () => {
    const { ctx, controller, keys, document } = context();
    const button = document.createElement('button');
    let clicked = 0;
    button.onclick = () => clicked++;
    document.body.appendChild(button);
    button.focus();
    tap(ctx, controller, 0, 0);
    assert.equal(clicked, 1);
    assert.deepEqual(keys, [], 'not sent on as Enter as well');
});

test('left and right turn a focused slider down and up', () => {
    const { ctx, controller, document } = context();
    const slider = document.createElement('input');
    slider.type = 'range';
    Object.assign(slider, { min: '0', max: '100', step: '5', value: '100' });
    const changes = [];
    slider.onchange = () => changes.push(slider.value);
    document.body.appendChild(slider);
    slider.focus();
    tap(ctx, controller, 14, 0);
    tap(ctx, controller, 14, 100);
    tap(ctx, controller, 15, 200);
    tap(ctx, controller, 15, 300);
    tap(ctx, controller, 15, 400);
    assert.deepEqual(changes, ['95', '90', '95', '100', '100'], 'never past the ends');
});

test('Y commits in a fight, and only there; Start does nothing', () => {
    const { ctx, controller, keys } = context();
    tap(ctx, controller, 3, 0);
    assert.deepEqual(keys, [], 'not a fight');
    ctx.combat_keys = {};
    ctx.dialog_set_base(ctx.combat_keys);
    tap(ctx, controller, 9, 100);
    assert.deepEqual(keys, [], 'Start');
    tap(ctx, controller, 3, 200);
    assert.deepEqual(keys, ['c']);
});

test('buttons follow the player\'s mapping', () => {
    const { ctx, controller, keys } = context({ player_settings: { pad_map: { confirm: 2, back: 0 } }, settings_get: (k) => ({ confirm: 2, back: 0 }) });
    ctx.settings_get = (k) => (k === 'pad_map' ? { confirm: 2, back: 0 } : null);
    tap(ctx, controller, 2, 0);  // X: now confirm
    tap(ctx, controller, 0, 100); // A: now back
    tap(ctx, controller, 1, 200); // B: nothing now
    assert.deepEqual(keys, ['Enter', 'Escape']);
});

test('while Settings waits for a new button, the next one pressed goes to it', () => {
    const { ctx, controller, keys } = context();
    const got = [];
    ctx.gamepad_capture((index) => got.push(index));
    tap(ctx, controller, 2, 0);
    tap(ctx, controller, 0, 100);
    assert.deepEqual(got, [2], 'just the one');
    assert.deepEqual(keys, ['Enter'], 'X was taken; A works as usual after');
});

test("the shoulder buttons move between a dialog's controls", () => {
    const { ctx, controller } = context();
    const moves = [];
    ctx.dialog_tab = (back) => moves.push(back ? 'back' : 'on');
    tap(ctx, controller, 5, 0);
    assert.deepEqual(moves, [], 'no dialog open: nothing');
    ctx.dialog_open({ element: null });
    tap(ctx, controller, 5, 100);
    tap(ctx, controller, 4, 200);
    assert.deepEqual(moves, ['on', 'back']);
});

test('Back opens Settings, and closes it when it is open', () => {
    const opened = [];
    const { ctx, controller } = context({ call_server: () => Promise.resolve([null]) });
    ctx.open_settings = (options) => opened.push(options);
    tap(ctx, controller, 8, 0);
    assert.equal(opened.length, 1);
    const shown = new (class SettingsDialog { close() { opened.push('closed'); } })();
    ctx.SettingsDialog = shown.constructor;
    ctx.dialog_top = () => shown;
    tap(ctx, controller, 8, 100);
    assert.deepEqual(opened.slice(1), ['closed']);
});
