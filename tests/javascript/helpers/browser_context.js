'use strict';

const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

// Loads the REAL, unmodified source of one or more browser JS files
// (app/javascript/*.js) into a fresh, isolated vm context and returns
// that context. Not a reimplementation of the client's logic -- these
// tests run the actual file content, the same way a browser would,
// just with browser globals (window, XMLHttpRequest, timers, DOM
// helpers) supplied explicitly rather than provided by a real page.
//
// Each call gets its own fresh context: app/javascript/proxy.js in
// particular has module-level mutable state (__xhr_outgoing_data,
// __xhr_timer) that would leak between tests if they all shared one
// global object, the way a plain eval() against Node's real global
// scope would. vm.createContext() is what actually prevents that.
//
// `extraGlobals` lets a test inject mocks (a fake XMLHttpRequest
// class, stub rendering functions like play_music, etc.) before the
// scripts run. `window` is set to the context object itself, matching
// how a real browser attaches top-level `function foo(){}` and
// `var foo` declarations to `window` automatically -- this is what
// lets app/javascript/proxy.js's own `window[funcName]` lookups in
// data_dispatch() work correctly against whatever the test defines.
function loadBrowserScripts(relativePaths, extraGlobals = {}) {
    const context = {
        console,
        setInterval,
        clearInterval,
        setTimeout,
        clearTimeout,
        ...extraGlobals,
    };
    context.window = context;
    vm.createContext(context);

    const javascriptDir = path.join(__dirname, '..', '..', '..', 'app', 'javascript');
    for (const relativePath of relativePaths) {
        const fullPath = path.join(javascriptDir, relativePath);
        const source = fs.readFileSync(fullPath, 'utf8');
        vm.runInContext(source, context, { filename: fullPath });
    }
    return context;
}

module.exports = { loadBrowserScripts };
