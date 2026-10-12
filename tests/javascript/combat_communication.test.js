'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

// receive_fight_data() calls into several other modules
// (fight_common.js's synchronize_fight_data(), process.js's
// convert_action_list_to_playlist(), html_loader.js's cache/loader
// functions, html_common.js's set_menu_state()) purely for rendering
// -- this test's whole point is the communication/data-handling
// logic in combat.js itself, not those modules, so they're provided
// as no-op spies rather than loaded for real. That keeps a failure
// here pointing at combat.js, not at an unrelated rendering module.
function freshCombat(overrides = {}) {
    const calls = {};
    const spy = (name, impl) => {
        calls[name] = [];
        return (...args) => { calls[name].push(args); return impl ? impl(...args) : undefined; };
    };

    const ctx = loadBrowserScripts(['combat.js'], {
        // Globals combat.js expects to already exist (normally set by
        // html_fight.php's own inline <script> block and other
        // modules loaded before it).
        fightid: 42,
        player_party: 0,
        teamid: 7,
        fight_screen: { style: {} },
        static_images: [],
        background_images: '/images/backgrounds/',
        MENU_STATE_FIGHT_NONE: 'fight_none',
        MENU_STATE_FIGHT_PLAYER: 'fight_player',
        MENU_STATE_FIGHT_WAIT: 'fight_wait',

        data_request: spy('data_request'),
        play_music: spy('play_music'),
        stop_music: spy('stop_music'),
        synchronize_fight_data: spy('synchronize_fight_data'),
        convert_action_list_to_playlist: spy('convert_action_list_to_playlist', (actions) => `playlist(${JSON.stringify(actions)})`),
        set_loader_percentage: spy('set_loader_percentage'),
        set_loader_message: spy('set_loader_message'),
        set_menu_state: spy('set_menu_state'),
        update_timerbox: spy('update_timerbox'),
        object_show: spy('object_show'),
        object_hide: spy('object_hide'),
        object_get: spy('object_get'),
        object_create_timerbox: spy('object_create_timerbox'),
        preload_hero_images: spy('preload_hero_images'),
        cache_fight_sounds: spy('cache_fight_sounds'),
        push_image_array: spy('push_image_array'),
        cache_images: spy('cache_images'),
        is_image_cache_loaded: () => [1, 1],
        timeout_time_left: () => 999,
        stop_fight_timeout_checks: spy('stop_fight_timeout_checks'),
        test_own_party_dead: () => false,
        test_other_parties_dead: () => false,
        fight_layout: spy('fight_layout'),
        slide_out: spy('slide_out'),
        kill_tranceiver: spy('kill_tranceiver'),
        player_slide: { oldgroup: 0, oldparty: 0 },
        ...overrides,
    });
    ctx.__calls = calls;

    // verify_cache() (and everything downstream of it: verify_fight_html(),
    // verify_audio_precache(), begin_fight()) is combat.js's own
    // animation-startup cascade, not communication -- providing these
    // as context overrides before loading doesn't work, since the
    // real file's own `function verify_cache() {...}` etc.
    // declarations simply overwrite them once loaded. Overriding them
    // AFTER loading works because JS functions resolve global names
    // at call time, not definition time -- receive_fight_data()'s own
    // call to verify_cache() picks up whichever one is current when
    // it actually runs.
    ctx.verify_cache = spy('verify_cache');
    ctx.fight_terminate = spy('fight_terminate');

    return ctx;
}

test('request_fight_data() asks the server for this specific fightid', () => {
    const ctx = freshCombat();
    ctx.request_fight_data();
    assert.deepEqual(ctx.__calls.data_request[0], ['request_fight_data', 42]);
});

test('receive_fight_data() stores the sequence number from the response', () => {
    const ctx = freshCombat();
    ctx.receive_fight_data(5, {}, [], { music: 'a.mp3', background: 'bg.png' }, 30);
    assert.equal(ctx.sequence_number, 5);
});

test('receive_fight_data() hands prefight off to synchronize_fight_data() unmodified', () => {
    const ctx = freshCombat();
    const prefight = { parties: [{ groups: [] }] };
    ctx.receive_fight_data(1, prefight, [], { music: 'a.mp3', background: 'bg.png' }, 30);
    assert.equal(JSON.stringify(ctx.__calls.synchronize_fight_data[0][0]), JSON.stringify(prefight));
});

test('receive_fight_data() stores the post-fight (end) state for the combat callback to adopt later', () => {
    const ctx = freshCombat();
    const postfight = { parties: [{ groups: [] }], music: 'a.mp3', background: 'bg.png' };
    ctx.receive_fight_data(1, {}, [], postfight, 30);
    assert.equal(JSON.stringify(ctx.postfight), JSON.stringify(postfight));
});

test('receive_fight_data() converts the raw action list via convert_action_list_to_playlist()', () => {
    const ctx = freshCombat();
    ctx.receive_fight_data(1, {}, [['Turn', [0, 0, 0]]], { music: 'a.mp3', background: 'bg.png' }, 30);
    assert.equal(ctx.__calls.convert_action_list_to_playlist.length, 1);
    assert.equal(JSON.stringify(ctx.__calls.convert_action_list_to_playlist[0][0]), JSON.stringify([['Turn', [0, 0, 0]]]));
    assert.equal(ctx.combat_playback, 'playlist([["Turn",[0,0,0]]])');
});

test('receive_fight_data() forwards the server timeout to update_timerbox() unmodified', () => {
    const ctx = freshCombat();
    ctx.receive_fight_data(1, {}, [], { music: 'a.mp3', background: 'bg.png' }, 47);
    assert.deepEqual(ctx.__calls.update_timerbox[0], [47]);
});

test('receive_fight_data() starts music/background only on the FIRST call (postfight was null)', () => {
    const ctx = freshCombat();
    assert.equal(ctx.postfight, null);
    ctx.receive_fight_data(1, {}, [], { music: 'battle.mp3', background: 'forest.png' }, 30);
    assert.deepEqual(ctx.__calls.play_music[0], ['battle.mp3']);
    assert.ok(ctx.fight_screen.style.backgroundImage.includes('forest.png'));
});

test('receive_fight_data() does NOT restart music/background on a later call (postfight already set)', () => {
    const ctx = freshCombat();
    ctx.receive_fight_data(1, {}, [], { music: 'battle.mp3', background: 'forest.png' }, 30);
    ctx.receive_fight_data(2, {}, [], { music: 'battle.mp3', background: 'forest.png' }, 25);
    assert.equal(ctx.__calls.play_music.length, 1, 'play_music should only ever fire once per fight');
});

test('receive_fight_data() stores the redirect for combat_callback() to act on later', () => {
    const ctx = freshCombat();
    ctx.receive_fight_data(1, {}, [], { music: 'a.mp3', background: 'bg.png' }, 30, 'setup_fight.php');
    assert.equal(ctx.pending_redirect, 'setup_fight.php');
});

test('receive_fight_data() stores a null redirect as null (still in progress, keep going)', () => {
    const ctx = freshCombat();
    ctx.receive_fight_data(1, {}, [], { music: 'a.mp3', background: 'bg.png' }, 30, null);
    assert.equal(ctx.pending_redirect, null);
});

test('combat_callback() terminates via fight_terminate() immediately when a redirect is pending, not the old finish_fight() round trip', () => {
    // The actual unified-path change this covers: no client-side
    // party_dead check and no separate finish_fight() RPC call --
    // the server already decided, as of the last receive_fight_data()
    // response, that this session is done and where it goes next.
    //
    // No artificial pause here anymore: combat_callback is
    // set_playback_queue's own completion callback, so by the time it
    // runs, the whole queue -- including a FightDialog entry's own
    // promise_event/[1] barrier wait -- has already drained. The
    // player has already seen the results and dismissed the dialog
    // before combat_callback is ever reached, so terminating
    // immediately here is correct.
    const ctx = freshCombat();
    ctx.postfight = { parties: [] };
    ctx.pending_redirect = 'login.php';

    ctx.combat_callback();

    assert.equal(ctx.__calls.fight_terminate.length, 1);
    assert.equal(ctx.__calls.data_request.length, 0, 'no finish_fight (or any other) RPC call should be made');
});

test('combat_callback() proceeds to the normal player-turn UI when no redirect is pending', () => {
    const ctx = freshCombat();
    ctx.postfight = { parties: [] };
    ctx.pending_redirect = null;
    ctx.sequence_number = 0; // declared in commit.js, which this test doesn't load

    ctx.combat_callback();

    assert.equal(ctx.__calls.fight_terminate.length, 0);
    assert.equal(ctx.__calls.slide_out.length, 1);
    assert.deepEqual(ctx.__calls.set_menu_state[0], ['fight_player']);
});

test('init_fight() requests fight data as its first real action', () => {
    const ctx = freshCombat();
    ctx.init_fight();
    assert.deepEqual(ctx.__calls.data_request[0], ['request_fight_data', 42]);
});

// --- spectating ---
// A spectator page is rendered with spectate_guid set and teamid null.
// It must use only the read-only spectator calls, poll after each
// round instead of opening the player menu, and never commit.

function freshSpectator(overrides = {}) {
    const scheduled = [];
    const ctx = freshCombat({
        teamid: null,
        spectate_guid: 'abc123',
        spectate_team: null,
        player_party: 1,
        setTimeout: (fn, ms) => scheduled.push([fn, ms]),
        ...overrides,
    });
    ctx.__scheduled = scheduled;
    return ctx;
}

test('a spectator requests fight data through the spectator call, by guid and party', () => {
    const ctx = freshSpectator();
    ctx.request_fight_data();
    assert.equal(JSON.stringify(ctx.__calls.data_request), JSON.stringify([['request_spectator_data', 'abc123', 1, null]]));
});

test('a spectator seated by team guid sends it with every spectator call', () => {
    const ctx = freshSpectator({ spectate_team: 'team-guid' });
    ctx.request_fight_data();
    ctx.receive_fight_data(0, {}, [], { music: 'a.mp3', background: 'bg.png' }, 30, null);
    ctx.postfight = { parties: [] };
    ctx.pending_redirect = null;
    ctx.sequence_number = 4;
    ctx.combat_callback();
    ctx.__scheduled.at(-1)[0]();

    assert.equal(JSON.stringify(ctx.__calls.data_request), JSON.stringify([
        ['request_spectator_data', 'abc123', 1, 'team-guid'],
        ['request_spectator_resource_manifest', 'abc123', 1, 'team-guid'],
        ['poll_spectator_data', 'abc123', 1, 4, 'team-guid'],
    ]));
});

test('a player still requests fight data through the participant call', () => {
    const ctx = freshCombat();
    ctx.request_fight_data();
    assert.equal(ctx.__calls.data_request[0][0], 'request_fight_data');
});

test('a spectator asks for the resource manifest through the spectator call', () => {
    const ctx = freshSpectator();
    ctx.receive_fight_data(0, {}, [], { music: 'a.mp3', background: 'bg.png' }, 30, null);
    const last = ctx.__calls.data_request.at(-1);
    assert.equal(JSON.stringify(last), JSON.stringify(['request_spectator_resource_manifest', 'abc123', 1, null]));
});

test('after a round, a spectator schedules a poll instead of opening the player menu', () => {
    const ctx = freshSpectator();
    ctx.postfight = { parties: [] };
    ctx.pending_redirect = null;
    ctx.sequence_number = 4;

    ctx.combat_callback();

    assert.equal(ctx.__calls.set_menu_state.length, 0, 'never enters the player menu');
    assert.equal(ctx.__scheduled.length, 1);
    ctx.__scheduled[0][0]();
    assert.equal(JSON.stringify(ctx.__calls.data_request.at(-1)), JSON.stringify(['poll_spectator_data', 'abc123', 1, 4, null]));
});

test('a spectator waiting for the next round refreshes the timer and polls again', () => {
    const ctx = freshSpectator();
    ctx.receive_spectator_wait(4, 90);
    assert.equal(JSON.stringify(ctx.__calls.update_timerbox.at(-1)), JSON.stringify([90]));
    assert.equal(ctx.__scheduled.length, 1);
});

test('a spectator leaves for the named page when the fight ends or access is denied', () => {
    for (const fn of ['receive_spectator_end', 'spectator_access_denied']) {
        const ctx = freshSpectator();
        ctx[fn]('spectate.php');
        assert.equal(ctx.pending_redirect, 'spectate.php');
        assert.equal(ctx.__calls.fight_terminate.length, 1);
    }
});

test('a spectator and a replay viewer never commit, even when the timer reaches zero', () => {
    const calls = [];
    const ctx = loadBrowserScripts(['commit.js'], {
        teamid: null,
        data_request: (...args) => calls.push(args),
        get_party: () => { throw new Error('must not get this far'); },
    });
    ctx.commit_commands();
    assert.equal(calls.length, 0);
});

// --- playback acknowledgement ---

test('after a round plays back, a player acknowledges it before entering the command menu', () => {
    const ctx = freshCombat();
    ctx.postfight = { parties: [] };
    ctx.pending_redirect = null;
    ctx.sequence_number = 3;

    ctx.combat_callback();

    assert.equal(JSON.stringify(ctx.__calls.data_request.at(-1)), JSON.stringify(['ack_playback', 3]));
    assert.equal(JSON.stringify(ctx.__calls.set_menu_state.at(-1)), JSON.stringify(['fight_player']));
});

test('a spectator never acknowledges playback', () => {
    const ctx = freshSpectator();
    ctx.postfight = { parties: [] };
    ctx.pending_redirect = null;
    ctx.combat_callback();
    assert.ok(!ctx.__calls.data_request.some((c) => c[0] === 'ack_playback'));
});

test('a new round arriving stops any pending timeout check', () => {
    const ctx = freshCombat();
    ctx.receive_fight_data(4, {}, [], { music: 'a.mp3', background: 'bg.png' }, 30, null);
    assert.equal(ctx.__calls.stop_fight_timeout_checks.length, 1);
});

// --- commit.js: the single timeout-check loop ---

function freshCommit() {
    const calls = { data_request: [], update_timerbox: [], cleared: [] };
    const scheduled = [];
    const ctx = loadBrowserScripts(['commit.js'], {
        teamid: 7,
        data_request: (...a) => calls.data_request.push(a),
        update_timerbox: (...a) => calls.update_timerbox.push(a),
        setTimeout: (fn, ms) => { scheduled.push([fn, ms]); return scheduled.length; },
        clearTimeout: (h) => calls.cleared.push(h),
    });
    ctx.sequence_number = 2; // commit.js declares it (as 0) on load
    return { ctx, calls, scheduled };
}

test('wait_for_fight_timeout() keeps a single check loop, replacing any check already scheduled', () => {
    const { ctx, calls, scheduled } = freshCommit();
    ctx.wait_for_fight_timeout(90);
    ctx.wait_for_fight_timeout(80);
    assert.equal(scheduled.length, 2);
    assert.equal(JSON.stringify(calls.cleared), JSON.stringify([1]), 'the first check was cancelled');
    scheduled[1][0]();
    assert.equal(JSON.stringify(calls.data_request), JSON.stringify([['check_fight_timeout', 2]]));
});

test('wait_for_fight_timeout() without a time leaves the timer alone', () => {
    const { ctx, calls } = freshCommit();
    ctx.wait_for_fight_timeout();
    ctx.wait_for_fight_timeout(45);
    assert.equal(JSON.stringify(calls.update_timerbox), JSON.stringify([[45]]));
});

test('stop_fight_timeout_checks() cancels the scheduled check', () => {
    const { ctx, calls } = freshCommit();
    ctx.wait_for_fight_timeout(90);
    ctx.stop_fight_timeout_checks();
    assert.equal(JSON.stringify(calls.cleared), JSON.stringify([1]));
});

test('human_control_notice() speaks up only about other people\'s parties', () => {
    const ctx = freshCombat();
    assert.equal(ctx.human_control_notice([1], 0), 'A player is controlling the monsters.');
    assert.equal(ctx.human_control_notice([1], 1), null, 'the monster player themselves');
    assert.equal(ctx.human_control_notice([], 0), null);
    assert.equal(ctx.human_control_notice(undefined, 0), null, 'older servers send nothing');
});
