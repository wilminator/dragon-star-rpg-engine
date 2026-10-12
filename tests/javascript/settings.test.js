'use strict';

// The settings popup (settings.js), the fight-screen toggles it drives,
// and dialog focus (dialogs.js): WCAG 2.4.3 and 1.4.2.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');
const { fakeDocument, findAll, button, settled } = require('./helpers/fake_dom.js');

function context(extra = {}) {
    const document = fakeDocument();
    const ctx = loadBrowserScripts(['object_api.js', 'dialogs.js', 'settings.js'], { document, ...extra });
    return { ctx, document };
}

const inputs = (el, type) => findAll(el, (e) => e.tagName === 'INPUT' && e.type === type);

// --- settings ---

test('with no saved settings, everything is on and at full volume', () => {
    const { ctx } = context();
    assert.equal(ctx.settings_volume('music'), 1);
    assert.equal(ctx.settings_volume('sound'), 1);
    assert.equal(ctx.settings_hidden('hide_gore'), false);
});

test('volumes reach the audio as 0 to 1, and a mute makes them 0', () => {
    const set = {};
    const { ctx } = context({
        player_settings: { music_volume: 40, sound_volume: 70, sound_muted: true },
        set_music_volume: (v) => { set.music = v; }, set_sound_volume: (v) => { set.sound = v; },
        music_handle: { volume: (v) => { set.playing = v; } },
    });
    ctx.settings_apply();
    assert.deepEqual(set, { music: 0.4, sound: 0, playing: 0.4 });
});

test('each change in the popup takes effect at once and is saved', async () => {
    const calls = [];
    const { ctx, document } = context({
        call: (method, changes) => { calls.push([method, changes]); return Promise.resolve([{ ...ctx_settings(), ...changes }]); },
    });
    function ctx_settings() { return ctx.settings_all(); }
    const dialog = ctx.open_settings({ parent: document.body, call: (...a) => calls.push(a) && Promise.resolve([{ hide_gore: true }]) });
    const boxes = inputs(dialog.element, 'checkbox');
    assert.equal(boxes.length, 6, 'music mute, sound mute, the three fight-screen toggles and the combat log');
    const gore = dialog.controls.hide_gore;
    gore.checked = true;
    gore.onchange();
    await settled();
    assert.equal(ctx.settings_hidden('hide_gore'), true);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0])), ['save_settings', { hide_gore: true }]);

    const slider = dialog.controls.music_volume;
    slider.value = '25';
    slider.oninput();
    assert.equal(ctx.settings_get('music_volume'), 25, 'heard while it moves');
    assert.equal(calls.length, 1, 'but not saved until it is let go');
    slider.onchange();
    assert.deepEqual(JSON.parse(JSON.stringify(calls[1])), ['save_settings', { music_volume: 25 }]);
});

test('a failed save says so, and the setting stays in effect', async () => {
    const { ctx, document } = context();
    const dialog = ctx.open_settings({ parent: document.body, call: () => Promise.reject(new Error('offline')) });
    dialog.controls.sound_muted.checked = true;
    await dialog.controls.sound_muted.onchange();
    await settled();
    assert.equal(ctx.settings_get('sound_muted'), true);
    assert.match(dialog.status.textContent, /Couldn't save/);
});

test('the popup is a labelled modal dialog, and Close or Escape shuts it', () => {
    const { ctx, document } = context();
    const dialog = ctx.open_settings({ parent: document.body });
    assert.equal(dialog.element.getAttribute('role'), 'dialog');
    assert.equal(dialog.element.getAttribute('aria-label'), 'Settings');
    assert.equal(dialog.element.getAttribute('aria-modal'), 'true');
    assert.ok(dialog.element.contains(document.activeElement), 'focus is inside it');
    assert.equal(ctx.dialog_key('Escape'), true);
    assert.equal(ctx.dialog_top(), null);
    const again = ctx.open_settings({ parent: document.body });
    button(again.element, 'Close').click();
    assert.equal(ctx.dialog_top(), null);
});

test('round time is one of three choices, saved like the rest', async () => {
    const calls = [];
    const { ctx, document } = context({ player_settings: { time_multiplier: 1.5 } });
    const dialog = ctx.open_settings({ parent: document.body, call: (...a) => { calls.push(a); return Promise.resolve([null]); } });
    const radios = findAll(dialog.element, (e) => e.tagName === 'INPUT' && e.type === 'radio');
    assert.deepEqual(radios.map((r) => r.value), ['1', '1.5', '2']);
    assert.deepEqual(radios.map((r) => r.checked), [false, true, false], 'shows the saved choice');
    assert.ok(radios.every((r) => r.name === radios[0].name), 'one group: arrow keys move between them');
    radios[2].checked = true;
    radios[2].onchange();
    await settled();
    assert.equal(ctx.settings_get('time_multiplier'), 2);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0])), ['save_settings', { time_multiplier: 2 }]);
});

// --- dialog focus ---

test('a dialog with a list puts focus on the list, which names the highlighted row', () => {
    const { ctx, document } = context();
    const frame = ctx.dialog_frame({ parent: document.body });
    const view = new ctx.SelectionListDomView(frame.body, [{}], 4);
    const list = view.bind(new ctx.SelectionList({ page_size: 4 }));
    list.set_rows([{ cells: ['Potion'] }, { cells: ['Ether'], selectable: false }]);
    frame.show();
    const dialog = { element: frame.element };
    frame.element.setAttribute('role', 'dialog');
    frame.element.setAttribute('aria-label', 'Whose items?');
    ctx.dialog_open(dialog);
    const listbox = findAll(frame.element, (e) => e.getAttribute('role') === 'listbox')[0];
    assert.equal(document.activeElement, listbox, 'not a button: Enter must choose the row');
    assert.equal(listbox.getAttribute('aria-label'), 'Whose items?', 'named for its dialog');
    list.highlight(0);
    const row = view.rows[0].element;
    assert.equal(row.getAttribute('role'), 'option');
    assert.equal(row.getAttribute('aria-selected'), 'true');
    assert.equal(listbox.getAttribute('aria-activedescendant'), row.id);
    assert.equal(view.rows[1].element.getAttribute('aria-disabled'), 'true');
    assert.equal(view.rows[0].pic.getAttribute('alt'), '', 'the picture is named by the row');
});

test('Tab goes round the controls of the dialog in front only', () => {
    const { ctx, document } = context();
    const behind = document.createElement('button');
    document.body.appendChild(behind);
    const frame = ctx.dialog_frame({ parent: document.body });
    const a = document.createElement('button'), b = document.createElement('button');
    const hidden = document.createElement('button');
    hidden.style.display = 'none';
    frame.body.appendChild(a); frame.body.appendChild(hidden); frame.body.appendChild(b);
    frame.show();
    ctx.dialog_open({ element: frame.element });
    assert.equal(document.activeElement, frame.element, 'no list: the dialog itself');
    ctx.dialog_tab(false);
    assert.equal(document.activeElement, a);
    ctx.dialog_tab(false);
    assert.equal(document.activeElement, b, 'the hidden one is skipped');
    ctx.dialog_tab(false);
    assert.equal(document.activeElement, a, 'round again, never to what is behind');
    ctx.dialog_tab(true);
    assert.equal(document.activeElement, b, 'Shift+Tab goes back');
});

// --- the fight-screen toggles ---

test('hidden HP numbers and blood are not drawn', () => {
    const drawn = [];
    const ctx = loadBrowserScripts(['settings.js', 'combat.js'], {
        document: fakeDocument(),
        player_settings: { hide_hp_popups: true, hide_gore: true }, player_party: 0,
        get_hero: () => ({ html: { pic: {} } }),
        make_damage_fly: () => drawn.push('number'), make_blood_fly: () => drawn.push('blood'),
        object_get_x: () => 0, object_get_y: () => 0, get_picture_x: () => 0, get_picture_y: () => 0,
    });
    ctx.number_bounce(0, 0, 0, 12, 'white');
    ctx.blood_splat(0, 0, 0, 12, 1);
    assert.deepEqual(drawn, []);
    ctx.player_settings.hide_hp_popups = false;
    ctx.player_settings.hide_gore = false;
    ctx.number_bounce(0, 0, 0, 12, 'white');
    ctx.blood_splat(0, 0, 0, 12, 1);
    assert.deepEqual(drawn, ['number', 'blood']);
});

test('a hidden attack or spell animation still does what it carries, at once', () => {
    const processed = [], started = [];
    const ctx = loadBrowserScripts(['settings.js', 'animations/animation.js'], {
        player_settings: { hide_actions: true },
        animations: {
            fireball: Object.assign(function () { this.start = () => { started.push('fireball'); return 0; }; }, { prototype: { prep() {} } }),
            base_animation: Object.assign(function () { this.start = () => { started.push('base'); return 0; }; }, { prototype: { prep() {} } }),
        },
        default_group_impact_processor: (p, g, c, range, events) => processed.push(events.length),
    });
    const target = { party: 1, group: 0, character: 2 };
    assert.equal(ctx.invoke_animation('fireball', target, target, 0, [['Damage', [1, 0, 2, 9, false]]], {}), 0);
    assert.deepEqual(processed, [1], 'the damage still lands');
    ctx.invoke_animation('base_animation', target, target, 0, [], {});
    assert.deepEqual(started, ['base'], "a fighter's own pose still plays");
    ctx.player_settings.hide_actions = false;
    ctx.invoke_animation('fireball', target, target, 0, [], {});
    assert.deepEqual(started, ['base', 'fireball']);
});
