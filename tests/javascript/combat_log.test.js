'use strict';

// The combat log panel (combat_log.js): rounds added in words, shown
// beside the fight or kept for screen readers only, and the reading
// pause after playback.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');
const { fakeDocument, findAll } = require('./helpers/fake_dom.js');

function context(settings = {}, extra = {}) {
    const document = fakeDocument();
    const ctx = loadBrowserScripts(['settings.js', 'combat_log.js'], { document, player_settings: settings, ...extra });
    return { ctx, document };
}

test('a round goes into the log under its number, as a log screen readers follow', () => {
    const { ctx, document } = context({ text_log: true });
    ctx.combat_log_add(3, ['Jordie attacks Woodman.', '6 enemies are damaged for 45 HP on average.']);
    const panel = document.getElementById('combatlog');
    assert.equal(panel.getAttribute('role'), 'log');
    assert.equal(panel.getAttribute('aria-live'), 'polite');
    assert.equal(panel.className, 'combatlog');
    const texts = findAll(panel, (e) => e.tagName === 'H3' || e.tagName === 'P').map((e) => e.textContent);
    assert.deepEqual(texts, ['Round 3', 'Jordie attacks Woodman.', '6 enemies are damaged for 45 HP on average.']);
});

test('with the log off, it is out of sight but still there', () => {
    const { ctx, document } = context({ text_log: false });
    ctx.combat_log_add(1, ['Mike attacks.']);
    assert.equal(document.getElementById('combatlog').className, 'combatlog combatlog-hidden');
    assert.equal(ctx.combat_log_pause, undefined, 'no reading pause after the round any more');
});

test('a round with nothing to say adds nothing, and only the latest 50 rounds are kept', () => {
    const { ctx, document } = context({ text_log: true });
    ctx.combat_log_round(1, [{ act: [], results: [] }, []]);
    assert.equal(document.getElementById('combatlog'), null);
    for (let round = 1; round <= 55; round++) ctx.combat_log_add(round, ['x']);
    const panel = document.getElementById('combatlog');
    assert.equal(panel.children.length, 50);
    assert.equal(panel.children[0].children[0].textContent, 'Round 6');
});

test('the heading goes in as the round starts, what a fighter does as their turn plays, and what came of it when the hit lands', () => {
    const { ctx, document } = context({ text_log: true });
    ctx.combat_log_round(2, [
        { act: ['Jordie attacks Woodman.'], results: ['Woodman takes 12 damage.'] },
        { act: [], results: [] },
        { act: ['Mike casts Fire.'], results: ['6 enemies are damaged for 45 HP on average.'] },
    ]);
    const text = () => findAll(document.getElementById('combatlog'), (e) => e.tagName === 'H3' || e.tagName === 'P').map((e) => e.textContent);
    assert.deepEqual(text(), ['Round 2'], 'only the heading before anyone acts');
    ctx.combat_log_turn(0);
    assert.deepEqual(text(), ['Round 2', 'Jordie attacks Woodman.'], 'the outcome waits');
    ctx.combat_log_landed();
    assert.deepEqual(text(), ['Round 2', 'Jordie attacks Woodman.', 'Woodman takes 12 damage.']);
    ctx.combat_log_landed();
    assert.equal(text().length, 3, 'once only');
    ctx.combat_log_turn(1);
    ctx.combat_log_turn(2);
    assert.equal(text().length, 4);
    ctx.combat_log_flush();
    assert.equal(text()[4], '6 enemies are damaged for 45 HP on average.', 'a hit that never showed: in by the round\'s end');
});

test('a result still waiting goes in before the next fighter\'s turn', () => {
    const { ctx, document } = context({ text_log: true });
    ctx.combat_log_round(3, [{ act: ['A defends.'], results: ['Late.'] }, { act: ['B attacks.'], results: [] }]);
    ctx.combat_log_turn(0);
    ctx.combat_log_turn(1);
    const text = findAll(document.getElementById('combatlog'), (e) => e.tagName === 'P').map((e) => e.textContent);
    assert.deepEqual(text, ['A defends.', 'Late.', 'B attacks.']);
});

test('the playback puts each fighter\'s log turn first in their part of the round', () => {
    const ctx = loadBrowserScripts(['process.js'], { document: fakeDocument() });
    const groups = ctx.preprocess_action_list([
        ['Turn', [0, 0, 0]], ['Attack', [0, 'left', 0]], ['Target', [1, 0, 0, 0]], ['Damage', [1, 0, 0, 5, false]],
        ['Turn', [0, 0, 1]],
        ['Turn', [1, 0, 0]], ['Defend', [1, 0, 0]],
    ]);
    assert.deepEqual(JSON.parse(JSON.stringify(groups.map((g) => g.turn))), [0, 2], 'numbered as the server numbers them, the empty turn skipped');
});

test('turning the log on or off from the settings shows it and resizes the hub frame', () => {
    const sizes = [];
    const { ctx, document } = context({ text_log: false }, {});
    ctx.window.parent = { hub_fight_resize: (on) => sizes.push(on) };
    ctx.combat_log_add(1, ['x']);
    ctx.player_settings.text_log = true;
    ctx.settings_apply();
    assert.equal(document.getElementById('combatlog').className, 'combatlog');
    assert.deepEqual(sizes, [false, true]);
});
