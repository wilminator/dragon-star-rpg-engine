// Playing with a game controller (the browser's Gamepad API, standard
// layout). The controller drives the same key handling the keyboard does
// (dialogs.js, combat_keys.js), so every menu, list and fight screen that
// works from the keyboard works from a controller too. Buttons are the
// player's to change (Settings > Controls, controls.js); by default:
//
//   D-pad / left stick   the arrow keys (held: they repeat)
//   A                    Enter (or presses the focused button or checkbox)
//   B                    Escape
//   Y                    in a fight: commit your commands
//   Back / Select        Settings (again: closes it)
//   LB / RB              the previous / next control in a dialog (Tab)
//   left / right on a focused slider: turn it down / up
//
// Needs dialogs.js (dialog_key, dialog_tab, dialog_top) and controls.js.

// What each action presses (see gamepad_press()).
var GAMEPAD_ACTION_KEYS = {up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight',
    confirm: 'Enter', back: 'Escape', commit: 'Commit', settings: 'Settings',
    prev_control: 'ShiftTab', next_control: 'Tab'};
var GAMEPAD_BUTTON_COUNT = 17;
var GAMEPAD_DIRECTIONS = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];
var GAMEPAD_STICK = 0.5;          // how far the stick must lean
var GAMEPAD_REPEAT_AFTER = 400;   // ms held before a direction repeats
var GAMEPAD_REPEAT_EVERY = 130;   // ms between repeats
var GAMEPAD_SLIDER_STEP = 5;

var gamepad_held = {};            // name -> {since, last} while held
var gamepad_running = false;

// While Settings > Controls waits for a new button: gets the next one
// pressed instead of it doing anything (see gamepad_capture()).
var gamepad_capture_callback = null;
var gamepad_buttons_down = {};

function gamepad_capture(callback) {
    gamepad_capture_callback = callback;
}

// What the first controller has held down now: a set of names, through
// the player's button mapping. (Buttons just pushed go to a waiting
// capture instead.)
function gamepad_read() {
    var pads = (typeof navigator !== 'undefined' && navigator.getGamepads) ? navigator.getGamepads() : [];
    var held = {};
    for (var p = 0; p < pads.length; p++) {
        var pad = pads[p];
        if (!pad || pad.connected === false) continue;
        var down = {};
        for (var index = 0; index < GAMEPAD_BUTTON_COUNT; index++) {
            var button = pad.buttons[index];
            if (!button || !(button.pressed || button.value > 0.5)) continue;
            down[index] = true;
            if (gamepad_capture_callback && !gamepad_buttons_down[index]) {
                var callback = gamepad_capture_callback;
                gamepad_capture_callback = null;
                gamepad_buttons_down = down;
                callback(index);
                return {};
            }
            var action = typeof controls_pad === 'function' ? controls_pad(index) : null;
            if (action && GAMEPAD_ACTION_KEYS[action]) held[GAMEPAD_ACTION_KEYS[action]] = true;
        }
        gamepad_buttons_down = down;
        if (gamepad_capture_callback) return {};
        var x = pad.axes[0] || 0, y = pad.axes[1] || 0;
        if (y <= -GAMEPAD_STICK) held.ArrowUp = true;
        if (y >= GAMEPAD_STICK) held.ArrowDown = true;
        if (x <= -GAMEPAD_STICK) held.ArrowLeft = true;
        if (x >= GAMEPAD_STICK) held.ArrowRight = true;
        break; // the first controller only
    }
    return held;
}

// One look at the controller at time $now (ms): presses what was just
// pushed, and repeats directions held long enough. Returns what it pressed.
function gamepad_poll(now) {
    var held = gamepad_read();
    var pressed = [];
    for (var name in held) {
        var state = gamepad_held[name];
        if (!state) {
            gamepad_held[name] = {since: now, last: now};
            pressed.push(name);
        } else if (GAMEPAD_DIRECTIONS.indexOf(name) !== -1
            && now - state.since >= GAMEPAD_REPEAT_AFTER && now - state.last >= GAMEPAD_REPEAT_EVERY) {
            state.last = now;
            pressed.push(name);
        }
    }
    for (var gone in gamepad_held) {
        if (!held[gone]) delete gamepad_held[gone];
    }
    pressed.forEach(gamepad_press);
    return pressed;
}

// Does what the keyboard would for $name.
function gamepad_press(name) {
    var focused = typeof document !== 'undefined' ? document.activeElement : null;
    var tag = focused ? focused.tagName : '';
    if (name === 'Enter' && focused && (tag === 'BUTTON' || (tag === 'INPUT' && (focused.type === 'checkbox' || focused.type === 'radio')))) {
        focused.click();
        return;
    }
    if ((name === 'ArrowLeft' || name === 'ArrowRight') && tag === 'INPUT' && focused.type === 'range') {
        var step = Number(focused.step) || GAMEPAD_SLIDER_STEP;
        var value = Number(focused.value) + (name === 'ArrowRight' ? step : -step);
        focused.value = String(Math.max(Number(focused.min || 0), Math.min(Number(focused.max || 100), value)));
        if (focused.oninput) focused.oninput();
        if (focused.onchange) focused.onchange();
        return;
    }
    switch (name) {
        case 'Tab':
        case 'ShiftTab':
            if (typeof dialog_top === 'function' && dialog_top() !== null) dialog_tab(name === 'ShiftTab');
            return;
        case 'Commit':
            // In a fight: commit (combat_keys.js takes C for that).
            if (typeof combat_keys !== 'undefined' && dialog_base === combat_keys && dialog_top() === null) dialog_key('c');
            return;
        case 'Settings':
            gamepad_settings();
            return;
    }
    dialog_key(name);
}

// Back / Select: Settings, or closes it if it's open.
function gamepad_settings() {
    var top = typeof dialog_top === 'function' ? dialog_top() : null;
    if (top && typeof SettingsDialog !== 'undefined' && top instanceof SettingsDialog) {
        top.close();
    } else if (typeof hub !== 'undefined' && hub && hub.menu) {
        hub.menu.choose_id('settings');
    } else if (typeof open_settings === 'function' && typeof call_server === 'function') {
        open_settings({call: call_server});
    }
}

function gamepad_loop() {
    if (!gamepad_running) return;
    gamepad_poll(Date.now());
    requestAnimationFrame(gamepad_loop);
}

// Browsers report a controller once a button on it is pressed: start
// looking then, and stop when the last one goes.
if (typeof window !== 'undefined' && window.addEventListener) {
    window.addEventListener('gamepadconnected', function () {
        if (!gamepad_running) {
            gamepad_running = true;
            requestAnimationFrame(gamepad_loop);
        }
    });
    window.addEventListener('gamepaddisconnected', function () {
        var pads = navigator.getGamepads ? navigator.getGamepads() : [];
        for (var i = 0; i < pads.length; i++) if (pads[i] && pads[i].connected !== false) return;
        gamepad_running = false;
        gamepad_held = {};
    });
}
