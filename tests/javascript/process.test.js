'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function freshProcess(extraGlobals = {}) {
    const errors = [];
    const ctx = loadBrowserScripts(['process.js'], {
        get_hero: () => ({ personalityid: 1 }),
        personalities: { 1: { attack_close_animation: 'PUNCH_ANIM', attack_close_data: {} } },
        player_party: 0,
        items: { 0: { fight_effect_type: 'close', name: 'Fists', fight_impact_animation: 'IMPACT_ANIM', fight_impact_data: {} } },
        alert: () => {},
        data_request: (...args) => errors.push(args),
        ...extraGlobals,
    });
    ctx.__errors = errors;
    return ctx;
}

// A real, filter_for_client()'d action_data shape from an actual
// resolved fight this session (one attack, one kill, then the
// FightDialog conclusion message). A factory, not a shared constant:
// convert_action_list_to_playlist() -> preprocess_action_list()
// mutates its input via action_list.shift(), so a shared array would
// get emptied by the first test that uses it.
function realResolvingRound() {
    return [
        ['Turn', [0, 0, 0]],
        ['Attack', [0, 'left', 0]],
        ['Target', [1, 0, 0, 0]],
        ['Damage', [1, 0, 0, 82, false]],
        ['Died', [1, 0, 0, 82]],
        ['FightDialog', ['Alice has earned 30 XP.\nYour team received 0 gold.']],
    ];
}

test('convert_action_list_to_playlist() does not report FightDialog as an unknown action', () => {
    const ctx = freshProcess();
    ctx.convert_action_list_to_playlist(realResolvingRound());
    assert.deepEqual(ctx.__errors, [], 'no client_error should be reported for FightDialog');
});

test('convert_action_list_to_playlist() does not drop a FightDialog-only group (no accompanying damage/effect)', () => {
    const ctx = freshProcess();
    const playlist = ctx.convert_action_list_to_playlist([
        ['Turn', [0, 0, 0]],
        ['FightDialog', ['Nothing else happened this round.']],
    ]);
    const hasPromiseEvent = playlist.some((entry) => entry[1] === 'promise_event' && entry[2] === 'process_fight_dialog');
    assert.ok(hasPromiseEvent, 'the FightDialog entry must still reach the playlist even with empty event/effect lists');
});

test('convert_action_list_to_playlist() calls process_fight_dialog via promise_event with just the dialog text', () => {
    const ctx = freshProcess();
    const playlist = ctx.convert_action_list_to_playlist(realResolvingRound());
    const entry = playlist.find((e) => e[1] === 'promise_event');
    assert.equal(JSON.stringify(entry), JSON.stringify([0, 'promise_event', 'process_fight_dialog', 'Alice has earned 30 XP.\nYour team received 0 gold.']));
});

test('convert_action_list_to_playlist() inserts a resync [1] barrier immediately before the dialog starts', () => {
    // The dialog must not start until every still-in-flight animation
    // from this round (Damage/Died impacts, etc.) has actually
    // finished -- otherwise it races them, rather than waiting for
    // the fight's visible state to settle first.
    const ctx = freshProcess();
    const playlist = ctx.convert_action_list_to_playlist(realResolvingRound());
    const dialogIndex = playlist.findIndex((e) => e[1] === 'promise_event');
    assert.ok(dialogIndex > 0, 'the dialog entry must not be the very first thing in the playlist');
    assert.equal(JSON.stringify(playlist[dialogIndex - 1]), '[1]', 'a resync barrier must immediately precede the dialog entry');
});

test('convert_action_list_to_playlist() still waits on its own barrier after the dialog entry too', () => {
    const ctx = freshProcess();
    const playlist = ctx.convert_action_list_to_playlist(realResolvingRound());
    const dialogIndex = playlist.findIndex((e) => e[1] === 'promise_event');
    assert.equal(JSON.stringify(playlist[dialogIndex + 1]), '[1]', 'the dialog\'s own completion barrier must still follow it');
});

test('convert_action_list_to_playlist() plays the dialog after this round\'s own attack and impact animations, not before them', () => {
    // Regression test: FightDialog is classified into message_list,
    // which is fully processed (the whole loop) before effect_list --
    // the source of Damage/Died impact animations -- even starts. A
    // barrier placed within the message_list switch can't wait for an
    // animation effect_list hasn't queued yet, so the dialog was
    // starting before the round's own combat animation had even been
    // added to the playlist, let alone played.
    const ctx = freshProcess();
    const playlist = ctx.convert_action_list_to_playlist(realResolvingRound());
    const attackIndex = playlist.findIndex((e) => e[2] === 'PUNCH_ANIM');
    const impactIndex = playlist.findIndex((e) => e[2] === 'IMPACT_ANIM');
    const dialogIndex = playlist.findIndex((e) => e[1] === 'promise_event');
    assert.ok(attackIndex > -1, 'the attack animation itself must be in the playlist');
    assert.ok(impactIndex > -1, 'the impact animation must be in the playlist');
    assert.ok(attackIndex < impactIndex, 'attack animation comes before its own impact');
    assert.ok(impactIndex < dialogIndex, 'the impact animation must be queued before the dialog starts');
});

test('convert_action_list_to_playlist() plays a round that is only the closing FightDialog (no one acted)', () => {
    // A fight decided before anyone acts (every monster already down)
    // sends just its conclusion: there's no Turn, so no actor.
    const ctx = freshProcess({ get_hero: () => { throw new Error('there is no actor to look up'); } });
    const playlist = ctx.convert_action_list_to_playlist([['FightDialog', ['The party has won!']]]);
    assert.ok(playlist.some((step) => step[1] === 'promise_event' && step[3] === 'The party has won!'));
    assert.ok(!playlist.some((step) => step[1] === 'present_group'), 'no group to bring on screen');
});
