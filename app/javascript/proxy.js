// The client's side of talking to the server (processor.php).
//
// data_request(method, ...args) sends a call and returns a Promise that
// settles once the server's answer has been handed out: each entry of
// the answer, [responseType, ...args], goes to the global function of
// that name, if it's on the allowlist below (data_dispatch()).
//
// Calls go straight away -- there's no timer pumping a queue any more.
// They still go in order, one request at a time: calls made while a
// request is on its way (or in the same moment) travel together in the
// next one, in the order they were made, because the server relies on
// that order (commands saved before the round is confirmed, say).
// Nothing is ever cut off mid-flight, so no answer can be lost the way
// the old pump could lose one by starting a new request over a slow one.
//
// Anything that waits on the server (the round timer, a spectator
// waiting for the next round) asks for itself, on its own schedule --
// see commit.js and combat.js. When the Python server arrives, a
// WebSocket can replace the fetch below and push answers through the
// same data_dispatch(); nothing calling data_request() has to change.

var __rpc_url = 'processor.php';
var __rpc_pending = [];     // [[method, args], [promise resolve], ...] not yet sent
var __rpc_in_flight = false;
var __rpc_scheduled = false;
var __rpc_stopped = true;
var __rpc_abort = null;     // cancels the request on its way (leaving the page)

function init_tranceiver(url)
    {
    if (url)
        __rpc_url = url;
    __rpc_stopped = false;
    schedule_send();
    return true;
    }

function kill_tranceiver()
    {
    __rpc_stopped = true;
    __rpc_pending = [];
    if (__rpc_abort)
        __rpc_abort.abort();
    }

function data_request(funct, ...args)
    {
    return new Promise(function (resolve) {
        __rpc_pending.push({ call: [funct, args], done: resolve });
        schedule_send();
    });
    }

function schedule_send()
    {
    if (__rpc_scheduled || __rpc_in_flight || __rpc_stopped || __rpc_pending.length === 0)
        return;
    __rpc_scheduled = true;
    // After the current code finishes, so calls made together travel together.
    Promise.resolve().then(send_pending);
    }

function send_pending()
    {
    __rpc_scheduled = false;
    if (__rpc_in_flight || __rpc_stopped || __rpc_pending.length === 0)
        return;
    var batch = __rpc_pending;
    __rpc_pending = [];
    __rpc_in_flight = true;
    transmit_calls(batch.map(function (item) { return item.call; }))
        .then(function (responses) {
            if (responses && !__rpc_stopped)
                data_dispatch(responses);
        })
        .finally(function () {
            __rpc_in_flight = false;
            batch.forEach(function (item) { item.done(); });
            schedule_send();
        });
    }

// The page's CSRF token (rpc_csrf_token, printed by html_fight.php) as
// the header processor.php checks. Also in rpc.js, for pages that load
// only one of the two.
function rpc_request_headers()
    {
    return typeof rpc_csrf_token === 'string' && rpc_csrf_token !== '' ? {'X-CSRF-Token': rpc_csrf_token} : {};
    }

// One request: the calls in, the server's answers out (null on failure,
// which is logged -- the callers' own timers ask again where it matters).
function transmit_calls(calls)
    {
    __rpc_abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
    return fetch(__rpc_url, {
        method: 'POST',
        body: JSON.stringify(calls),
        headers: rpc_request_headers(),
        signal: __rpc_abort ? __rpc_abort.signal : undefined,
    })
    .then(function (response) {
        if (!response.ok)
            throw new Error('HTTP ' + response.status);
        return response.text();
    })
    .then(parse_responses)
    .catch(function (error) {
        if (!__rpc_stopped)
            console.error('Server request failed:', error, calls);
        return null;
    })
    .finally(function () {
        __rpc_abort = null;
    });
    }

// The server's answer: a JSON array of [responseType, ...args]. An empty
// or malformed body is logged and handed out as nothing.
function parse_responses(text)
    {
    try {
        var parsed = JSON.parse(text);
        if (Array.isArray(parsed))
            return parsed;
    }
    catch (error) {
    }
    console.error('Unreadable server response:', JSON.stringify(text));
    return null;
    }

// Every response type the server can ever send back via
// queue_response(), and nothing else -- confirmed against every
// queue_response() call site in the PHP codebase. Mirrors
// proxy.php's own dispatch_rpc_call() allowlist on the server side:
// same reasoning, same fix. A response type not in this list is
// rejected rather than silently attempted, the same way an unknown
// RPC method is on the server.
var KNOWN_RESPONSE_TYPES = {
    'fight_terminate': true,
    'load_map_data': true,
    'receive_fight_data': true,
    'receive_minimap_data': true,
    'receive_resource_manifest': true,
    'receive_spectator_end': true,
    'receive_spectator_wait': true,
    'request_fight_data': true,
    'spectator_access_denied': true,
    'update_timerbox': true,
    'wait_for_fight_timeout': true
};

function data_dispatch(data)
    {
    var index,index2,args,funcName;
    for(index in data)
        {
        funcName=data[index][0];
        args=[];
        for(index2=1;index2<data[index].length;index2++)
            args.push(data[index][index2]);
        try {
            // No eval() here at all: funcName is checked against a
            // fixed allowlist, then looked up directly as a global
            // function and invoked with the real argument values
            // (not string-built references to them) via .apply().
            // Previously this built a string like
            // "receive_fight_data(data[index][1],data[index][2]);"
            // and eval()'d it -- functionally equivalent for every
            // response the server actually sends, but with no
            // dynamic code execution involved at all now.
            if(!KNOWN_RESPONSE_TYPES[funcName])
                {
                console.error("Dispatch error: unknown response type",funcName);
                continue;
                }
            if(typeof window[funcName] !== 'function')
                {
                console.error("Dispatch error: no handler defined for",funcName);
                continue;
                }
            window[funcName].apply(null,args);
        }
        catch (error){
            console.error("Dispatch error:",error);
            console.log("funcName: "+ funcName);
            }
        }
    }

