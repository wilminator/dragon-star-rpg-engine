'use strict';

// The round timer's audible cues (timerbox.js).
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function context(state = 1, teamid = 5) {
    const played = [], said = [];
    const ctx = loadBrowserScripts(['timerbox.js'], {
        MENU_STATE_FIGHT_NONE: 0, MENU_STATE_FIGHT_PLAYER: 1, MENU_STATE_FIGHT_WAIT: 6, menu_state: state, teamid,
        audio_clips: { timer: 'minute.mp3', timer_final: 'final.mp3' },
        play_sound: (clip) => played.push(clip),
        combat_keys_say: (text) => said.push(text),
    });
    return { ctx, played, said };
}

// Counts down from $from to 0, a second at a time.
function countdown(ctx, from) {
    for (let s = from; s >= 0; s--) ctx.timer_cue(s);
}

test('cues at each minute and at 30, 15, 10 and 5 seconds, not where the countdown starts', () => {
    const { ctx, played, said } = context();
    countdown(ctx, 120);
    assert.deepEqual(said, ['1 minute left', '30 seconds left', '15 seconds left', '10 seconds left', '5 seconds left'],
        'not "2 minutes": the countdown started there');
    assert.deepEqual(played, ['minute.mp3', 'minute.mp3', 'final.mp3', 'final.mp3', 'final.mp3']);
    const longer = context();
    countdown(longer.ctx, 185);
    assert.deepEqual(longer.said.slice(0, 3), ['3 minutes left', '2 minutes left', '1 minute left']);
});

test('each cue once, even as the display refreshes several times a second', () => {
    const { ctx, said } = context();
    for (const s of [40, 31, 30, 30, 30, 29, 30]) ctx.timer_cue(s);
    assert.deepEqual(said, ['30 seconds left']);
});

test('silent while a round plays, and for spectators', () => {
    const waiting = context(6);
    countdown(waiting.ctx, 70);
    assert.deepEqual(waiting.played, []);
    const spectator = context(1, null);
    countdown(spectator.ctx, 70);
    assert.deepEqual(spectator.played, []);
});

test('the server re-syncing the clock doesn\'t lose a cue; a new round starts a new countdown', () => {
    const { ctx, said } = context();
    for (const s of [40, 35, 31, 32, 30, 29]) ctx.timer_cue(s); // 32: a re-sync a second slow
    assert.deepEqual(said, ['30 seconds left']);
    for (const s of [80, 79]) ctx.timer_cue(s); // the next round
    countdown(ctx, 61);
    assert.equal(said.at(-5), '1 minute left');
});

test('the timer moves beside Settings and Commit only for a player controlling monsters', () => {
    const { ctx } = context();
    ctx.timerbox = { className: 'timerbox' };
    ctx.player_party = 1;
    ctx.fight = null;
    ctx.timerbox_place();
    assert.equal(ctx.timerbox.className, 'timerbox', 'no fight data yet: left where it is');
    ctx.fight = { parties: [{ monster: false }, { monster: true }] };
    ctx.timerbox_place();
    assert.equal(ctx.timerbox.className, 'timerbox timerboxlow');
    ctx.player_party = 0;
    ctx.timerbox_place();
    assert.equal(ctx.timerbox.className, 'timerbox', 'heroes: top right as before');
});
