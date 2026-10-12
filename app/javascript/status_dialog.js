// The status and equipment-benefits dialogs, and the equip flow out of
// combat that joins the equip dialog to the benefits one. The numbers
// come from the server (php_js/character_sheet.php and
// preview_equipment.php, both CharacterSheet) so the stat formulas live
// in one place.
//
//   StatusDialog     a hero's job, level, experience, stats and equipment
//   BenefitsDialog   what a pick in the equip dialog would do to each
//                    stat, and what would come off; confirm or go back
//   equip_out_of_combat(options)   equip dialog -> benefits -> on_commit
//
// Needs dialogs.js, equip_dialog.js and rpc.js (call_server). Every
// option named `call` stands in for call_server, and `frame` for
// dialog_frame, so tests can run these without a server or a page.

// The rows the benefits dialog shows: [{stat, before, after, change}],
// HP and MP by their most, every stat in the server's order.
function benefit_rows(preview) {
    var rows = [];
    Object.keys(preview.before).forEach(function (stat) {
        var most = function (value) { return Array.isArray(value) ? value[1] : value; };
        var before = most(preview.before[stat]);
        var after = most(preview.after[stat]);
        rows.push({stat: stat, before: before, after: after, change: after - before});
    });
    return rows;
}

function dialog_element(tag, class_name, text) {
    var element = document.createElement(tag);
    if (class_name) element.className = class_name;
    if (text !== undefined && text !== null) element.textContent = String(text);
    return element;
}

function dialog_button(parent, label, action) {
    var button = dialog_element('button', 'dialogbutton', label);
    button.type = 'button';
    button.onclick = action;
    parent.appendChild(button);
    return button;
}

function dialog_clear(element) {
    while (element.firstChild) element.removeChild(element.firstChild);
}

// A dialog that fetches its content when opened and draws it when the
// answer comes; an answer that arrives after it closed is dropped.
var FetchedDialog = class {
    constructor(options) {
        this.options = options || {};
        this.call = this.options.call || (typeof call_server === 'function' ? call_server : null);
        this.frame = null;
        this.closed = true;
    }

    open(title, method, args) {
        var make_frame = this.options.frame || dialog_frame;
        this.frame = make_frame({title: title, parent: this.options.parent, width: this.options.width, height: this.options.height});
        this.element = this.frame.element;
        this.frame.element.setAttribute('role', 'dialog');
        this.frame.element.setAttribute('aria-label', title);
        this.frame.body.appendChild(dialog_element('div', 'dialogdesc', 'Loading...'));
        this.frame.show();
        this.closed = false;
        dialog_open(this);
        var self = this;
        return Promise.resolve(this.call.apply(null, [method].concat(args)))
            .then(function (answer) { if (!self.closed) self.draw.apply(self, answer); })
            .catch(function (error) {
                if (self.closed) return;
                dialog_clear(self.frame.body);
                self.frame.body.appendChild(dialog_element('div', 'dialogdesc', "Couldn't load this. Try again in a moment."));
                self._buttons([['Close', function () { self.cancel(); }]]);
                if (typeof console !== 'undefined') console.error(error);
            });
    }

    close() {
        if (this.closed) return;
        this.closed = true;
        dialog_close(this);
        if (this.frame) this.frame.remove();
    }

    cancel() {
        this.close();
        if (this.options.on_cancel) this.options.on_cancel();
    }

    _buttons(buttons) {
        var row = dialog_element('div', 'dialogbuttons');
        var made = buttons.map(function (b) { return dialog_button(row, b[0], b[1]); });
        this.frame.body.appendChild(row);
        if (made.length > 0 && made[0].focus) made[0].focus(); // keyboard users start on the first
        return made;
    }
};

// options: charid, parent, on_cancel (closed), call, frame
var StatusDialog = class extends FetchedDialog {
    open() {
        return super.open('Status', 'character_sheet', [this.options.charid]);
    }

    draw(sheet) {
        var body = this.frame.body;
        var self = this;
        dialog_clear(body);
        if (!sheet) {
            body.appendChild(dialog_element('div', 'dialogdesc', "That hero isn't on one of your teams."));
            this._buttons([['Close', function () { self.cancel(); }]]);
            return;
        }
        this.sheet = sheet;
        body.appendChild(dialog_element('div', 'statusname', sheet.name));
        body.appendChild(dialog_element('div', 'statusline', 'Level ' + sheet.level + ' ' + sheet.job));
        body.appendChild(dialog_element('div', 'statusline', 'Experience ' + sheet.exp + ' / ' + sheet.need));
        var columns = dialog_element('div', 'statuscolumns');
        var stats = dialog_element('table', 'statustable');
        stats.setAttribute('aria-label', 'Stats');
        Object.keys(sheet.stats).forEach(function (stat) {
            var value = sheet.stats[stat];
            var row = dialog_element('tr');
            row.appendChild(dialog_element('th', null, stat));
            var cell = dialog_element('td');
            if (Array.isArray(value)) {
                // now | / most, kept on one line (see div.statussplit)
                var split = dialog_element('div', 'statussplit');
                split.appendChild(dialog_element('div', 'statusnow', value[0]));
                split.appendChild(dialog_element('div', 'statusmost', '/ ' + value[1]));
                cell.appendChild(split);
            } else {
                cell.textContent = String(value);
            }
            row.appendChild(cell);
            stats.appendChild(row);
        });
        columns.appendChild(stats);
        var worn = dialog_element('table', 'statustable');
        worn.setAttribute('aria-label', 'Equipment');
        Object.keys(sheet.equipment).forEach(function (slot) {
            var row = dialog_element('tr');
            row.appendChild(dialog_element('th', null, EQUIP_SLOT_NAMES[slot] || slot));
            row.appendChild(dialog_element('td', null, sheet.equipment[slot] || '-'));
            worn.appendChild(row);
        });
        columns.appendChild(worn);
        body.appendChild(columns);
        this._buttons([['Close', function () { self.cancel(); }]]);
    }

    handle_key(key) {
        if (key === 'Enter') {
            this.cancel();
            return true;
        }
        return false;
    }
};

// options: charid, slot, model (the EquipModel the pick came from, to
// name things), on_confirm(slot, model), on_cancel, parent, call, frame
var BenefitsDialog = class extends FetchedDialog {
    open() {
        var model = this.options.model;
        var item = model.item();
        var worn = model.worn_item(this.options.slot);
        var title = model.equipping()
            ? 'Equip ' + (item ? item.name : '') + ' (' + EQUIP_SLOT_NAMES[this.options.slot] + ')'
            : 'Take off ' + (worn ? worn.name : '');
        return super.open(title, 'preview_equipment', [this.options.charid, model.using, this.options.slot]);
    }

    // The server answers (charid, index, slot, preview or null).
    draw(charid, index, slot, preview) {
        var body = this.frame.body;
        var self = this;
        var model = this.options.model;
        dialog_clear(body);
        this.preview = preview;
        if (!preview) {
            body.appendChild(dialog_element('div', 'dialogdesc', "That can't go there."));
            this._buttons([['Back', function () { self.cancel(); }]]);
            return;
        }
        var table = dialog_element('table', 'benefitstable');
        table.setAttribute('aria-label', 'Stat changes');
        var head = dialog_element('tr');
        ['Stat', 'Now', 'After', ''].forEach(function (text) { head.appendChild(dialog_element('th', null, text)); });
        table.appendChild(head);
        benefit_rows(preview).forEach(function (row) {
            var tr = dialog_element('tr', row.change > 0 ? 'benefit-up' : (row.change < 0 ? 'benefit-down' : null));
            tr.appendChild(dialog_element('th', null, row.stat));
            tr.appendChild(dialog_element('td', null, row.before));
            tr.appendChild(dialog_element('td', null, row.after));
            tr.appendChild(dialog_element('td', null, row.change === 0 ? '' : (row.change > 0 ? '+' : '') + row.change));
            table.appendChild(tr);
        });
        body.appendChild(table);
        var off = [];
        preview.empties.forEach(function (s) {
            var worn = model.worn_item(s);
            var name = worn ? worn.name : EQUIP_SLOT_NAMES[s];
            if (off.indexOf(name) === -1) off.push(name);
        });
        if (model.equipping() && off.length > 0) {
            body.appendChild(dialog_element('div', 'dialogdesc', 'Comes off: ' + off.join(', ')));
        }
        this._buttons([
            [model.equipping() ? 'Equip' : 'Take off', function () { self.confirm(); }],
            ['Back', function () { self.cancel(); }],
        ]);
    }

    confirm() {
        if (!this.preview) return false;
        this.close();
        if (this.options.on_confirm) this.options.on_confirm(this.options.slot, this.options.model);
        return true;
    }

    handle_key(key) {
        if (key === 'Enter' && this.preview) {
            this.confirm();
            return true;
        }
        return false;
    }
};

// The equip dialog out of combat: pick a slot, see what it does, confirm.
// options:
//   dialog       an EquipDialog (its view drawing the equip window)
//   model        an EquipModel for the hero and item
//   charid
//   on_commit(slot, model)   confirmed; the caller makes the change
//   on_cancel()              the equip dialog was cancelled
//   show(), hide()           show and hide the equip window
//   call, frame, parent      passed to the benefits dialog
function equip_out_of_combat(options) {
    var dialog = options.dialog;
    var close = function () {
        dialog_close(dialog);
        if (options.hide) options.hide();
    };
    dialog.on_choose = function (slot, model) {
        new BenefitsDialog({
            charid: options.charid, slot: slot, model: model, call: options.call, frame: options.frame, parent: options.parent,
            on_confirm: function (s, m) {
                close();
                if (options.on_commit) options.on_commit(s, m);
            },
            // Back: the equip dialog is on top again
        }).open();
    };
    dialog.on_cancel = function () {
        close();
        if (options.on_cancel) options.on_cancel();
    };
    dialog.set_model(options.model);
    if (options.show) options.show();
    dialog_open(dialog);
    return dialog;
}
