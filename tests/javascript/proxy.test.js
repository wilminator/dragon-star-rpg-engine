'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

// Values that originate inside a vm.createContext() (as anything
// pulled off ctx.* here does) are a different realm than Node's own
// Array/Object -- assert.deepEqual's reference/prototype checks fail
// on them even when the values are structurally identical, which is
// a vm quirk, not a real difference. A JSON round-trip sidesteps it
// cleanly, and is a reasonable equivalence to assert here anyway:
// this is a communication layer where everything genuinely does pass
// through JSON at some point.
function assertJsonEqual(actual, expected, message) {
    assert.equal(JSON.stringify(actual), JSON.stringify(expected), message);
}

// A stand-in for fetch(): records each request and lets the test
// answer it when it chooses, so ordering and overlap can be checked.
function fakeFetch() {
    const requests = [];
    const fetch = (url, options) => new Promise((resolve, reject) => {
        requests.push({
            url, options, body: JSON.parse(options.body),
            respond: (text, status = 200) => resolve({ ok: status >= 200 && status < 300, status, text: () => Promise.resolve(text) }),
            fail: (error) => reject(error),
        });
    });
    return { fetch, requests };
}

function freshProxy(extraGlobals = {}) {
    const server = fakeFetch();
    const ctx = loadBrowserScripts(['proxy.js'], { fetch: server.fetch, ...extraGlobals });
    ctx.__server = server;
    ctx.init_tranceiver('processor.php');
    return ctx;
}

// Lets queued promise callbacks run.
const settle = () => new Promise((resolve) => setImmediate(resolve));

// --- sending ---

test('a call is sent at once, without waiting for a timer', async () => {
    const ctx = freshProxy();
    ctx.data_request('request_fight_data', 42);
    await settle();

    assert.equal(ctx.__server.requests.length, 1);
    assert.equal(ctx.__server.requests[0].url, 'processor.php');
    assert.equal(ctx.__server.requests[0].options.method, 'POST');
    assertJsonEqual(ctx.__server.requests[0].body, [['request_fight_data', [42]]]);
});

test('calls made together travel together, in order', async () => {
    const ctx = freshProxy();
    ctx.data_request('change_character_command', 0, 1, 2, [0, [1, 0, 0], 0], 5);
    ctx.data_request('process_fight_commands', 5);
    await settle();

    assert.equal(ctx.__server.requests.length, 1);
    assertJsonEqual(ctx.__server.requests[0].body, [
        ['change_character_command', [0, 1, 2, [0, [1, 0, 0], 0], 5]],
        ['process_fight_commands', [5]],
    ]);
});

test('nothing is sent when nothing was asked', async () => {
    const ctx = freshProxy();
    await settle();
    assert.equal(ctx.__server.requests.length, 0);
});

test('a request on its way is never cut off: later calls wait for it, then go together', async () => {
    // The bug this replaces: the old pump started a new request every
    // 2.5 s on the same XMLHttpRequest, cancelling a slow one and
    // losing its answer.
    const ctx = freshProxy();
    const handled = [];
    ctx.window.update_timerbox = (...args) => handled.push(args);

    ctx.data_request('check_fight_timeout', 3);
    await settle();
    ctx.data_request('change_character_command', 0, 0, 0, [], 3);
    ctx.data_request('ack_playback', 3);
    await settle();
    assert.equal(ctx.__server.requests.length, 1, 'still waiting on the first');

    ctx.__server.requests[0].respond(JSON.stringify([['update_timerbox', 30]]));
    await settle();

    assertJsonEqual(handled, [[30]], 'the slow answer still arrives');
    assert.equal(ctx.__server.requests.length, 2);
    assertJsonEqual(ctx.__server.requests[1].body, [
        ['change_character_command', [0, 0, 0, [], 3]],
        ['ack_playback', [3]],
    ]);
});

test('the promise settles once the answer has been handed out', async () => {
    const ctx = freshProxy();
    const order = [];
    ctx.window.update_timerbox = () => order.push('handled');

    const done = ctx.data_request('check_fight_timeout', 3).then(() => order.push('settled'));
    await settle();
    ctx.__server.requests[0].respond(JSON.stringify([['update_timerbox', 10]]));
    await done;

    assertJsonEqual(order, ['handled', 'settled']);
});

test('calls made before the transceiver starts wait for it', async () => {
    const server = fakeFetch();
    const ctx = loadBrowserScripts(['proxy.js'], { fetch: server.fetch });
    ctx.data_request('request_fight_data', 1);
    await settle();
    assert.equal(server.requests.length, 0);

    ctx.init_tranceiver('processor.php');
    await settle();
    assert.equal(server.requests.length, 1);
});

// --- answers ---

test('an answer is handed to the allowlisted handlers', async () => {
    const ctx = freshProxy();
    let received = null;
    ctx.window.receive_fight_data = (...args) => { received = args; };

    ctx.data_request('request_fight_data', 1);
    await settle();
    ctx.__server.requests[0].respond(JSON.stringify([['receive_fight_data', 1, { a: 1 }, [], { b: 2 }, 30]]));
    await settle();

    assertJsonEqual(received, [1, { a: 1 }, [], { b: 2 }, 30]);
});

test('an empty or malformed answer is a clean no-op, and the next call still goes', async () => {
    const ctx = freshProxy({ console: { ...console, error: () => {} } });
    let called = false;
    ctx.window.r = () => { called = true; };

    ctx.data_request('request_fight_data', 1);
    await settle();
    ctx.__server.requests[0].respond('');
    await settle();
    ctx.data_request('request_fight_data', 2);
    await settle();

    assert.equal(called, false);
    assert.equal(ctx.__server.requests.length, 2);
});

test('a failed request is logged, and the next call still goes', async () => {
    const errors = [];
    const ctx = freshProxy({ console: { ...console, error: (...args) => errors.push(args) } });

    const done = ctx.data_request('request_fight_data', 1);
    await settle();
    ctx.__server.requests[0].respond('Server error', 500);
    await done;
    ctx.__server.requests.length = 0;
    ctx.data_request('request_fight_data', 2);
    await settle();

    assert.equal(errors.length, 1);
    assert.equal(ctx.__server.requests.length, 1);
});

// --- data_dispatch() ---

test('data_dispatch() calls a known, allowlisted response type with the right arguments', () => {
    const ctx = freshProxy();
    let received = null;
    ctx.window.update_timerbox = (...args) => { received = args; };
    ctx.data_dispatch([['update_timerbox', 30]]);
    assert.deepEqual(received, [30]);
});

test('data_dispatch() never calls a function that is not in the allowlist, even if it exists', () => {
    // The actual exploit this guards against: the server used to be
    // eval()'d dynamically too, and this is the client-side half of
    // the same class of bug -- a response type outside the fixed
    // list must never be invoked, regardless of what happens to be
    // defined globally.
    const ctx = freshProxy();
    let exploitRan = false;
    ctx.window.system = () => { exploitRan = true; };
    ctx.data_dispatch([['system', 'rm -rf /']]);
    assert.equal(exploitRan, false);
});

test('data_dispatch() handles an unknown response type without throwing', () => {
    const ctx = freshProxy();
    assert.doesNotThrow(() => ctx.data_dispatch([['totally_unknown_thing', 1, 2, 3]]));
});

test('data_dispatch() handles an allowlisted type with no handler defined, without throwing', () => {
    const ctx = freshProxy();
    // 'load_map_data' is allowlisted but this test deliberately never
    // defines it on window.
    assert.doesNotThrow(() => ctx.data_dispatch([['load_map_data', 'map1']]));
});

test('data_dispatch() processes every entry even if an earlier one throws', () => {
    const ctx = freshProxy();
    let secondCalled = false;
    ctx.window.receive_fight_data = () => { throw new Error('boom'); };
    ctx.window.update_timerbox = () => { secondCalled = true; };
    ctx.data_dispatch([
        ['receive_fight_data', 1, {}, [], {}, 30],
        ['update_timerbox', 30],
    ]);
    assert.equal(secondCalled, true);
});

// --- init_tranceiver() / kill_tranceiver() ---

test('there is no timer at all', () => {
    let timers = 0;
    const server = fakeFetch();
    const ctx = loadBrowserScripts(['proxy.js'], { fetch: server.fetch, setInterval: () => { timers++; return 1; } });
    assert.equal(ctx.init_tranceiver('processor.php'), true);
    assert.equal(timers, 0);
});

test('kill_tranceiver() stops sending, drops waiting calls and cancels the request on its way', async () => {
    let aborted = false;
    class FakeAbortController {
        constructor() { this.signal = {}; }
        abort() { aborted = true; }
    }
    const ctx = freshProxy({ AbortController: FakeAbortController });
    let handled = false;
    ctx.window.update_timerbox = () => { handled = true; };

    ctx.data_request('check_fight_timeout', 1);
    await settle();
    ctx.data_request('ack_playback', 1);
    ctx.kill_tranceiver();
    ctx.__server.requests[0].respond(JSON.stringify([['update_timerbox', 5]]));
    await settle();

    assert.equal(aborted, true);
    assert.equal(handled, false, 'nothing is handed out after leaving');
    assert.equal(ctx.__server.requests.length, 1);
});

test('every request carries the page\'s CSRF token as a header', async () => {
    const ctx = freshProxy({ rpc_csrf_token: 'tok123' });
    ctx.data_request('request_fight_data', 1);
    await settle();
    assert.equal(ctx.__server.requests[0].options.headers['X-CSRF-Token'], 'tok123');
});

test('without a token on the page, no header is sent', async () => {
    const ctx = freshProxy();
    ctx.data_request('request_fight_data', 1);
    await settle();
    assert.equal(JSON.stringify(ctx.__server.requests[0].options.headers), '{}');
});
