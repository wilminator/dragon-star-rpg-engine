// The settings popup: music and sound-effect volume, each with a mute,
// and what the fight screen shows (blood, attack and spell animations,
// the flying HP numbers). Saved with the account (save_settings,
// include/player_settings.php) as each control changes; the page gets
// them as player_settings.
//
//   settings_get(key)        a setting (the default if the page has none)
//   settings_hidden(key)     true if hide_gore / hide_actions /
//                            hide_hp_popups is on
//   settings_apply()         sets the audio volumes from the settings
//   SettingsDialog           the popup; open_settings(options) opens one
//
// Needs dialogs.js (dialog_frame, dialog_open, dialog_close).

var SETTINGS_DEFAULTS = {
    music_volume: 100, music_muted: false,
    sound_volume: 100, sound_muted: false,
    hide_gore: false, hide_actions: false, hide_hp_popups: false,
    time_multiplier: 1, text_log: false, key_map: {}, pad_map: {},
};

function settings_all() {
    if (typeof player_settings === 'undefined' || !player_settings) {
        player_settings = {};
    }
    for (var key in SETTINGS_DEFAULTS) {
        if (!(key in player_settings)) player_settings[key] = SETTINGS_DEFAULTS[key];
    }
    return player_settings;
}

function settings_get(key) {
    return settings_all()[key];
}

function settings_hidden(key) {
    return settings_get(key) === true;
}

// 0 to 1, as the audio library wants it.
function settings_volume(which) {
    return settings_get(which + '_muted') ? 0 : Math.max(0, Math.min(100, Number(settings_get(which + '_volume')) || 0)) / 100;
}

// Puts the volumes into effect, including on music already playing.
function settings_apply() {
    if (typeof set_music_volume === 'function') set_music_volume(settings_volume('music'));
    if (typeof set_sound_volume === 'function') set_sound_volume(settings_volume('sound'));
    if (typeof music_handle !== 'undefined' && music_handle && music_handle.volume) {
        music_handle.volume(settings_volume('music'));
    }
    if (typeof combat_log_show === 'function') combat_log_show(settings_get('text_log') === true);
}

// options:
//   parent   where the popup goes (default: the fight screen or body)
//   call     the server call (call_server(method, ...args) -> Promise)
var SettingsDialog = class {
    constructor(options) {
        this.options = options || {};
        this.frame = null;
        this.element = null;
        this.controls = {};
        this.saving = null;
    }

    open() {
        var self = this;
        var frame = dialog_frame({title: 'Settings', width: 540, height: 520, parent: this.options.parent});
        this.frame = frame;
        this.element = frame.element;
        frame.element.setAttribute('role', 'dialog');
        frame.element.setAttribute('aria-label', 'Settings');
        frame.element.classList && frame.element.classList.add('settingsdialog');
        // Tabs (the ARIA tabs pattern): Left and Right move between them.
        var tabs = document.createElement('div');
        tabs.className = 'settingstabs';
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', 'Settings');
        frame.body.appendChild(tabs);
        this.tabs = [];
        this.panels = [];
        var panel = function (id, title) {
            var tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'settingstab';
            tab.id = 'settingstab-' + id;
            tab.textContent = title;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', 'settingspanel-' + id);
            var index = self.tabs.length;
            tab.onclick = function () { self.show_tab(index); };
            tabs.appendChild(tab);
            var body = document.createElement('div');
            body.className = 'settingspanel';
            body.id = 'settingspanel-' + id;
            body.setAttribute('role', 'tabpanel');
            body.setAttribute('aria-labelledby', tab.id);
            frame.body.appendChild(body);
            self.tabs.push(tab);
            self.panels.push(body);
            return body;
        };

        var sound = panel('sound', 'Sound');
        this._volume(sound, 'music', 'Music');
        this._volume(sound, 'sound', 'Sound effects');

        var shown = this._group(panel('screen', 'Fight screen'), 'What the fight shows');
        this._checkbox(shown, 'hide_gore', 'Hide blood');
        this._checkbox(shown, 'hide_actions', 'Hide attack and spell animations');
        this._checkbox(shown, 'hide_hp_popups', 'Hide the HP numbers');
        this._checkbox(shown, 'text_log', 'Show the combat log beside the fight');

        var time = this._group(panel('time', 'Round time'), 'Time to give commands');
        this._radio(time, 'time_multiplier', 1, 'Normal');
        this._radio(time, 'time_multiplier', 1.5, '1.5\u00d7 (monsters +5% HP)');
        this._radio(time, 'time_multiplier', 2, '2\u00d7 (monsters +10% HP)');
        var note = document.createElement('div');
        note.className = 'settingsnote';
        note.textContent = 'From your next fight; tougher monsters are worth more PXP.';
        time.appendChild(note);

        if (typeof ControlsPanel !== 'undefined') {
            this.controls_panel = new ControlsPanel(panel('controls', 'Controls'), {call: this.options.call});
        }
        this.show_tab(SettingsDialog.last_tab < this.tabs.length ? SettingsDialog.last_tab : 0, false);

        var body = frame.body;
        var buttons = document.createElement('div');
        buttons.className = 'dialogbuttons';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'dialogbutton';
        close.textContent = 'Close';
        close.onclick = function () { self.close(); };
        buttons.appendChild(close);
        body.appendChild(buttons);
        this.status = document.createElement('div');
        this.status.className = 'settingsstatus';
        this.status.setAttribute('aria-live', 'polite');
        body.appendChild(this.status);
        frame.element.style.height = 'auto'; // as tall as its controls, in either screen's font
        frame.show();
        dialog_open(this);
        return this;
    }

    // Shows tab $index (and puts focus on it, unless $focus is false).
    show_tab(index, focus) {
        if (this.controls_panel) this.controls_panel.stop_listening();
        for (var i = 0; i < this.tabs.length; i++) {
            var on = i === index;
            this.tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
            this.tabs[i].setAttribute('tabindex', on ? '0' : '-1');
            this.panels[i].style.display = on ? '' : 'none';
        }
        this.tab = index;
        SettingsDialog.last_tab = index;
        if (focus !== false && this.tabs[index].focus) this.tabs[index].focus();
    }

    // Left and Right on a focused tab move to the next tab (dialogs.js
    // hands the dialog in front its keys).
    handle_key(key) {
        var focused = typeof document !== 'undefined' ? document.activeElement : null;
        var at = this.tabs ? this.tabs.indexOf(focused) : -1;
        if (at !== -1 && (key === 'ArrowLeft' || key === 'ArrowRight')) {
            var n = this.tabs.length;
            this.show_tab((at + (key === 'ArrowRight' ? 1 : n - 1)) % n);
            return true;
        }
        return false;
    }

    // While the Controls tab waits for a new key, it gets the key.
    capture_key(key) {
        return !!(this.controls_panel && this.controls_panel.capture_key(key));
    }

    cancel() {
        if (this.controls_panel && this.controls_panel.waiting) {
            this.controls_panel.capture_key('Escape');
            return;
        }
        this.close();
    }

    close() {
        if (this.controls_panel) this.controls_panel.stop_listening();
        dialog_close(this);
        if (this.frame) this.frame.remove();
        this.frame = null;
    }

    // A change from one of the controls: in effect at once, then saved.
    change(key, value) {
        var settings = settings_all();
        settings[key] = value;
        settings_apply();
        var changes = {};
        changes[key] = value;
        var self = this;
        if (!this.options.call) return Promise.resolve(settings);
        this.saving = Promise.resolve(this.options.call('save_settings', changes)).then(function (answer) {
            var saved = answer && answer[0];
            if (saved && typeof saved === 'object') {
                for (var k in saved) settings[k] = saved[k];
                settings_apply();
            }
            self._say('');
            return settings;
        }, function () {
            self._say("Couldn't save that. It's in effect until you leave this page.");
            return settings;
        });
        return this.saving;
    }

    _say(text) {
        if (this.status) this.status.textContent = text;
    }

    _group(body, legend) {
        var fieldset = document.createElement('fieldset');
        fieldset.className = 'settingsgroup';
        var title = document.createElement('legend');
        title.textContent = legend;
        fieldset.appendChild(title);
        body.appendChild(fieldset);
        return fieldset;
    }

    _volume(body, which, legend) {
        var self = this;
        var group = this._group(body, legend);
        var label = document.createElement('label');
        label.appendChild(settings_text('Volume '));
        var slider = document.createElement('input');
        slider.type = 'range';
        slider.min = '0';
        slider.max = '100';
        slider.step = '5';
        slider.value = String(settings_get(which + '_volume'));
        slider.setAttribute('aria-label', legend + ' volume');
        // Heard at once while it moves; saved when it's let go.
        slider.oninput = function () { settings_all()[which + '_volume'] = Number(slider.value); settings_apply(); };
        slider.onchange = function () { self.change(which + '_volume', Number(slider.value)); };
        label.appendChild(slider);
        group.appendChild(label);
        this.controls[which + '_volume'] = slider;
        this._checkbox(group, which + '_muted', 'Mute');
    }

    _radio(parent, key, value, text) {
        var self = this;
        var label = document.createElement('label');
        label.className = 'settingscheck';
        var radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'settings_' + key;
        radio.value = String(value);
        radio.checked = Number(settings_get(key)) === value;
        radio.onchange = function () { if (radio.checked) self.change(key, value); };
        label.appendChild(radio);
        label.appendChild(settings_text(' ' + text));
        parent.appendChild(label);
        this.controls[key + '_' + value] = radio;
    }

    _checkbox(parent, key, text) {
        var self = this;
        var label = document.createElement('label');
        label.className = 'settingscheck';
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = settings_get(key) === true;
        box.onchange = function () { self.change(key, box.checked === true); };
        label.appendChild(box);
        label.appendChild(settings_text(' ' + text));
        parent.appendChild(label);
        this.controls[key] = box;
    }
};

function settings_text(text) {
    var span = document.createElement('span');
    span.textContent = text;
    return span;
}

function open_settings(options) {
    return new SettingsDialog(options).open();
}
SettingsDialog.last_tab = 0;
