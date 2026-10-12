// Remappable controls: which key and which controller button does each
// action. Everything that handles keys (dialogs.js, combat_keys.js) sees
// the game's own key names (ArrowUp, Enter, Escape, c...); this turns
// whatever the player pressed into those, using their mapping (saved
// with the account: player_settings.key_map / pad_map, only the changes).
//
//   controls_key(key)         a pressed key -> the key the game handles
//   controls_pad(button)      a controller button -> an action name
//   ControlsPanel             the Controls tab of Settings, to change them
//
// Keys not in the mapping pass through as they are: Space still confirms,
// letters still jump through menus, Tab still moves between controls.

var CONTROL_ACTIONS = [
    // [action, what it's called, the key the game handles, keyboard?, controller?]
    ['up', 'Up', 'ArrowUp', true, true],
    ['down', 'Down', 'ArrowDown', true, true],
    ['left', 'Left', 'ArrowLeft', true, true],
    ['right', 'Right', 'ArrowRight', true, true],
    ['confirm', 'Choose / OK', 'Enter', true, true],
    ['back', 'Back / cancel', 'Escape', true, true],
    ['commit', 'Commit commands (in a fight)', 'c', true, true],
    ['settings', 'Settings', null, false, true],
    ['prev_control', 'Previous control in a dialog', null, false, true],
    ['next_control', 'Next control in a dialog', null, false, true],
];

var CONTROL_KEY_DEFAULTS = {up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight',
    confirm: 'Enter', back: 'Escape', commit: 'c'};
// Standard controller layout: 0 A, 1 B, 2 X, 3 Y, 4 LB, 5 RB, 8 Back,
// 9 Start, 12-15 the D-pad.
var CONTROL_PAD_DEFAULTS = {up: 12, down: 13, left: 14, right: 15, confirm: 0, back: 1, commit: 3,
    settings: 8, prev_control: 4, next_control: 5};
var CONTROL_PAD_NAMES = {0: 'A', 1: 'B', 2: 'X', 3: 'Y', 4: 'LB', 5: 'RB', 6: 'LT', 7: 'RT', 8: 'Back', 9: 'Start',
    10: 'Left stick', 11: 'Right stick', 12: 'D-pad up', 13: 'D-pad down', 14: 'D-pad left', 15: 'D-pad right', 16: 'Home'};

function controls_setting(name) {
    var saved = typeof settings_get === 'function' ? settings_get(name) : null;
    return saved && typeof saved === 'object' ? saved : {};
}

// The key for each action: the player's, else the default.
function controls_keys() {
    var saved = controls_setting('key_map'), keys = {};
    for (var action in CONTROL_KEY_DEFAULTS) keys[action] = saved[action] || CONTROL_KEY_DEFAULTS[action];
    return keys;
}

function controls_buttons() {
    var saved = controls_setting('pad_map'), buttons = {};
    for (var action in CONTROL_PAD_DEFAULTS) {
        buttons[action] = typeof saved[action] === 'number' ? saved[action] : CONTROL_PAD_DEFAULTS[action];
    }
    return buttons;
}

function controls_game_key(action) {
    for (var i = 0; i < CONTROL_ACTIONS.length; i++) if (CONTROL_ACTIONS[i][0] === action) return CONTROL_ACTIONS[i][2];
    return null;
}

// A key the player pressed -> the key the game handles. A key mapped to
// an action becomes that action's key; a default key the player has moved
// to something else does nothing; anything else is left alone.
function controls_key(key) {
    var keys = controls_keys();
    var norm = key.length === 1 ? key.toLowerCase() : key;
    for (var action in keys) {
        var mine = keys[action].length === 1 ? keys[action].toLowerCase() : keys[action];
        if (mine === norm) return controls_game_key(action);
    }
    for (var moved in CONTROL_KEY_DEFAULTS) {
        var was = CONTROL_KEY_DEFAULTS[moved];
        if ((was.length === 1 ? was.toLowerCase() : was) === norm) return null; // freed: it does nothing now
    }
    return key;
}

// A controller button -> the action it does, or null.
function controls_pad(button) {
    var buttons = controls_buttons();
    for (var action in buttons) if (buttons[action] === button) return action;
    return null;
}

// What a key is called on screen.
function controls_key_name(key) {
    var names = {ArrowUp: '↑', ArrowDown: '↓', ArrowLeft: '←', ArrowRight: '→', ' ': 'Space', Escape: 'Esc'};
    return names[key] || (key.length === 1 ? key.toUpperCase() : key);
}

function controls_button_name(button) {
    return CONTROL_PAD_NAMES[button] || 'Button ' + button;
}

// Gives $action the key (or button) $value; whatever action had it gets
// the one $action gave up, so nothing is left doubled up. Returns the
// changes to save.
function controls_assign(which, action, value) {
    var current = which === 'key_map' ? controls_keys() : controls_buttons();
    var defaults = which === 'key_map' ? CONTROL_KEY_DEFAULTS : CONTROL_PAD_DEFAULTS;
    var old = current[action];
    for (var other in current) {
        if (other !== action && current[other] === value) current[other] = old;
    }
    current[action] = value;
    // Only what differs from the defaults is saved.
    var map = {};
    for (var a in current) if (current[a] !== defaults[a]) map[a] = current[a];
    return map;
}

// --- the Controls tab of Settings ---

// Builds the table into $container. options: call (the server call).
var ControlsPanel = class {
    constructor(container, options) {
        var self = this;
        this.options = options || {};
        this.waiting = null; // [which, action, button] while waiting for a key or button
        this.cells = {};
        var table = document.createElement('table');
        table.className = 'controlstable';
        var head = document.createElement('tr');
        ['Action', 'Key', 'Controller'].forEach(function (text) {
            var th = document.createElement('th');
            th.textContent = text;
            head.appendChild(th);
        });
        table.appendChild(head);
        CONTROL_ACTIONS.forEach(function (entry) {
            var row = document.createElement('tr');
            var name = document.createElement('td');
            name.textContent = entry[1];
            row.appendChild(name);
            row.appendChild(self._cell('key_map', entry, entry[3]));
            row.appendChild(self._cell('pad_map', entry, entry[4]));
            table.appendChild(row);
        });
        container.appendChild(table);
        this.note = document.createElement('div');
        this.note.className = 'settingsnote';
        this.note.setAttribute('aria-live', 'polite');
        this.note.textContent = 'Choose a key or button, then press the new one. Esc cancels.';
        container.appendChild(this.note);
        var reset = this._button('Reset to defaults', function () { self.reset(); });
        container.appendChild(reset);
        this.refresh();
    }

    _button(text, action) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'dialogbutton';
        button.textContent = text;
        button.onclick = action;
        return button;
    }

    _cell(which, entry, offered) {
        var self = this;
        var cell = document.createElement('td');
        if (!offered) return cell;
        var button = this._button('', function () { self.listen(which, entry[0], button); });
        button.dataset && (button.dataset.label = entry[1] + ': ' + (which === 'key_map' ? 'key' : 'controller button'));
        button.setAttribute('data-label', entry[1] + ': ' + (which === 'key_map' ? 'key' : 'controller button'));
        cell.appendChild(button);
        this.cells[which + ':' + entry[0]] = button;
        return cell;
    }

    refresh() {
        var keys = controls_keys(), buttons = controls_buttons();
        for (var id in this.cells) {
            var parts = id.split(':');
            var shown = parts[0] === 'key_map' ? controls_key_name(keys[parts[1]]) : controls_button_name(buttons[parts[1]]);
            this.cells[id].textContent = shown;
            this.cells[id].setAttribute('aria-label', this.cells[id].getAttribute('data-label') + ', ' + shown);
        }
    }

    // Waits for the next key (or controller button) for $action.
    listen(which, action, button) {
        this.stop_listening();
        this.waiting = [which, action, button];
        button.textContent = which === 'key_map' ? 'Press a key\u2026' : 'Press a button\u2026';
        this.note.textContent = which === 'key_map' ? 'Press the new key (Esc cancels).' : 'Press the new controller button.';
        if (which === 'pad_map' && typeof gamepad_capture === 'function') {
            var self = this;
            gamepad_capture(function (index) { self.take(index); });
        }
    }

    stop_listening() {
        if (this.waiting && this.waiting[0] === 'pad_map' && typeof gamepad_capture === 'function') gamepad_capture(null);
        this.waiting = null;
    }

    // The key or button pressed while listening.
    take(value) {
        if (!this.waiting) return false;
        var which = this.waiting[0], action = this.waiting[1];
        this.stop_listening();
        this.save(which, controls_assign(which, action, value));
        this.note.textContent = 'Saved.';
        return true;
    }

    save(which, map) {
        settings_all()[which] = map;
        this.refresh();
        var changes = {};
        changes[which] = map;
        if (this.options.call) {
            var self = this;
            Promise.resolve(this.options.call('save_settings', changes)).catch(function () {
                self.note.textContent = "Couldn't save that. It's in effect until you leave this page.";
            });
        }
    }

    reset() {
        this.stop_listening();
        this.save('key_map', {});
        this.save('pad_map', {});
        this.note.textContent = 'Back to the defaults.';
    }

    // A key pressed while listening (dialogs.js asks the dialog in front
    // before doing anything else with a key). True if taken.
    capture_key(key) {
        if (!this.waiting) return false;
        if (key === 'Escape') {
            this.stop_listening();
            this.refresh();
            this.note.textContent = 'Cancelled.';
            return true;
        }
        if (this.waiting[0] !== 'key_map') return true; // waiting for a controller button: keys wait too
        if (key === 'Tab' || key === 'Shift' || key === 'Control' || key === 'Alt' || key === 'Meta') return true;
        return this.take(key);
    }
};
