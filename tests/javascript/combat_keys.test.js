'use strict';

// The fight screen from the keyboard (combat_keys.js): choosing heroes,
// the action menu, backing out.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');
const { fakeDocument } = require('./helpers/fake_dom.js');

// A party of two groups: [alive?, charid] per hero; teams maps team to charids.
function context({ groups = [[[1, 11], [1, 12], [0, 13]], [[1, 21]]], teams = { 5: [11, 12, 13, 21] }, teamid = 5, hidden = [] } = {}) {
    const document = fakeDocument();
    for (let i = 0; i < 8; i++) {
        const icon = document.createElement('img');
        icon.id = 'action' + i;
        if (hidden.includes(i)) icon.style.visibility = 'hidden';
        document.body.appendChild(icon);
    }
    const log = [];
    const party = { teams, groups: groups.map((g) => g.map(([alive, charid]) => ({ charid, current: { HP: alive ? 10 : 0 } }))) };
    const ctx = loadBrowserScripts(['object_api.js', 'dialogs.js', 'menu_control.js', 'combat_keys.js'], {
        document, player_party: 0, teamid, teamids: [teamid],
        get_party: () => party,
        get_party_length: () => party.groups.length,
        get_group_length: (p, g) => party.groups[g].length,
        get_hero: (p, g, c) => party.groups[g][c],
        highlight_action_menu_icon: (icon) => log.push(['on', icon.id]),
        unhighlight_action_menu_icon: (icon) => log.push(['off', icon.id]),
        action_menu_selection: (choice) => log.push(['choose', choice]),
    });
    ctx.target_cancel = () => log.push(['cancel target']); // menu_control.js has its own
    // select_hero as the fight has it: a new hero is chosen; choosing the
    // chosen one again opens (or, from the menu, closes) the action menu.
    ctx.curr_hero = null;
    ctx.select_hero = (p, g, c) => {
        if (ctx.curr_hero && ctx.curr_hero[0] === g && ctx.curr_hero[1] === c) {
            ctx.menu_state = ctx.menu_state === ctx.MENU_STATE_FIGHT_ACTION ? ctx.MENU_STATE_FIGHT_PLAYER : ctx.MENU_STATE_FIGHT_ACTION;
        } else if (ctx.menu_state === ctx.MENU_STATE_FIGHT_PLAYER) {
            ctx.curr_hero = [g, c];
        }
        log.push(['select', g, c]);
    };
    ctx.menu_state = ctx.MENU_STATE_FIGHT_PLAYER;
    return { ctx, log };
}

const key = (ctx, k) => ctx.combat_keys.handle_key(k);
const hero = (ctx) => JSON.parse(JSON.stringify(ctx.curr_hero));

test('only the player\'s own living heroes can be chosen, in screen order', () => {
    const { ctx } = context({ teams: { 5: [11, 13, 21], 6: [12] } });
    assert.deepEqual(JSON.parse(JSON.stringify(ctx.combat_keys_heroes())), [[0, 0], [1, 0]], '12 is an ally\'s, 13 has fallen');
    const spectator = context({ teamid: null }).ctx;
    assert.deepEqual(JSON.parse(JSON.stringify(spectator.combat_keys_heroes())), []);
    const monsters = context({ teams: {}, teamid: -1 }).ctx;
    assert.equal(monsters.combat_keys_heroes().length, 3, 'a monster player commands every living monster');
});

test('left and right step through them, up and down jump between groups, both wrapping', () => {
    const { ctx } = context();
    key(ctx, 'ArrowRight');
    assert.deepEqual(hero(ctx), [0, 0]);
    key(ctx, 'ArrowRight');
    assert.deepEqual(hero(ctx), [0, 1]);
    key(ctx, 'ArrowRight');
    assert.deepEqual(hero(ctx), [1, 0], 'past the fallen one');
    key(ctx, 'ArrowRight');
    assert.deepEqual(hero(ctx), [0, 0], 'round again');
    key(ctx, 'ArrowLeft');
    assert.deepEqual(hero(ctx), [1, 0]);
    key(ctx, 'ArrowDown');
    assert.deepEqual(hero(ctx), [0, 0], 'the first in the next group, round');
    key(ctx, 'ArrowUp');
    assert.deepEqual(hero(ctx), [1, 0]);
});

test('with one hero who can act, moving stays put instead of opening their menu', () => {
    const { ctx } = context({ groups: [[[1, 11]]], teams: { 5: [11] } });
    key(ctx, 'ArrowRight');
    key(ctx, 'ArrowRight');
    key(ctx, 'ArrowLeft');
    assert.equal(ctx.menu_state, ctx.MENU_STATE_FIGHT_PLAYER);
});

test('Enter opens the action menu on the first icon showing; the arrows skip hidden ones', () => {
    const { ctx, log } = context({ hidden: [1, 4] });
    assert.equal(key(ctx, 'Enter'), true, 'no hero chosen yet: the first one');
    assert.equal(ctx.menu_state, ctx.MENU_STATE_FIGHT_ACTION);
    assert.equal(ctx.combat_keys.action_highlight, 0);
    key(ctx, 'ArrowRight');
    assert.equal(ctx.combat_keys.action_highlight, 2, 'past the hidden right-hand icon');
    key(ctx, 'ArrowLeft');
    key(ctx, 'ArrowLeft');
    assert.equal(ctx.combat_keys.action_highlight, 3, 'round the row');
    key(ctx, 'ArrowDown');
    assert.equal(ctx.combat_keys.action_highlight, 7);
    key(ctx, 'ArrowUp');
    assert.equal(ctx.combat_keys.action_highlight, 3);
    key(ctx, 'Enter');
    assert.deepEqual(JSON.parse(JSON.stringify(log.filter((e) => e[0] === 'choose'))), [['choose', 3]]);
});

test('Escape backs out of the action menu, and cancels choosing a target', () => {
    const { ctx, log } = context();
    key(ctx, 'Enter');
    key(ctx, 'Escape');
    assert.equal(ctx.menu_state, ctx.MENU_STATE_FIGHT_PLAYER);
    assert.equal(ctx.combat_keys.action_highlight, null);
    ctx.menu_state = ctx.MENU_STATE_FIGHT_TARGET;
    assert.equal(key(ctx, 'Escape'), true);
    assert.deepEqual(JSON.parse(JSON.stringify(log.at(-1))), ['cancel target']);
});

test('nothing happens while a round plays, and a focused button keeps its Enter', () => {
    const { ctx } = context();
    ctx.menu_state = ctx.MENU_STATE_FIGHT_WAIT;
    assert.equal(key(ctx, 'ArrowRight'), false);
    ctx.menu_state = ctx.MENU_STATE_FIGHT_PLAYER;
    const button = ctx.document.createElement('button');
    button.focus();
    assert.equal(key(ctx, 'Enter'), false);
    assert.equal(key(ctx, 'ArrowRight'), true, 'the arrows still move');
});

// --- choosing a target (part 2) ---

// Party 0 (the player's): one group of two. Party 1: two groups; 1:0:1 has fallen.
function targeting(target_dead = 0) {
    const document = fakeDocument();
    const log = [];
    const fighter = (name, hp) => ({ name, current: { HP: hp }, base: { HP: 20 }, charid: name.length });
    const parties = [
        { teams: { 5: [5, 3] }, groups: [[fighter('Jordie', 12), fighter('Mike', 20)]] },
        { teams: [], groups: [[fighter('Woodman-A', 20), fighter('Woodman-B', 0), fighter('Woodman-C', 7)], [fighter('Hippo-A', 30)]] },
    ];
    const ctx = loadBrowserScripts(['object_api.js', 'dialogs.js', 'menu_control.js', 'combat_keys.js'], {
        document, player_party: 0, teamid: 5, teamids: [5], target_dead,
        fight: { parties },
        get_party: (p) => parties[p],
        get_party_length: (p) => parties[p].groups.length,
        get_group_length: (p, g) => parties[p].groups[g].length,
        get_hero: (p, g, c) => parties[p].groups[g][c],
        highlight_hero_range: (p, g, c) => log.push(['on', p, g, c]),
        unhighlight_hero_range: (p, g, c) => log.push(['off', p, g, c]),
        hero_slide_center: (p, g) => log.push(['slide', p, g]),
        select_hero: (p, g, c) => log.push(['select', p, g, c]),
        commit_commands: () => log.push(['commit']),
    });
    ctx.target_cancel = () => log.push(['cancel target']);
    ctx.menu_state = ctx.MENU_STATE_FIGHT_TARGET;
    const cursor = () => JSON.parse(JSON.stringify(ctx.combat_keys.target));
    const said = () => document.getElementById('combatannounce').textContent;
    return { ctx, log, cursor, said };
}

test('the first key puts the cursor on the first enemy who can be hit, and says who', () => {
    const { ctx, cursor, said, log } = targeting();
    key(ctx, 'ArrowRight');
    assert.deepEqual(cursor(), [1, 0, 0]);
    assert.equal(said(), 'Target: Woodman-A, 20 of 20 HP');
    assert.deepEqual(JSON.parse(JSON.stringify(log.slice(0, 2))), [['slide', 1, 0], ['on', 1, 0, 0]], 'the group in view, the range lit');
});

test('left and right skip the fallen; up and down go through the enemy groups, then your own', () => {
    const { ctx, cursor } = targeting();
    key(ctx, 'ArrowRight');
    key(ctx, 'ArrowRight');
    assert.deepEqual(cursor(), [1, 0, 2], 'past Woodman-B, who has fallen');
    key(ctx, 'ArrowRight');
    assert.deepEqual(cursor(), [1, 0, 0], 'round the group');
    key(ctx, 'ArrowDown');
    assert.deepEqual(cursor(), [1, 1, 0]);
    key(ctx, 'ArrowDown');
    assert.deepEqual(cursor(), [0, 0, 0], 'then the player\'s own group');
    key(ctx, 'ArrowUp');
    assert.deepEqual(cursor(), [1, 1, 0]);
});

test('an action that reaches the fallen can target them', () => {
    const { ctx, cursor } = targeting(1);
    key(ctx, 'ArrowRight');
    key(ctx, 'ArrowRight');
    assert.deepEqual(cursor(), [1, 0, 1]);
});

test('Enter sets the target as a click would; Escape cancels', () => {
    const { ctx, log, said } = targeting();
    key(ctx, 'ArrowRight');
    key(ctx, 'ArrowDown');
    key(ctx, 'Enter');
    assert.deepEqual(JSON.parse(JSON.stringify(log.at(-1))), ['select', 1, 1, 0]);
    assert.equal(said(), 'Target set: Hippo-A, 30 of 20 HP');
    assert.equal(ctx.combat_keys.target, null);
    key(ctx, 'ArrowRight');
    key(ctx, 'Escape');
    assert.deepEqual(JSON.parse(JSON.stringify(log.at(-1))), ['cancel target']);
    assert.equal(said(), 'Cancelled.');
});

test('C commits while choosing heroes, and says so', () => {
    const { ctx, log, said } = targeting();
    ctx.menu_state = ctx.MENU_STATE_FIGHT_PLAYER;
    assert.equal(key(ctx, 'c'), true);
    assert.deepEqual(JSON.parse(JSON.stringify(log.at(-1))), ['commit']);
    assert.equal(said(), 'Commands sent. Waiting for the round.');
    ctx.menu_state = ctx.MENU_STATE_FIGHT_ACTION;
    const before = log.length;
    key(ctx, 'c');
    assert.equal(log.filter((e) => e[0] === 'commit').length, 1, 'not from inside the action menu');
    assert.ok(log.length >= before);
});
