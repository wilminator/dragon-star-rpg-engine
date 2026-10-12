// The menu dialog: the hub's always-visible menu. Not a pop-up -- it sits
// beside the party and is the dialog stack's base, so it has the keyboard
// whenever no dialog is open over it.
//
//   MenuDialog     a column of buttons, some greyed out
//   hub_menu_items(actions)   the hub's options, in order, from a map of
//                  id => action (or {action, enabled, hint}); an option
//                  with no action is shown greyed out
//
// Keys: Up and Down (wrapping), Home and End, a letter jumps to the next
// option starting with it, Enter or Space chooses.
//
// Needs dialogs.js and status_dialog.js (dialog_element, dialog_button).

// Every option the hub offers. (Fighting as the monsters is on team
// selection, pick_team.php: it's the player's, not the party's.)
var HUB_MENU = [
    ['fight', 'Fight'],
    ['public_fight', 'Public fight'],
    ['inn', 'Inn'],
    ['shop', 'Shop'],
    ['items', 'Items'],
    ['spells', 'Spells'],
    ['abilities', 'Abilities'],
    ['equip', 'Equip'],
    ['status', 'Status'],
    ['formation', 'Formation'],
    ['alliance', 'Alliance'],
    ['manage_team', 'Manage team'],
    ['fight_history', 'Fight history'],
    ['settings', 'Settings'],
    ['account', 'Account'],
    ['team_select', 'Back to team selection'],
];

function hub_menu_items(actions) {
    actions = actions || {};
    return HUB_MENU.map(function (entry) {
        var given = actions[entry[0]];
        var spec = typeof given === 'function' ? {action: given} : (given || {});
        return {
            id: entry[0],
            label: spec.label || entry[1],
            action: spec.action || null,
            enabled: spec.action ? (spec.enabled === undefined ? true : spec.enabled) : false,
            hint: spec.hint || '',
        };
    });
}

// options:
//   container    the element the menu is drawn into
//   items        [{id, label, action(item), enabled (bool or function),
//                hint}]
//   label        what the menu is called, for screen readers ('Menu')
var MenuDialog = class {
    constructor(options) {
        this.options = options || {};
        this.items = this.options.items || [];
        this.highlighted = null;
        this.buttons = [];
        this.element = null;
        if (this.options.container) this.draw(this.options.container);
    }

    enabled(index) {
        var item = this.items[index];
        if (!item || !item.action) return false;
        return typeof item.enabled === 'function' ? !!item.enabled(item) : item.enabled !== false;
    }

    draw(container) {
        var self = this;
        dialog_clear(container);
        var menu = dialog_element('div', 'menudialog');
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', this.options.label || 'Menu');
        this.buttons = this.items.map(function (item, index) {
            var button = dialog_button(menu, item.label, function () { self.choose(index); });
            button.className = 'menudialogitem';
            button.setAttribute('role', 'menuitem');
            if (item.hint) button.title = item.hint;
            button.onmouseover = function () { self.highlight(index, false); };
            return button;
        });
        container.appendChild(menu);
        this.element = menu;
        this.refresh();
        return menu;
    }

    // Re-reads which options can be chosen (after gold, alliance or team
    // changes).
    refresh() {
        var self = this;
        this.buttons.forEach(function (button, index) {
            var on = self.enabled(index);
            button.setAttribute('aria-disabled', on ? 'false' : 'true');
            button.className = 'menudialogitem' + (on ? '' : ' menudialogitem-disabled')
                + (index === self.highlighted ? ' menudialogitem-highlighted' : '');
        });
    }

    // $focus: move the keyboard focus there too (keys do; the mouse doesn't).
    highlight(index, focus) {
        if (index < 0 || index >= this.items.length) return;
        this.highlighted = index;
        this.refresh();
        if (focus !== false && this.buttons[index] && this.buttons[index].focus) this.buttons[index].focus();
    }

    choose(index) {
        if (!this.enabled(index)) {
            return false;
        }
        this.highlighted = index;
        this.refresh();
        var item = this.items[index];
        item.action(item);
        return true;
    }

    choose_id(id) {
        for (var i = 0; i < this.items.length; i++) {
            if (this.items[i].id === id) return this.choose(i);
        }
        return false;
    }

    _move(from, by) {
        var n = this.items.length;
        if (n === 0) return;
        var index = from === null ? (by > 0 ? 0 : n - 1) : (from + by + n) % n;
        this.highlight(index);
    }

    handle_key(key) {
        switch (key) {
            case 'ArrowDown': this._move(this.highlighted, 1); return true;
            case 'ArrowUp': this._move(this.highlighted, -1); return true;
            case 'Home': this.highlight(0); return true;
            case 'End': this.highlight(this.items.length - 1); return true;
            case 'Enter':
            case ' ':
                if (this.highlighted !== null) this.choose(this.highlighted);
                return true;
        }
        if (key.length === 1 && /\S/.test(key)) {
            // The next option starting with that letter.
            var letter = key.toLowerCase();
            var n = this.items.length;
            var start = this.highlighted === null ? 0 : this.highlighted + 1;
            for (var i = 0; i < n; i++) {
                var index = (start + i) % n;
                if (this.items[index].label.toLowerCase().charAt(0) === letter) {
                    this.highlight(index);
                    return true;
                }
            }
        }
        return false;
    }
};
