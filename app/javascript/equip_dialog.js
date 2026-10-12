// The equip dialog: pick where an item goes (or what to take off) on a
// figure of the hero. Combat and the hub both use it; the caller decides
// what a choice means (equip_menu.js turns it into a fight command).
//
//   EquipModel   the rules, no drawing: which slots can be picked, what a
//                pick fills, what it takes off, the side the server
//                expects. Mirrors CHARACTER::equip_item()/unequip_item().
//   EquipDialog  input: mouse (through the view) and keys -- arrows move
//                to the nearest pickable slot in that direction, Enter or
//                Space picks, Escape cancels. Goes on the dialog stack.
//   EquipDomView draws into the equip window html_equip_menu() prints.
//
// Needs dialogs.js.

// Where each slot sits in the equip window (html_equip_menu.php's
// get_equip_points(), which the fight page prints as equip_layout). The
// character's right hand is on the viewer's left, like facing them.
var EQUIP_LAYOUT_DEFAULT = {
    lhand: [400, 225], lammo: [400, 275], rhand: [0, 225], rammo: [0, 275],
    larm: [400, 125], rarm: [0, 125], body: [200, 175], head: [400, 25],
    back: [0, 50], feet: [200, 400],
};

var EQUIP_SLOT_NAMES = {
    lhand: 'Left hand', lammo: 'Left ammunition', rhand: 'Right hand', rammo: 'Right ammunition',
    larm: 'Left arm', rarm: 'Right arm', body: 'Body', head: 'Head', back: 'Back', feet: 'Feet',
};

// hero: {equipment: {slot: inventory index or null}, inventory: [{item, qty}]}
// items: item id => {name, menu_pic, equip_type: [...], ammo_type}
// using: the inventory index being put on, or -1 to take something off.
var EquipModel = class {
    constructor(hero, items, using, slots) {
        this.hero = hero;
        this.items = items;
        this.using = (using === null || using === undefined) ? -1 : using;
        this.slots = slots || Object.keys(EQUIP_LAYOUT_DEFAULT);
    }

    // 'hand', 'arm' or 'ammo' for a sided slot, else the slot itself.
    static base(slot) {
        var match = /^[lr](hand|arm|ammo)$/.exec(slot);
        return match ? match[1] : slot;
    }

    // What find_slot() on the server means by a side: 0 left, 1 right.
    static side_of(slot) {
        return /^r(hand|arm|ammo)$/.test(slot) ? 1 : 0;
    }

    // find_slot(): a generic 'hand', 'arm' or 'ammo' on a side.
    static find_slot(type, side) {
        return (type === 'hand' || type === 'arm' || type === 'ammo') ? (side === 0 ? 'l' : 'r') + type : type;
    }

    equipping() {
        return this.using !== -1;
    }

    item_at(index) {
        var entry = index === null || index === undefined ? null : this.hero.inventory[index];
        return entry ? (this.items[entry.item] || null) : null;
    }

    // The item being put on, or null when taking off.
    item() {
        return this.equipping() ? this.item_at(this.using) : null;
    }

    worn(slot) {
        var index = this.hero.equipment[slot];
        return index === undefined ? null : index;
    }

    // {name, qty, pic} of what's worn in $slot, or null.
    worn_item(slot) {
        var index = this.worn(slot);
        var item = this.item_at(index);
        return item === null ? null : {name: item.name, qty: this.hero.inventory[index].qty, pic: item.menu_pic};
    }

    // The side ammo of the weapon in $side's hand goes on: its own side,
    // or the left for a two-handed weapon (as equip_item() does).
    _ammo_side(side) {
        var hand = this.worn(side === 0 ? 'lhand' : 'rhand');
        return hand !== null && hand === this.worn('lhand') && hand === this.worn('rhand') ? 0 : side;
    }

    can_choose(slot) {
        if (!this.equipping()) {
            return this.worn(slot) !== null;
        }
        var item = this.item();
        if (item === null || !item.equip_type) {
            return false;
        }
        var fits = item.equip_type.some(function (type) {
            return type === slot || type === EquipModel.base(slot);
        });
        if (!fits) {
            return false;
        }
        if (item.equip_type.length === 1 && item.equip_type[0] === 'ammo') {
            // Ammo needs a weapon that takes it in that hand.
            var weapon = this.item_at(this.worn(EquipModel.find_slot('hand', EquipModel.side_of(slot))));
            return weapon !== null && !!item.ammo_type && weapon.ammo_type === item.ammo_type;
        }
        return true;
    }

    // The slots picking $slot would put the item in.
    fills(slot) {
        if (!this.equipping() || !this.can_choose(slot)) {
            return [];
        }
        var item = this.item();
        var side = EquipModel.side_of(slot);
        if (item.equip_type.length === 1 && item.equip_type[0] === 'ammo') {
            side = this._ammo_side(side);
        }
        return item.equip_type.map(function (type) { return EquipModel.find_slot(type, side); });
    }

    // Every slot holding inventory item $index, and the ammo that goes
    // with it if it's a weapon that takes ammo (unequip_item()).
    _slots_of(index) {
        var self = this;
        var slots = this.slots.filter(function (slot) { return self.worn(slot) === index; });
        var item = this.item_at(index);
        var hands = slots.filter(function (slot) { return slot === 'lhand' || slot === 'rhand'; });
        if (item && item.ammo_type && item.equip_type.indexOf('ammo') === -1 && hands.length > 0) {
            var ammo = hands.length > 1 ? 'lammo' : hands[0].charAt(0) + 'ammo';
            if (this.worn(ammo) !== null && slots.indexOf(ammo) === -1) {
                slots.push(ammo);
            }
        }
        return slots;
    }

    // The slots whose items would come off.
    empties(slot) {
        if (!this.can_choose(slot)) {
            return [];
        }
        var self = this;
        var off = [];
        var take_off = function (index) {
            self._slots_of(index).forEach(function (s) { if (off.indexOf(s) === -1) off.push(s); });
        };
        if (!this.equipping()) {
            take_off(this.worn(slot));
            return off;
        }
        this.fills(slot).forEach(function (s) {
            var index = self.worn(s);
            if (index !== null) take_off(index);
        });
        if (this.slots.some(function (s) { return self.worn(s) === self.using; })) {
            take_off(this.using); // moving it from where it is now
        }
        return off;
    }

    // What a fight command's target carries for this pick: the side to
    // equip on, or the inventory index to take off.
    target(slot) {
        return this.equipping() ? EquipModel.side_of(slot) : this.worn(slot);
    }

    // True if the item takes ammo that has to be picked next (a weapon,
    // not ammo itself).
    needs_ammo() {
        var item = this.item();
        return item !== null && !!item.ammo_type && item.equip_type.join(',').indexOf('ammo') === -1;
    }
};

// The nearest of $positions ({key: [x, y]}) from $from in a direction
// ('ArrowUp' etc.), among $allowed keys; null if there's none that way.
// Straight ahead counts more than off to the side, so the move goes where
// the eye expects. Used for any grid of choices the keyboard or a
// controller moves around.
function dialog_nearest(positions, from, direction, allowed) {
    var origin = positions[from];
    var best = null, best_score = Infinity;
    allowed.forEach(function (key) {
        if (key === from || !positions[key]) return;
        var dx = positions[key][0] - origin[0], dy = positions[key][1] - origin[1];
        var ahead, aside;
        switch (direction) {
            case 'ArrowUp': ahead = -dy; aside = Math.abs(dx); break;
            case 'ArrowDown': ahead = dy; aside = Math.abs(dx); break;
            case 'ArrowLeft': ahead = -dx; aside = Math.abs(dy); break;
            case 'ArrowRight': ahead = dx; aside = Math.abs(dy); break;
            default: return;
        }
        if (ahead <= 0) return;
        var score = ahead + 2 * aside;
        if (score < best_score) {
            best = key;
            best_score = score;
        }
    });
    return best;
}

// options:
//   model        an EquipModel
//   view         draws it: render(model), show_effect(slot, fills,
//                empties), clear_effect(), set_description(text). Any may
//                be missing.
//   layout       slot => [x, y] for arrow keys (EQUIP_LAYOUT_DEFAULT)
//   on_choose(slot, model)   a slot was picked
//   on_cancel()
//   on_highlight(slot or null, model)   the highlight moved (previews)
//   sounds       {select, cancel, refuse} clip names, as SelectionList
var EquipDialog = class {
    constructor(options) {
        this.view = options.view || {};
        this.layout = options.layout || EQUIP_LAYOUT_DEFAULT;
        this.on_choose = options.on_choose || null;
        this.on_cancel = options.on_cancel || null;
        this.on_highlight = options.on_highlight || null;
        this.sounds = options.sounds || null;
        this.highlighted = null;
        this.set_model(options.model);
    }

    set_model(model) {
        this.model = model;
        this.highlighted = null;
        if (model) {
            this._view('render', model);
            this._view('clear_effect');
            this._view('set_description', '');
        }
    }

    choosable() {
        var model = this.model;
        return model ? model.slots.filter(function (slot) { return model.can_choose(slot); }) : [];
    }

    highlight(slot) {
        if (!this.model.can_choose(slot)) {
            return;
        }
        this.highlighted = slot;
        this._view('clear_effect');
        this._view('show_effect', slot, this.model.fills(slot), this.model.empties(slot));
        var worn = this.model.worn_item(slot);
        this._view('set_description', EQUIP_SLOT_NAMES[slot] + (worn ? ': ' + worn.name : ''));
        if (this.on_highlight) this.on_highlight(slot, this.model);
    }

    unhighlight(slot) {
        if (slot !== undefined && slot !== this.highlighted) {
            return;
        }
        this.highlighted = null;
        this._view('clear_effect');
        this._view('set_description', '');
        if (this.on_highlight) this.on_highlight(null, this.model);
    }

    // Picks $slot. False (and a refusal sound) if it can't be picked.
    choose(slot) {
        if (!this.model.can_choose(slot)) {
            this._sound('refuse');
            return false;
        }
        this._sound('select');
        this.unhighlight();
        if (this.on_choose) this.on_choose(slot, this.model);
        return true;
    }

    cancel() {
        this._sound('cancel');
        this.unhighlight();
        if (this.on_cancel) this.on_cancel();
    }

    handle_key(key) {
        var choosable = this.choosable();
        switch (key) {
            case 'ArrowUp':
            case 'ArrowDown':
            case 'ArrowLeft':
            case 'ArrowRight':
                if (choosable.length === 0) return true;
                if (this.highlighted === null) {
                    this.highlight(choosable[0]);
                } else {
                    var next = dialog_nearest(this.layout, this.highlighted, key, choosable);
                    if (next !== null) this.highlight(next);
                }
                return true;
            case 'Tab':
                return false; // the browser's own focus order
            case 'Enter':
            case ' ':
                if (this.highlighted !== null) this.choose(this.highlighted);
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

// Draws an EquipDialog into the equip window html_equip_menu() prints
// (div#equipwindow with an equiprow/equippic/equipitem per slot). Rows
// that can be picked get a green border; the rows a pick fills are teal,
// the ones it empties dark red. $image_dir is where item pictures live.
var EquipDomView = class {
    constructor(image_dir, slots) {
        this.image_dir = image_dir || '';
        this.slots = slots || Object.keys(EQUIP_LAYOUT_DEFAULT);
        this.model = null;
        var window_element = document.getElementById('equipwindow');
        if (window_element) {
            window_element.setAttribute('role', 'dialog');
            window_element.setAttribute('aria-label', 'Choose where to equip');
        }
    }

    _row(slot) { return document.getElementById('equiprow' + slot); }

    render(model) {
        this.model = model;
        var self = this;
        this.slots.forEach(function (slot) {
            var row = self._row(slot);
            if (!row) return;
            var choosable = model.can_choose(slot);
            var worn = model.worn_item(slot);
            row.style.borderColor = choosable ? 'rgb(0,192,0)' : '';
            row.style.backgroundColor = '';
            row.selectable = choosable;
            row.setAttribute('role', 'button');
            row.setAttribute('aria-disabled', choosable ? 'false' : 'true');
            row.setAttribute('aria-label', EQUIP_SLOT_NAMES[slot] + ': ' + (worn ? worn.name : 'empty'));
            var pic = document.getElementById('equippic' + slot);
            if (pic) {
                var src = self.image_dir + (worn && worn.pic ? worn.pic : 'bignone.png');
                if (typeof push_image === 'function') push_image(src); // the image cache's bookkeeping
                pic.src = src;
            }
            var text = document.getElementById('equipitem' + slot);
            if (text) text.textContent = worn ? worn.name + (worn.qty > 1 ? ' (' + worn.qty + ')' : '') : '';
        });
    }

    show_effect(slot, fills, empties) {
        var self = this;
        empties.forEach(function (s) {
            var row = self._row(s);
            if (row) row.style.backgroundColor = 'rgb(128,0,0)';
        });
        (fills.length > 0 ? fills : [slot]).forEach(function (s) {
            var row = self._row(s);
            if (!row) return;
            row.style.borderColor = 'rgb(192,192,0)';
            if (fills.length > 0) row.style.backgroundColor = 'rgb(0,128,128)';
        });
    }

    clear_effect() {
        var self = this;
        this.slots.forEach(function (slot) {
            var row = self._row(slot);
            if (!row) return;
            row.style.backgroundColor = '';
            row.style.borderColor = row.selectable ? 'rgb(0,192,0)' : '';
        });
    }

    set_description(text) {
        var desc = document.getElementById('equipdesc');
        if (desc) desc.textContent = text;
    }
};

// --- the equip window's own handlers (html_equip_menu.php) ---

// The EquipDialog drawing into the equip window now: combat's (set by
// equip_menu.js) or the hub's. The window's rows and cancel button call
// these, whichever it is.
var equip_window_dialog = null;

function equip_window_highlight(slot) {
    if (equip_window_dialog) equip_window_dialog.highlight(slot);
}

function equip_window_unhighlight(slot) {
    if (equip_window_dialog) equip_window_dialog.unhighlight(slot);
}

function equip_window_select(slot) {
    return equip_window_dialog ? equip_window_dialog.choose(slot) : false;
}

function equip_window_cancel() {
    if (equip_window_dialog) equip_window_dialog.cancel();
}

function highlight_equip_menu_icon(object) {
    object.style.border = 'solid white';
    object.style.zIndex = 30;
}

function unhighlight_equip_menu_icon(object) {
    object.style.border = 'solid black';
    object.style.zIndex = 25;
}
