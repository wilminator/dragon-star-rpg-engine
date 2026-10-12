'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function freshRpc(fetchMock) {
    return loadBrowserScripts(['rpc.js'], { fetch: fetchMock });
}

test('call_server() sends a one-element [method, args] envelope', async () => {
    let sentUrl = null, sentBody = null;
    const ctx = freshRpc((url, opts) => {
        sentUrl = url;
        sentBody = opts.body;
        return Promise.resolve({ ok: true, text: () => Promise.resolve(JSON.stringify([['ack', 1]])) });
    });
    await ctx.call_server('report_anomalies', [{ type: 'x' }]);
    assert.equal(sentUrl, 'processor.php');
    assert.equal(sentBody, JSON.stringify([['report_anomalies', [[{ type: 'x' }]]]]));
});

test('call_server() resolves with the response args, not the response type name', async () => {
    const ctx = freshRpc(() =>
        Promise.resolve({ ok: true, text: () => Promise.resolve(JSON.stringify([['anomalies_received', 2]])) })
    );
    const result = await ctx.call_server('report_anomalies', []);
    assert.equal(JSON.stringify(result), JSON.stringify([2]));
});

test('call_server() forwards multiple variadic arguments correctly', async () => {
    let sentBody = null;
    const ctx = freshRpc((url, opts) => {
        sentBody = opts.body;
        return Promise.resolve({ ok: true, text: () => Promise.resolve(JSON.stringify([['ack']])) });
    });
    await ctx.call_server('some_method', 1, 'two', [3]);
    assert.equal(sentBody, JSON.stringify([['some_method', [1, 'two', [3]]]]));
});

test('call_server() rejects on a non-ok HTTP status', async () => {
    const ctx = freshRpc(() => Promise.resolve({ ok: false, status: 500 }));
    await assert.rejects(() => ctx.call_server('report_anomalies', []), /HTTP 500/);
});

test('call_server() rejects cleanly on an empty response body, not a raw parse error', async () => {
    // The exact real-world case that used to produce a garbage
    // dispatch in the old proxy.js transport (see receive_data()'s
    // own test in proxy.test.js) -- here it should simply reject with
    // a clear message instead.
    const ctx = freshRpc(() => Promise.resolve({ ok: true, text: () => Promise.resolve('') }));
    await assert.rejects(() => ctx.call_server('report_anomalies', []), /malformed response \(empty\)/);
});

test('call_server() rejects cleanly on a malformed (non-empty) response body', async () => {
    const ctx = freshRpc(() => Promise.resolve({ ok: true, text: () => Promise.resolve('not json{{{') }));
    await assert.rejects(() => ctx.call_server('report_anomalies', []), /malformed response \(unparsable\)/);
});

test('call_server() rejects when the server returns an empty response array', async () => {
    const ctx = freshRpc(() => Promise.resolve({ ok: true, text: () => Promise.resolve('[]') }));
    await assert.rejects(() => ctx.call_server('report_anomalies', []), /no response/);
});

test('call_server() sends the page\'s CSRF token as a header', async () => {
    let sent = null;
    const ctx = loadBrowserScripts(['rpc.js'], {
        rpc_csrf_token: 'tok456',
        fetch: (url, options) => { sent = options; return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('[["x",1]]') }); },
    });
    await ctx.call_server('character_sheet', 7);
    assert.equal(sent.headers['X-CSRF-Token'], 'tok456');
});
