// A single self-contained call: call_server() sends one request and
// resolves with that call's own answer, instead of handing answers out
// by name the way proxy.js's data_request() does. Used where the caller
// wants the answer itself (report_anomalies). proxy.js no longer runs a
// timer either, but still routes answers through its allowlisted
// handlers, which the combat code is built around.
//
// The wire format is UNCHANGED, deliberately: processor.php still
// expects a JSON array of [method, args] pairs and returns a JSON
// array of [responseType, ...responseArgs] results, since it hasn't
// dropped that batched-array shape yet. call_server() just always
// sends a one-element array and reads back the one-element response
// -- each call is its own transaction from the caller's point of
// view even though the wire envelope still looks like a batch of
// one. When processor.php does drop the array wrapper for a
// single-call shape, only this function's request body and response
// parsing need to change, not anything that calls it.
const RPC_ENDPOINT = 'processor.php';

// The page's CSRF token as the header processor.php checks (see
// proxy.js, which defines the same function when it's loaded too).
if (typeof rpc_request_headers !== 'function') {
    var rpc_request_headers = function () {
        return typeof rpc_csrf_token === 'string' && rpc_csrf_token !== '' ? {'X-CSRF-Token': rpc_csrf_token} : {};
    };
}

function call_server(method, ...args) {
    return fetch(RPC_ENDPOINT, {
        method: 'POST',
        body: JSON.stringify([[method, args]]),
        headers: rpc_request_headers(),
    })
    .then((response) => {
        if (!response.ok) {
            throw new Error(`call_server(${method}): HTTP ${response.status}`);
        }
        return response.text();
    })
    .then((text) => {
        var responses;
        try {
            responses = JSON.parse(text);
        } catch (error) {
            throw new Error(`call_server(${method}): malformed response (${text === '' ? 'empty' : 'unparsable'})`);
        }
        if (!Array.isArray(responses) || responses.length === 0) {
            throw new Error(`call_server(${method}): server returned no response`);
        }
        // responses[0][0] is the response's own declared type (e.g.
        // 'anomalies_received') -- not inspected here to decide what
        // to do with it, unlike proxy.js's data_dispatch(): the
        // caller already knows what it asked for and is the one
        // deciding what the response means, not a name-keyed global
        // dispatch table.
        var [, ...responseArgs] = responses[0];
        return responseArgs;
    });
}
