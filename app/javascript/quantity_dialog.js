// The quantity dialog: how many to buy, sell, use or give. Up and down
// between a least and a most, with the total when there's a price.
//
// options:
//   title, prompt   what it asks ("Buy Potion", "How many?")
//   min (1), max    the range; with price and gold, max is also what
//                   the gold can pay for
//   value           the starting amount (min)
//   price           per one, to show a total (optional)
//   gold            gold on hand, to show and to cap max (optional)
//   unit            what the price is in ('gold')
//   on_confirm(quantity), on_cancel()
//   parent, frame   as dialog_frame()
//
// Keys: Up/Right one more, Down/Left one fewer, PageUp/PageDown ten,
// Home/End the least and the most, digits type an amount, Backspace
// takes the last digit off, Enter confirms, Escape cancels.
//
// Needs dialogs.js and status_dialog.js (dialog_element, dialog_button).
var QuantityDialog = class {
    constructor(options) {
        this.options = options || {};
        this.min = this.options.min !== undefined ? this.options.min : 1;
        var max = this.options.max !== undefined ? this.options.max : 99;
        if (this.options.price > 0 && this.options.gold !== undefined) {
            max = Math.min(max, Math.floor(this.options.gold / this.options.price));
        }
        this.max = max;
        this.value = this.clamp(this.options.value !== undefined ? this.options.value : this.min);
        this.typed = null; // digits typed so far, or null
        this.frame = null;
        this.element = null;
    }

    // Nothing to choose: fewer can be afforded (or are held) than the least.
    empty() {
        return this.max < this.min;
    }

    clamp(value) {
        if (this.empty()) return this.min;
        return Math.max(this.min, Math.min(this.max, value));
    }

    set(value) {
        this.value = this.clamp(value);
        this.draw();
        return this.value;
    }

    step(by) {
        this.typed = null;
        return this.set(this.value + by);
    }

    total() {
        return this.options.price > 0 ? this.value * this.options.price : null;
    }

    open() {
        var make_frame = this.options.frame || dialog_frame;
        this.frame = make_frame({title: this.options.title || 'How many?', parent: this.options.parent, width: 340, height: 240});
        this.element = this.frame.element;
        this.element.setAttribute('role', 'dialog');
        this.element.setAttribute('aria-label', this.options.title || 'How many?');
        var body = this.frame.body;
        var self = this;
        if (this.options.prompt) body.appendChild(dialog_element('div', 'dialogdesc', this.options.prompt));
        var row = dialog_element('div', 'quantityrow');
        dialog_button(row, '−', function () { self.step(-1); }).setAttribute('aria-label', 'One fewer');
        this.number = dialog_element('div', 'quantityvalue');
        this.number.setAttribute('role', 'spinbutton');
        this.number.setAttribute('tabindex', '0');
        this.number.setAttribute('aria-label', 'Quantity');
        row.appendChild(this.number);
        dialog_button(row, '+', function () { self.step(1); }).setAttribute('aria-label', 'One more');
        body.appendChild(row);
        this.total_line = dialog_element('div', 'quantitytotal');
        this.total_line.setAttribute('aria-live', 'polite');
        body.appendChild(this.total_line);
        var buttons = dialog_element('div', 'dialogbuttons');
        this.ok = dialog_button(buttons, 'OK', function () { self.confirm(); });
        dialog_button(buttons, 'Cancel', function () { self.cancel(); });
        body.appendChild(buttons);
        this.draw();
        this.frame.show();
        dialog_open(this);
        if (this.number.focus) this.number.focus();
        return this;
    }

    draw() {
        if (!this.number) return;
        this.number.textContent = this.empty() ? '-' : String(this.value);
        this.number.setAttribute('aria-valuemin', String(this.min));
        this.number.setAttribute('aria-valuemax', String(Math.max(this.min, this.max)));
        this.number.setAttribute('aria-valuenow', String(this.value));
        var unit = this.options.unit || 'gold';
        var text = '';
        if (this.empty()) {
            text = this.options.price > 0 ? 'Not enough ' + unit + '.' : 'None to choose.';
        } else if (this.total() !== null) {
            text = 'Total: ' + Math.round(this.total()) + ' ' + unit; // a sale can pay fractions each
            if (this.options.gold !== undefined) text += ' of ' + this.options.gold;
        }
        this.total_line.textContent = text;
        this.ok.disabled = this.empty();
    }

    confirm() {
        if (this.empty()) return false;
        var value = this.value;
        this.close();
        if (this.options.on_confirm) this.options.on_confirm(value);
        return true;
    }

    cancel() {
        this.close();
        if (this.options.on_cancel) this.options.on_cancel();
    }

    close() {
        dialog_close(this);
        if (this.frame) this.frame.remove();
    }

    handle_key(key) {
        switch (key) {
            case 'ArrowUp': case 'ArrowRight': this.step(1); return true;
            case 'ArrowDown': case 'ArrowLeft': this.step(-1); return true;
            case 'PageUp': this.step(10); return true;
            case 'PageDown': this.step(-10); return true;
            case 'Home': this.typed = null; this.set(this.min); return true;
            case 'End': this.typed = null; this.set(this.max); return true;
            case 'Enter': this.confirm(); return true;
            case 'Backspace':
                if (this.typed) {
                    this.typed = this.typed.slice(0, -1);
                    this.set(this.typed === '' ? this.min : parseInt(this.typed, 10));
                }
                return true;
        }
        if (/^[0-9]$/.test(key)) {
            // Typing builds a number; one past the most starts over.
            var typed = (this.typed || '') + key;
            if (parseInt(typed, 10) > this.max) typed = key;
            this.typed = typed;
            this.set(parseInt(typed, 10));
            return true;
        }
        return false;
    }
};
