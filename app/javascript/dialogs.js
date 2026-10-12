// Dialogs: the pieces every screen of the game is built from.
//
//   dialog_open(dialog) / dialog_close(dialog) -- the dialog stack. The
//     top dialog gets the keyboard; Escape cancels it. A dialog is any
//     object with, optionally, handle_key(key) -> true if used, cancel(),
//     on_open(), on_close(), and element (its DOM element: a button
//     inside it that has focus gets Enter and Space itself, and focus
//     goes back where it was when the dialog closes).
//   dialog_set_base(dialog) -- what gets the keyboard when no dialog is
//     open: the hub's always-visible menu.
//   SelectionList -- a paged list of rows, each of one or more columns
//     (name, quantity, price...), some greyed out, with a callback when a
//     row is chosen. It draws through a "view" (below), so the same logic
//     serves combat's spell and item lists and every list the hub needs.
//   dialog_frame(options) -- a window in the game's dialog style, with
//     SelectionListDomView to draw a multi-column list into it.
//
// Declared as `var X = class` rather than `class X`: top-level classes
// don't become properties of window, and the game (and its tests) look
// things up there.

// --- the stack ---

var dialog_stack = [];
var dialog_base = null;

function dialog_top() {
    return dialog_stack.length > 0 ? dialog_stack[dialog_stack.length - 1] : null;
}

// The dialog that gets keys: the top of the stack, else the base.
function dialog_active() {
    return dialog_top() || dialog_base;
}

function dialog_set_base(dialog) {
    dialog_base = dialog;
}

function dialog_focused() {
    return typeof document !== 'undefined' && document.activeElement ? document.activeElement : null;
}

function dialog_is_open(dialog) {
    return dialog_stack.indexOf(dialog) !== -1;
}

// Puts $dialog on top (moving it there if it's already open).
function dialog_open(dialog) {
    var at = dialog_stack.indexOf(dialog);
    if (at !== -1) {
        dialog_stack.splice(at, 1);
    }
    dialog_stack.push(dialog);
    if (at === -1) {
        dialog._return_focus = dialog_focused();
        if (dialog.on_open) {
            dialog.on_open();
        }
    }
    dialog_take_focus(dialog);
    return dialog;
}

// --- focus (WCAG 2.4.3) ---
//
// A dialog in front has the focus: when it opens (or comes back to the
// top), focus moves into it -- onto its list, if it has one (a listbox,
// which screen readers follow as the highlight moves), else onto the
// dialog itself. Not onto its first button: a focused button takes Enter
// for itself, and Enter should choose the highlighted row. Tab and
// Shift+Tab then go round the dialog's own controls only.

function dialog_is_shown(element) {
    for (var e = element; e && e.style; e = e.parentNode) {
        if (e.style.display === 'none' || e.style.visibility === 'hidden') return false;
    }
    return true;
}

function dialog_walk(element, visit) {
    var children = element.children || [];
    for (var i = 0; i < children.length; i++) {
        var child = children[i];
        if (child.style && (child.style.display === 'none' || child.style.visibility === 'hidden')) continue;
        visit(child);
        dialog_walk(child, visit);
    }
}

// The controls Tab stops at inside $element, in order.
function dialog_focusables(element) {
    var found = [];
    dialog_walk(element, function (e) {
        var tag = e.tagName;
        var tabindex = e.getAttribute ? e.getAttribute('tabindex') : null;
        if (tabindex !== null && Number(tabindex) < 0) return;
        if ((tag === 'BUTTON' || tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') && !e.disabled) found.push(e);
        else if (tag === 'A' && e.getAttribute('href') !== null) found.push(e);
        else if (tabindex !== null) found.push(e);
    });
    return found;
}

function dialog_take_focus(dialog) {
    var element = dialog && dialog.element;
    if (!element || !element.setAttribute || !element.getAttribute || dialog === dialog_base) return;
    if (element.getAttribute('role') === 'dialog') element.setAttribute('aria-modal', 'true');
    var now = dialog_focused();
    if (now && element.contains(now) && now !== element) return; // already inside (it chose where)
    var target = null;
    dialog_walk(element, function (e) {
        if (!target && e.getAttribute && e.getAttribute('role') === 'listbox') target = e;
    });
    if (target && !target.getAttribute('aria-label') && !target.getAttribute('aria-labelledby')) {
        target.setAttribute('aria-label', element.getAttribute('aria-label') || 'Choices'); // the dialog's name
    }
    if (!target) {
        if (element.getAttribute('tabindex') === null) element.setAttribute('tabindex', '-1');
        target = element;
    }
    if (target.focus) target.focus();
}

// Tab inside the dialog in front: round its own controls. True if used.
function dialog_tab(backwards) {
    var top = dialog_top();
    var element = top && top.element;
    if (!element || !element.contains) return false;
    var stops = dialog_focusables(element);
    if (stops.length === 0) {
        dialog_take_focus(top);
        return true;
    }
    var at = stops.indexOf(dialog_focused());
    var next = at === -1 ? (backwards ? stops.length - 1 : 0) : (at + (backwards ? stops.length - 1 : 1)) % stops.length;
    stops[next].focus();
    return true;
}

// Takes $dialog off the stack, wherever it is. The one below becomes the
// top again.
function dialog_close(dialog) {
    var at = dialog_stack.indexOf(dialog);
    if (at === -1) {
        return false;
    }
    dialog_stack.splice(at, 1);
    if (dialog.on_close) {
        dialog.on_close();
    }
    var back = dialog._return_focus;
    dialog._return_focus = null;
    if (back && back.focus && back.isConnected !== false && at === dialog_stack.length) {
        back.focus(); // it was on top: focus goes back to what opened it
    }
    var top = dialog_top();
    if (top && at === dialog_stack.length && !(top.element && top.element.contains && top.element.contains(dialog_focused()))) {
        dialog_take_focus(top); // what opened it is gone: into the dialog now in front
    }
    return true;
}

// A key for the top dialog (key names as KeyboardEvent.key gives them).
// Escape cancels it if it doesn't handle Escape itself. True if used.
function dialog_key(key) {
    var top = dialog_active();
    if (top === null) {
        return false;
    }
    if (top.handle_key && top.handle_key(key)) {
        return true;
    }
    if (key === 'Escape' && top.cancel) {
        top.cancel();
        return true;
    }
    return false;
}

if (typeof document !== 'undefined' && document.addEventListener) {
    document.addEventListener('keydown', function (event) {
        var target = event.target || {};
        // A dialog waiting for a key (Settings > Controls, remapping) gets it first.
        var waiting = dialog_top();
        if (waiting && waiting.capture_key && waiting.capture_key(event.key)) {
            event.preventDefault();
            return;
        }
        if (event.key === 'Tab' && dialog_top() !== null) {
            // Keep Tab inside the dialog in front (form fields included).
            if (dialog_tab(event.shiftKey)) event.preventDefault();
            return;
        }
        if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT') {
            // Typing in a form field -- but Escape in one inside the
            // dialog in front still closes that dialog.
            var front = dialog_top();
            if (event.key === 'Escape' && front && front.element && front.element.contains && front.element.contains(target)
                && dialog_key('Escape')) {
                event.preventDefault();
            }
            return;
        }
        // The player's own mapping (controls.js): their key for an action
        // becomes the key the game handles; a default key they've moved
        // elsewhere does nothing.
        var key = typeof controls_key === 'function' ? controls_key(event.key) : event.key;
        if (key === null) return;
        var active = dialog_active();
        if (target.tagName === 'BUTTON' && (key === 'Enter' || key === ' ')
            && active && active.element && active.element.contains(target)) {
            if (key !== event.key) {
                target.click(); // their confirm key, on a focused button
                event.preventDefault();
            }
            return; // the focused button of the dialog in front presses itself
        }
        if (dialog_key(key)) {
            event.preventDefault();
        }
    });
}

// --- the selection list ---

// options:
//   page_size   rows shown at once (8)
//   view        draws it: show_row(slot, row, columns), hide_row(slot),
//               highlight_row(slot, row) / unhighlight_row(slot),
//               set_description(text), set_paging(has_previous, has_next).
//               Any of these may be missing.
//   on_choose(row, index)   a selectable row was chosen
//   on_cancel()             cancelled (Escape, or a Cancel button)
//   on_change(list)         after every redraw (paging included, however
//                           it was asked for)
//   sounds      {hover, select, cancel, refuse}: play_sound() clip names,
//               or null for silence
//
// A row: {value, pic, cells: [text, ...], colors: [color or null, ...],
// desc, selectable (default true)}.
var SelectionList = class {
    constructor(options) {
        options = options || {};
        this.page_size = options.page_size || 8;
        this.view = options.view || {};
        this.on_choose = options.on_choose || null;
        this.on_cancel = options.on_cancel || null;
        this.on_change = options.on_change || null;
        this.sounds = options.sounds || null;
        this.rows = [];
        this.position = 0;      // index of the first row on the page
        this.highlighted = null; // slot on the page, or null
    }

    set_rows(rows) {
        this.rows = rows.map(function (row) {
            return Object.assign({cells: [], colors: [], desc: '', selectable: true}, row);
        });
        this.position = 0;
        this.highlighted = null;
        this.render();
    }

    add_row(row) {
        this.rows.push(Object.assign({cells: [], colors: [], desc: '', selectable: true}, row));
    }

    clear() {
        this.rows = [];
        this.position = 0;
        this.highlighted = null;
    }

    page_rows() {
        return this.rows.slice(this.position, this.position + this.page_size);
    }

    has_previous() {
        return this.position > 0;
    }

    has_next() {
        return this.position + this.page_size < this.rows.length;
    }

    render() {
        for (var slot = 0; slot < this.page_size; slot++) {
            var row = this.rows[this.position + slot];
            if (row === undefined) {
                this._view('hide_row', slot);
            } else {
                this._view('show_row', slot, row);
            }
        }
        this._view('set_description', '');
        this._view('set_paging', this.has_previous(), this.has_next());
        if (this.highlighted !== null && this.rows[this.position + this.highlighted] !== undefined) {
            this.highlight(this.highlighted);
        } else {
            this.highlighted = null;
        }
        if (this.on_change) {
            this.on_change(this);
        }
    }

    next_page() {
        if (this.has_next()) {
            this.position += this.page_size;
            this.highlighted = this.highlighted === null ? null : 0;
            this._sound('hover');
        }
        this.render();
    }

    previous_page() {
        if (this.has_previous()) {
            this.position = Math.max(0, this.position - this.page_size);
            this.highlighted = this.highlighted === null ? null : 0;
            this._sound('hover');
        }
        this.render();
    }

    highlight(slot) {
        var row = this.rows[this.position + slot];
        if (row === undefined) {
            return;
        }
        if (this.highlighted !== null && this.highlighted !== slot) {
            this._view('unhighlight_row', this.highlighted);
        }
        this.highlighted = slot;
        this._view('highlight_row', slot, row);
        this._view('set_description', row.desc || '');
    }

    unhighlight(slot) {
        this._view('unhighlight_row', slot);
        this._view('set_description', '');
        if (this.highlighted === slot) {
            this.highlighted = null;
        }
    }

    // Chooses the row in page slot $slot. False (and a refusal sound) if
    // it's greyed out or missing.
    choose(slot) {
        var index = this.position + slot;
        var row = this.rows[index];
        if (row === undefined || row.selectable === false) {
            this._sound('refuse');
            return false;
        }
        this._sound('select');
        this.unhighlight(slot);
        this.chosen = index; // the row last chosen, for a redraw to return to
        if (this.on_choose) {
            this.on_choose(row, index);
        }
        return true;
    }

    cancel() {
        this._sound('cancel');
        if (this.on_cancel) {
            this.on_cancel();
        }
    }

    // Arrow keys move the highlight (paging at the edges), PageUp and
    // PageDown page, Enter or Space chooses. True if the key was used.
    handle_key(key) {
        var on_page = this.page_rows().length;
        switch (key) {
            case 'ArrowDown':
                if (on_page === 0) return true;
                if (this.highlighted === null) {
                    this.highlight(0);
                } else if (this.highlighted + 1 < on_page) {
                    this.highlight(this.highlighted + 1);
                } else if (this.has_next()) {
                    this.next_page();
                    this.highlight(0);
                }
                return true;
            case 'ArrowUp':
                if (on_page === 0) return true;
                if (this.highlighted === null) {
                    this.highlight(on_page - 1);
                } else if (this.highlighted > 0) {
                    this.highlight(this.highlighted - 1);
                } else if (this.has_previous()) {
                    this.previous_page();
                    this.highlight(this.page_rows().length - 1);
                }
                return true;
            case 'PageDown':
                this.next_page();
                return true;
            case 'PageUp':
                this.previous_page();
                return true;
            case 'Enter':
            case ' ':
                if (this.highlighted !== null) {
                    this.choose(this.highlighted);
                }
                return true;
        }
        return false;
    }

    _view(method) {
        if (this.view[method]) {
            this.view[method].apply(this.view, Array.prototype.slice.call(arguments, 1));
        }
    }

    _sound(name) {
        if (this.sounds && this.sounds[name] && typeof play_sound === 'function' && typeof audio_clips !== 'undefined') {
            play_sound(audio_clips[this.sounds[name]]);
        }
    }
};

// --- frames and a multi-column list view ---

// The play area every screen draws on: div.screen, 800x580 (css/fight.css).
var DIALOG_STAGE = {width: 800, height: 580};

// A window in the game's dialog style, added to $options.parent (default:
// the fight screen or the page body), hidden until shown. $options: id,
// title, width, height (px; default the fight dialog's 600x430), left,
// top (px, default centred on the stage). Returns {element, body, show(),
// hide(), remove()}.
function dialog_frame(options) {
    options = options || {};
    var width = options.width || 600;
    var height = options.height || 430;
    var frame = document.createElement('div');
    frame.className = 'dialogframe';
    if (options.id) {
        frame.id = options.id;
    }
    frame.style.width = width + 'px';
    frame.style.height = height + 'px';
    frame.style.left = (options.left !== undefined ? options.left : Math.round((DIALOG_STAGE.width - width) / 2)) + 'px';
    frame.style.top = (options.top !== undefined ? options.top : Math.round((DIALOG_STAGE.height - height) / 2)) + 'px';
    frame.style.display = 'none';
    if (options.title) {
        var title = document.createElement('div');
        title.className = 'dialogtitle';
        title.textContent = options.title;
        frame.appendChild(title);
    }
    var body = document.createElement('div');
    body.className = 'dialogbody';
    frame.appendChild(body);
    var parent = options.parent || (typeof fight_screen !== 'undefined' && fight_screen) || document.body;
    parent.appendChild(frame);
    return {
        element: frame,
        body: body,
        show: function () { frame.style.display = 'block'; },
        hide: function () { frame.style.display = 'none'; },
        remove: function () { if (frame.parentNode) frame.parentNode.removeChild(frame); },
    };
}

// Draws a SelectionList into $container as rows of columns, with a
// description line and previous / next / cancel buttons. $columns:
// [{width (px), align ('left' | 'right')}, ...], the first after the
// picture. Bind it with view.bind(list) so its buttons and rows call the
// list.
var SelectionListDomView = class {
    constructor(container, columns, page_size) {
        this.container = container;
        this.columns = columns || [{align: 'left'}];
        this.page_size = page_size || 8;
        this.list = null;
        this.rows = [];
        var self = this;
        var table = document.createElement('div');
        table.className = 'dialoglist';
        // A listbox: dialog_take_focus() puts focus here, and screen
        // readers read the highlighted row (aria-activedescendant) as the
        // arrow keys move it.
        var list_id = 'dialoglist' + (++SelectionListDomView.count);
        table.id = list_id;
        table.setAttribute('role', 'listbox');
        table.setAttribute('tabindex', '0');
        this.table = table;
        for (var slot = 0; slot < this.page_size; slot++) {
            var row = document.createElement('div');
            row.className = 'dialogrow';
            row.style.display = 'none';
            row.id = list_id + '-' + slot;
            row.setAttribute('role', 'option');
            var pic = document.createElement('img');
            pic.className = 'dialogpic';
            pic.setAttribute('alt', ''); // the row's own text names it
            row.appendChild(pic);
            var cells = [];
            for (var c = 0; c < this.columns.length; c++) {
                var cell = document.createElement('div');
                cell.className = 'dialogcell';
                if (this.columns[c].width) cell.style.width = this.columns[c].width + 'px';
                cell.style.textAlign = this.columns[c].align || 'left';
                row.appendChild(cell);
                cells.push(cell);
            }
            (function (s) {
                row.onmouseover = function () { if (self.list) self.list.highlight(s); };
                row.onmouseout = function () { if (self.list) self.list.unhighlight(s); };
                row.onclick = function () { if (self.list) self.list.choose(s); };
            })(slot);
            table.appendChild(row);
            this.rows.push({element: row, pic: pic, cells: cells});
        }
        this.description = document.createElement('div');
        this.description.className = 'dialogdesc';
        var buttons = document.createElement('div');
        buttons.className = 'dialogbuttons';
        this.previous = this._button(buttons, 'Previous', function () { if (self.list) self.list.previous_page(); });
        this.next = this._button(buttons, 'Next', function () { if (self.list) self.list.next_page(); });
        this._button(buttons, 'Cancel', function () { if (self.list) self.list.cancel(); });
        container.appendChild(table);
        container.appendChild(this.description);
        container.appendChild(buttons);
    }

    bind(list) {
        this.list = list;
        list.view = this;
        return list;
    }

    show_row(slot, row) {
        var r = this.rows[slot];
        r.element.style.display = '';
        r.element.className = 'dialogrow' + (row.selectable === false ? ' dialogrow-disabled' : '');
        r.element.setAttribute('aria-disabled', row.selectable === false ? 'true' : 'false');
        r.element.setAttribute('aria-selected', 'false');
        if (row.pic) {
            r.pic.src = row.pic;
            r.pic.style.visibility = '';
        } else {
            r.pic.style.visibility = 'hidden';
        }
        for (var c = 0; c < r.cells.length; c++) {
            r.cells[c].textContent = row.cells[c] !== undefined ? row.cells[c] : '';
            r.cells[c].style.color = (row.colors && row.colors[c]) || '';
        }
    }

    hide_row(slot) {
        this.rows[slot].element.style.display = 'none';
    }

    highlight_row(slot, row) {
        var element = this.rows[slot].element;
        element.style.backgroundColor = row.selectable === false ? 'rgb(96,0,0)' : 'rgb(0,96,0)';
        element.setAttribute('aria-selected', 'true');
        this.table.setAttribute('aria-activedescendant', element.id);
    }

    unhighlight_row(slot) {
        var element = this.rows[slot].element;
        element.style.backgroundColor = '';
        element.setAttribute('aria-selected', 'false');
        if (this.table.getAttribute('aria-activedescendant') === element.id) {
            this.table.setAttribute('aria-activedescendant', '');
        }
    }

    set_description(text) {
        this.description.textContent = text;
    }

    set_paging(has_previous, has_next) {
        this.previous.style.visibility = has_previous ? '' : 'hidden';
        this.next.style.visibility = has_next ? '' : 'hidden';
    }

    _button(parent, label, action) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'dialogbutton';
        button.textContent = label;
        button.onclick = action;
        parent.appendChild(button);
        return button;
    }
};
SelectionListDomView.count = 0;
