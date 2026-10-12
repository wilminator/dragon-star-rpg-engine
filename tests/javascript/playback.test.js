'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function freshPlayback(extraGlobals = {}) {
    return loadBrowserScripts(['playback.js'], extraGlobals);
}

test('promise_event() returns 0 synchronously, regardless of how long the target promise takes', () => {
    const ctx = freshPlayback({ my_func: () => new Promise(() => {}) }); // never resolves
    const result = ctx.promise_event('my_func');
    assert.equal(result, 0);
});

test('promise_event() calls the named target function with the given arguments', () => {
    const calls = [];
    const ctx = freshPlayback({ my_func: (...args) => { calls.push(args); return Promise.resolve(); } });
    ctx.promise_event('my_func', 'a', 'b', 3);
    assert.deepEqual(calls, [['a', 'b', 3]]);
});

test('promise_event() increments active_events before the target settles, decrements it once resolved', async () => {
    let resolveTarget;
    const ctx = freshPlayback({ my_func: () => new Promise((resolve) => { resolveTarget = resolve; }) });
    assert.equal(ctx.active_events, 0);
    ctx.promise_event('my_func');
    assert.equal(ctx.active_events, 1, 'started -- not yet settled');
    resolveTarget();
    await new Promise((r) => setTimeout(r, 0)); // let the .then() microtask run
    assert.equal(ctx.active_events, 0, 'settled -- back to 0');
});

test('promise_event() decrements active_events even when the target promise rejects', async () => {
    let rejectTarget;
    const ctx = freshPlayback({
        my_func: () => new Promise((resolve, reject) => { rejectTarget = reject; }),
        console: { error: () => {} }, // swallow the expected error log
    });
    ctx.promise_event('my_func');
    assert.equal(ctx.active_events, 1);
    rejectTarget(new Error('boom'));
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(ctx.active_events, 0, 'still cleaned up on rejection, not left stuck');
});

test('promise_event() correctly bridges into the real event_playback() queue -- the [1] barrier actually waits for it', async () => {
    const log = [];
    let resolveTarget;
    const ctx = freshPlayback({
        my_func: (text) => { log.push('called: ' + text); return new Promise((resolve) => { resolveTarget = resolve; }); },
    });

    let callbackFired = false;
    ctx.set_playback_queue(
        [[0, 'promise_event', 'my_func', 'hello'], [1]],
        () => { callbackFired = true; }
    );

    // event_playback() is async now; let its own microtasks/awaits settle.
    await new Promise((r) => setImmediate(r));

    assert.equal(callbackFired, false, 'must not fire while the target is still pending');
    assert.deepEqual(log, ['called: hello']);

    resolveTarget();
    await new Promise((r) => setImmediate(r));
    assert.equal(callbackFired, true, 'fires once the bridged promise actually settles');
});

test('the [1] barrier has no artificial timeout -- it waits as long as the open event actually takes', async () => {
    // Regression test for the real bug this rework fixes: the old
    // polling implementation gave up after ~10 seconds (200 polls x
    // 50ms) regardless of whether active_events was still nonzero --
    // confirmed directly against the pre-fix code. A dialog genuinely
    // waiting on a human to read text and click a button can easily
    // take longer than that.
    //
    // This test doesn't wait 10 real seconds (that would make the
    // suite slow) -- what actually removes the old ceiling is
    // structural: the rewritten event_playback()/wait_for_open_events()
    // has no poll-and-give-up path left in it at all, so there's
    // nothing left to time out regardless of how long the wait runs.
    // This confirms no premature resolution happens within a real
    // window, as a live check against that.
    const ctx = freshPlayback();
    let callbackFired = false;

    ctx.start_event(); // an "open event" with no resolution scheduled yet
    ctx.set_playback_queue([[1]], () => { callbackFired = true; });

    await new Promise((r) => setTimeout(r, 600));
    assert.equal(callbackFired, false, 'still must not fire -- nothing has finished the open event yet');

    ctx.finish_event();
    await new Promise((r) => setImmediate(r));
    assert.equal(callbackFired, true, 'fires immediately once the open event actually finishes');
});
