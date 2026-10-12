// The hub (app.php): the party in formation with the menu always beside
// it. Everything a player does between fights starts here; what isn't
// moved into the app yet opens its old page.
//
//   hub_start(options)   builds the hub and asks the server for the party
//   HubPartyView         draws the party: a row per group, a tile per hero
//   FormationDialog      rearranging the party from the keyboard or mouse
//   hub_pick_hero()      a list of the party's heroes, for Equip and Status
//
// Server calls (through call_server): hub_state, move_hero, rest_at_inn,
// character_sheet, preview_equipment, change_equipment, field_options,
// use_thing, store_open, store_goods, store_buy, store_sell,
// manage_options, change_hero, alliance_view, alliance_act.
//
// Needs dialogs.js, dialog_text.js, status_dialog.js, equip_dialog.js,
// quantity_dialog.js, conversation_dialog.js, menu_dialog.js, rpc.js.

var hub = null;

var HUB_TILE = {width: 112, height: 150};

// options:
//   party, menu, status   the elements to draw into
//   fighter_images        where hero pictures live
//   call                  stands in for call_server (tests)
//   navigate(url)         stands in for going to another page (tests)
function hub_start(options) {
    hub = {
        options: options,
        call: options.call || call_server,
        navigate: options.navigate || function (url) { window.location.href = url; },
        state: null,
        view: new HubPartyView(options.party, options.fighter_images || ''),
        status: options.status || null,
        formation: null,
    };
    hub.view.on_hero = hub_hero_clicked;
    hub.menu = new MenuDialog({container: options.menu, label: 'Hub menu', items: hub_menu_items(hub_actions())});
    dialog_set_base(hub.menu);
    var loaded = hub_load();
    if (options.resume_fight) hub_fight('rpg.php'); // came back mid-fight
    return loaded;
}

function hub_actions() {
    var go = function (url) { return function () { hub.navigate(url); }; };
    var has_party = function () { return hub.state !== null; };
    var ready = function () { return hub.state !== null && Object.keys(hub.state.needs || {}).length === 0; };
    return {
        // No fighting until every hero has a job and look on offer (the
        // Manage team dialog sees to that).
        fight: {action: function () { hub_fight('init_fight.php'); }, enabled: ready},
        public_fight: {action: function () { hub_fight('init_fight.php?public'); }, enabled: ready},
        inn: {action: hub_inn, enabled: has_party},
        shop: {action: hub_shop, enabled: has_party},
        items: {action: function () {
            hub_pick_hero('Whose items?', function (hero) { hub_use(hero.charid, 'items'); });
        }, enabled: has_party},
        spells: {action: function () {
            hub_pick_hero('Who casts?', function (hero) { hub_use(hero.charid, 'spells'); });
        }, enabled: has_party},
        abilities: {action: function () {
            hub_pick_hero('Who acts?', function (hero) { hub_use(hero.charid, 'abilities'); });
        }, enabled: has_party},
        equip: {action: function () {
            hub_pick_hero('Equip whom?', function (hero) { hub_equip(hero.charid); });
        }, enabled: has_party},
        status: {action: function () {
            hub_pick_hero('Whose status?', function (hero) { hub_show_status(hero.charid); });
        }, enabled: has_party},
        formation: {action: hub_formation, enabled: has_party},
        alliance: {action: hub_alliance, enabled: has_party},
        manage_team: {action: hub_manage, enabled: has_party},
        fight_history: {action: function () {
            hub.navigate('fight_history.php?teamid=' + encodeURIComponent(hub.state.teams[0].teamid));
        }, enabled: has_party},
        settings: function () { open_settings({parent: hub.options.stage, call: hub.call}); },
        account: go('account.php'),
        team_select: go('pick_team.php'),
    };
}

// --- fights ---
//
// A fight plays in a frame over the hub: the fight page itself
// (init_fight.php starts one and goes on to rpg.php). When it's over, rpg.php calls hub_fight_over() instead
// of leaving (combat.js's fight_terminate()), and the hub comes back. The
// fight engine still starts fresh for every fight this way; playing
// fights in the hub's own page waits for the Python server.

// Pages that belong in the fight frame; anything else it's sent to ends
// the fight's turn there.
var HUB_FIGHT_PAGES = ['init_fight.php', 'rpg.php'];

function hub_fight(url) {
    if (hub.fight_frame) return hub.fight_frame;
    var frame = document.createElement('iframe');
    frame.className = 'hubfight';
    frame.title = 'Fight';
    frame.onload = function () { hub_fight_loaded(frame); };
    frame.src = url;
    hub_fight_resize(typeof settings_get === 'function' && settings_get('text_log') === true, frame);
    (hub.options.frame_parent || document.body).appendChild(frame);
    hub.fight_frame = frame;
    hub_say('');
    return frame;
}

// The fight frame, wide enough for the combat log beside the stage
// when it's showing (combat_log.js calls this from inside the frame).
var HUB_FIGHT_WIDTH = 804;
var HUB_FIGHT_WIDTH_WITH_LOG = 1112;

function hub_fight_resize(with_log, frame) {
    frame = frame || hub.fight_frame;
    if (frame && frame.style) frame.style.width = (with_log ? HUB_FIGHT_WIDTH_WITH_LOG : HUB_FIGHT_WIDTH) + 'px';
}

function hub_fight_loaded(frame) {
    var where;
    try {
        where = frame.contentWindow.location;
    } catch (e) {
        return;
    }
    var page = where.pathname.substring(where.pathname.lastIndexOf('/') + 1);
    if (HUB_FIGHT_PAGES.indexOf(page) !== -1) {
        if (frame.focus) frame.focus();
        return;
    }
    // Sent somewhere else (no fight to start, or the fight is gone).
    hub_fight_over(page + where.search);
}

// The fight is over; $redirect is where the fight page would have gone.
// Back to the hub (or the old setup_fight.php, which now leads here)
// means stay; anywhere else, go there.
function hub_fight_over(redirect) {
    var frame = hub.fight_frame;
    hub.fight_frame = null;
    if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
    var page = String(redirect || '').split('?')[0].split('#')[0];
    if (page !== '' && page !== 'setup_fight.php' && page !== 'app.php') {
        hub.navigate(redirect);
        return Promise.resolve();
    }
    return hub_load().then(function () {
        if (hub.menu.highlighted !== null) hub.menu.highlight(hub.menu.highlighted);
    });
}

function hub_load() {
    return Promise.resolve(hub.call('hub_state')).then(function (answer) {
        hub_show(answer[0]);
    });
}

// Draws $state (HubState) -- or, with none, sends the player to pick a team.
function hub_show(state) {
    if (!state) {
        // (Not while a fight is open over the hub: a monster player needn't
        // have picked a team.)
        if (!hub.fight_frame) hub.navigate('pick_team.php');
        return;
    }
    hub.state = state;
    hub.view.draw(state);
    hub_say_needs(state);
    if (hub.formation) hub.formation.redraw();
    hub.menu.refresh();
}

function hub_say(text) {
    if (hub.status) hub.status.textContent = text;
}

// Every hero, with where it stands: [{group, position, charid, name, ...}].
function hub_heroes() {
    var heroes = [];
    (hub.state ? hub.state.groups : []).forEach(function (group, g) {
        group.forEach(function (hero, p) {
            heroes.push(Object.assign({group: g, position: p}, hero));
        });
    });
    return heroes;
}

function hub_hero_clicked(group, position) {
    if (hub.formation) {
        hub.formation.clicked(group, position);
        return;
    }
    var hero = hub.state.groups[group][position];
    hub_show_status(hero.charid);
}

function hub_show_status(charid) {
    return new StatusDialog({charid: charid, call: hub.call, parent: hub.options.stage}).open();
}

// A list of the party's heroes; $on_pick(hero) with the one chosen.
function hub_pick_hero(title, on_pick) {
    return hub_pick(title, hub_heroes().map(function (hero) {
        return {value: hero, cells: [hero.name, 'Lv ' + hero.level + ' ' + hero.job],
            pic: hero.picture ? hub.view.images + hero.picture : null};
    }), on_pick);
}

// A list to pick one row from ($rows as SelectionList rows); closes and
// calls $on_pick(value) with the one chosen. Escape closes it.
function hub_pick(title, rows, on_pick) {
    var shown = Math.min(6, Math.max(1, rows.length));
    // As tall as its rows: title, rows, description, buttons.
    var frame = (hub.options.frame || dialog_frame)({title: title, parent: hub.options.stage, width: 500, height: 116 + shown * 46});
    var view = new SelectionListDomView(frame.body, [{align: 'left'}, {align: 'right', width: 160}], shown);
    var picker = {element: frame.element};
    var close = function () {
        dialog_close(picker);
        frame.remove();
    };
    var list = new SelectionList({
        page_size: shown,
        on_choose: function (row) {
            close();
            on_pick(row.value);
        },
        on_cancel: close,
    });
    view.bind(list);
    picker.handle_key = function (key) { return list.handle_key(key); };
    picker.cancel = function () { list.cancel(); };
    picker.list = list;
    list.set_rows(rows);
    frame.element.setAttribute('role', 'dialog');
    frame.element.setAttribute('aria-label', title);
    frame.show();
    dialog_open(picker);
    var first = 0;
    while (first < rows.length - 1 && first < shown - 1 && rows[first].selectable === false) first++;
    list.highlight(first);
    return picker;
}

// The inn, as a conversation: each team's price, Yes or No.
function hub_inn_text(state) {
    var prices = state.teams.map(function (team) {
        return state.teams.length > 1 ? team.inn_price + ' gold for ' + team.name : team.inn_price + ' gold';
    });
    var total = state.teams.reduce(function (sum, team) { return sum + team.inn_price; }, 0);
    return 'Welcome to the inn! The fire is warm and the beds are soft.\n'
        + '`inn,' + total + ',bye`A night\'s rest is ' + prices.join(' and ') + '. Will you stay?\n'
        + '`label,rested`You wake refreshed, every wound healed.`jump,end`'
        + '`label,short`You can\'t pay for the rooms.`jump,end`'
        + '`label,bye`Come back any time.';
}

function hub_inn() {
    var conversation = new ConversationDialog({
        title: 'The Inn',
        text: hub_inn_text(hub.state),
        parent: hub.options.stage,
        delay: hub.options.text_delay,
        on_tag: function (command) {
            if (command !== 'inn') return Promise.resolve(null);
            return Promise.resolve(hub.call('rest_at_inn')).then(function (answer) {
                var problem = answer[0];
                if (answer[1]) hub_show(answer[1]);
                hub_say(problem || (hub.state.teams.length > 1 ? 'Your teams have rested.' : 'Your team has rested.'));
                return problem ? 'short' : 'rested';
            });
        },
    });
    conversation.open();
    return conversation;
}

function hub_formation() {
    hub.formation = new FormationDialog();
    dialog_open(hub.formation);
    hub_say('Formation: arrow keys pick a hero, Enter picks them up, arrows move them, Enter puts them down, Escape when done.');
    return hub.formation;
}

// --- the party view ---

var HubPartyView = class {
    constructor(container, images) {
        this.container = container;
        this.images = images;
        this.tiles = {};      // 'g;p' => tile element
        this.on_hero = null;  // (group, position)
        this.on_arrow = null; // (group, position, direction)
    }

    draw(state) {
        var self = this;
        dialog_clear(this.container);
        this.tiles = {};
        var head = dialog_element('div', 'hubteams');
        head.textContent = state.teams.map(function (team) { return team.name + ': ' + team.gold + ' gold'; }).join('  ·  ');
        this.container.appendChild(head);
        if (state.alliance) {
            this.container.appendChild(dialog_element('div', 'huballiance',
                'Alliance: ' + Math.ceil(state.alliance.seconds_left / 60) + ' min left'));
        }
        state.groups.forEach(function (group, g) {
            var row = dialog_element('div', 'hubgroup');
            row.setAttribute('role', 'group');
            row.setAttribute('aria-label', 'Group ' + (g + 1));
            group.forEach(function (hero, p) {
                row.appendChild(self._tile(hero, g, p));
            });
            self.container.appendChild(row);
        });
    }

    _tile(hero, g, p) {
        var self = this;
        var tile = dialog_element('div', 'hubhero');
        tile.setAttribute('role', 'button');
        tile.setAttribute('aria-label', hero.name + ', level ' + hero.level + ' ' + hero.job
            + ', HP ' + hero.hp[0] + ' of ' + hero.hp[1] + ', MP ' + hero.mp[0] + ' of ' + hero.mp[1]);
        if (hero.picture) {
            var pic = dialog_element('img', 'hubheropic');
            pic.src = this.images + hero.picture;
            pic.alt = '';
            tile.appendChild(pic);
        }
        tile.appendChild(dialog_element('div', 'hubheroname', hero.name));
        tile.appendChild(dialog_element('div', 'hubheroline', 'Lv ' + hero.level + ' ' + hero.job));
        tile.appendChild(dialog_element('div', 'hubheroline', 'HP ' + hero.hp[0] + '/' + hero.hp[1]));
        tile.appendChild(dialog_element('div', 'hubheroline', 'MP ' + hero.mp[0] + '/' + hero.mp[1]));
        tile.onclick = function () { if (self.on_hero) self.on_hero(g, p); };
        this.tiles[g + ';' + p] = tile;
        return tile;
    }

    // Marks one tile highlighted, and whether it's picked up (with arrows
    // to move it by mouse).
    mark(key, held) {
        var self = this;
        Object.keys(this.tiles).forEach(function (k) {
            var tile = self.tiles[k];
            tile.className = 'hubhero' + (k === key ? (held ? ' hubhero-held' : ' hubhero-highlighted') : '');
            tile.setAttribute('aria-pressed', k === key && held ? 'true' : 'false');
            if (tile.arrows) {
                tile.removeChild(tile.arrows);
                tile.arrows = null;
            }
        });
        if (key !== null && held && this.tiles[key]) {
            var parts = key.split(';');
            var arrows = dialog_element('div', 'hubarrows');
            [['◀', 'l', 'Move left'], ['▲', 'u', 'Move up a group'], ['▼', 'd', 'Move down a group'], ['▶', 'r', 'Move right']]
                .forEach(function (a) {
                    var b = dialog_button(arrows, a[0], function (event) {
                        if (event && event.stopPropagation) event.stopPropagation();
                        if (self.on_arrow) self.on_arrow(parseInt(parts[0], 10), parseInt(parts[1], 10), a[1]);
                    });
                    b.setAttribute('aria-label', a[2]);
                });
            this.tiles[key].appendChild(arrows);
            this.tiles[key].arrows = arrows;
        }
    }

    // Where each tile sits, for arrow keys: 'g;p' => [x, y].
    positions() {
        var out = {};
        Object.keys(this.tiles).forEach(function (key) {
            var parts = key.split(';');
            out[key] = [parseInt(parts[1], 10) * HUB_TILE.width, parseInt(parts[0], 10) * HUB_TILE.height];
        });
        return out;
    }
};

// --- formation ---

var FORMATION_DIRECTIONS = {ArrowLeft: 'l', ArrowRight: 'r', ArrowUp: 'u', ArrowDown: 'd'};

var FormationDialog = class {
    constructor() {
        this.key = null;     // highlighted 'g;p'
        this.held = false;
        this.charid = null;  // the hero highlighted, followed across moves
        this.busy = false;   // a move on its way to the server
        this.element = hub.view.container;
        var self = this;
        hub.view.on_arrow = function (g, p, direction) { self.move(g, p, direction); };
        var first = Object.keys(hub.view.tiles)[0];
        if (first !== undefined) this.select(first);
    }

    select(key) {
        this.key = key;
        var parts = key.split(';');
        var hero = hub.state.groups[parts[0]] && hub.state.groups[parts[0]][parts[1]];
        this.charid = hero ? hero.charid : null;
        hub.view.mark(key, this.held);
        if (hero) hub_say((this.held ? 'Moving ' : '') + hero.name);
    }

    // After the party is redrawn: find the hero again, wherever it went.
    redraw() {
        var self = this;
        var found = null;
        hub_heroes().forEach(function (hero) {
            if (hero.charid === self.charid) found = hero.group + ';' + hero.position;
        });
        if (found !== null) this.select(found);
        else hub.view.mark(null, false);
    }

    move(g, p, direction) {
        if (this.busy) return Promise.resolve();
        this.busy = true;
        var self = this;
        return Promise.resolve(hub.call('move_hero', g, p, direction)).then(function (answer) {
            self.busy = false;
            hub_show(answer[0]);
        }, function () { self.busy = false; });
    }

    clicked(g, p) {
        var key = g + ';' + p;
        if (key === this.key) {
            this.held = !this.held;
        } else {
            this.held = true;
        }
        this.select(key);
    }

    handle_key(key) {
        if (FORMATION_DIRECTIONS[key]) {
            if (this.key === null) return true;
            if (this.held) {
                var parts = this.key.split(';');
                this.move(parseInt(parts[0], 10), parseInt(parts[1], 10), FORMATION_DIRECTIONS[key]);
            } else {
                var next = dialog_nearest(hub.view.positions(), this.key, key, Object.keys(hub.view.tiles));
                if (next !== null) this.select(next);
            }
            return true;
        }
        if (key === 'Enter' || key === ' ') {
            if (this.key !== null) {
                this.held = !this.held;
                this.select(this.key);
            }
            return true;
        }
        return false;
    }

    cancel() {
        if (this.held) {
            this.held = false;
            this.select(this.key);
            return;
        }
        this.close();
    }

    close() {
        hub.view.mark(null, false);
        hub.view.on_arrow = null;
        dialog_close(this);
        hub.formation = null;
        hub_say('');
    }
};

// --- equipment ---

// A hero's equipment: their equippable items (and "Take something off");
// picking one opens the equip dialog, then the benefits, then the change.
// Stays open, redrawn, until cancelled, so several changes go quickly.
function hub_equip(charid) {
    var frame = (hub.options.frame || dialog_frame)({title: 'Equipment', parent: hub.options.stage, width: 480, height: 420});
    var view = new SelectionListDomView(frame.body, [{align: 'left'}, {align: 'right', width: 150}], 6);
    var box = {element: frame.element, sheet: null, frame: frame};
    var close = function () {
        dialog_close(box);
        frame.remove();
    };
    var list = new SelectionList({
        page_size: 6,
        on_choose: function (row) { hub_equip_item(box, row.value); },
        on_cancel: close,
    });
    view.bind(list);
    box.list = list;
    box.handle_key = function (key) { return list.handle_key(key); };
    box.cancel = function () { list.cancel(); };
    box.show = function (sheet) {
        box.sheet = sheet;
        frame.element.setAttribute('aria-label', 'Equipment: ' + sheet.name);
        list.set_rows(hub_equip_rows(sheet));
        list.highlight(0);
    };
    frame.element.setAttribute('role', 'dialog');
    frame.show();
    dialog_open(box);
    hub.equip = box;
    return Promise.resolve(hub.call('character_sheet', charid)).then(function (answer) {
        if (!answer[0]) {
            close();
            hub_say("That hero isn't on one of your teams.");
            return;
        }
        box.show(answer[0]);
    });
}

// The list's rows: each equippable item with where it's worn, then
// "Take something off" when anything is.
function hub_equip_rows(sheet) {
    var worn_at = {};
    Object.keys(sheet.worn).forEach(function (slot) {
        var index = sheet.worn[slot];
        if (index !== null) (worn_at[index] = worn_at[index] || []).push(EQUIP_SLOT_NAMES[slot]);
    });
    var rows = [];
    Object.keys(sheet.inventory).forEach(function (index) {
        var entry = sheet.inventory[index];
        var item = items[entry.item];
        if (!item || !item.equip_type || item.equip_type.length === 0) return;
        rows.push({
            value: parseInt(index, 10),
            cells: [item.name + (entry.qty > 1 ? ' (' + entry.qty + ')' : ''), worn_at[index] ? worn_at[index].join(', ') : ''],
            pic: item.menu_pic ? (hub.options.item_images || '') + item.menu_pic : null,
            desc: worn_at[index] ? 'Worn: ' + worn_at[index].join(', ') : 'Not worn',
        });
    });
    if (Object.keys(worn_at).length > 0) {
        rows.push({value: -1, cells: ['Take something off', ''], desc: 'Pick a slot to empty'});
    }
    return rows;
}

// The equip window for inventory item $index (-1: taking off).
function hub_equip_item(box, index) {
    var sheet = box.sheet;
    var model = new EquipModel({equipment: sheet.worn, inventory: sheet.inventory}, items, index);
    if (!hub.equip_dialog) {
        hub.equip_dialog = new EquipDialog({view: new EquipDomView(hub.options.item_images || '')});
    }
    var windowed = typeof document !== 'undefined' && document.getElementById ? document.getElementById('equipwindow') : null;
    equip_window_dialog = hub.equip_dialog;
    return equip_out_of_combat({
        dialog: hub.equip_dialog,
        model: model,
        charid: sheet.charid,
        call: hub.call,
        parent: hub.options.stage,
        frame: hub.options.frame,
        // The list steps aside while the equip window is up.
        show: function () { box.frame.hide(); if (windowed) windowed.style.visibility = 'visible'; },
        hide: function () { if (windowed) windowed.style.visibility = 'hidden'; box.frame.show(); },
        on_commit: function (slot, m) {
            return Promise.resolve(hub.call('change_equipment', sheet.charid, m.using, slot)).then(function (answer) {
                var problem = answer[0], message = answer[1], fresh = answer[2];
                hub_say(problem || message || 'Done.');
                if (fresh) box.show(fresh);
            });
        },
    });
}

// --- items, spells and abilities ---

var HUB_USE_TITLES = {items: 'Items', spells: 'Spells', abilities: 'Abilities'};

// A list in the list window combat uses (list_window.js), on the dialog
// stack. options: on_choose(value, box), on_close(), label. Returns the
// box: show(rows) draws (or redraws) it, close() closes it.
function hub_list_window(options) {
    var window_element = document.getElementById('menuwindow');
    var box = {element: window_element, closed: false};
    var list = new SelectionList({
        page_size: 8,
        view: list_window_view,
        on_choose: function (row) { options.on_choose(row.value, box); },
        on_cancel: function () { box.close(); },
    });
    box.list = list;
    box.handle_key = function (key) { return list.handle_key(key); };
    box.cancel = function () { list.cancel(); };
    box.show = function (rows, empty_text) {
        var at = list.highlighted !== null ? list.position + list.highlighted : (list.chosen || 0);
        list.set_rows(rows);
        if (rows.length === 0) {
            list_window_view.set_description(empty_text || '');
            return;
        }
        // Stay where the player was, or as near as the list now allows.
        at = Math.min(at, rows.length - 1);
        while (list.position + list.page_size <= at) list.next_page();
        list.highlight(at - list.position);
    };
    box.close = function () {
        if (box.closed) return;
        box.closed = true;
        dialog_close(box);
        if (list_window_list === list) list_window_list = null;
        if (window_element) window_element.style.visibility = 'hidden';
        if (options.on_close) options.on_close();
    };
    list_window_list = list;
    if (window_element) {
        window_element.setAttribute('role', 'dialog');
        window_element.setAttribute('aria-label', options.label || 'List');
        window_element.style.visibility = 'visible';
    }
    dialog_open(box);
    return box;
}

// A hero's items, spells or abilities ($section), in the list window;
// picking one goes on to what to do with it. Stays open, redrawn, until
// cancelled.
function hub_use(charid, section) {
    var box = hub_list_window({
        label: HUB_USE_TITLES[section],
        on_choose: function (entry) {
            if (section === 'items') hub_item_menu(box, entry);
            else hub_use_target(box, entry);
        },
    });
    box.options = null;
    box.section = section;
    var draw = box.show;
    box.show = function (options) {
        box.options = options;
        var actor = options.actor;
        var entries = options[section];
        var line = actor.name + ': HP ' + actor.hp[0] + '/' + actor.hp[1] + ', MP ' + actor.mp[0] + '/' + actor.mp[1];
        draw(entries.map(function (entry) {
            if (section === 'items') {
                return {value: entry, pic: entry.pic, cells: [entry.name, '\u00d7' + entry.qty], desc: entry.desc};
            }
            return {value: entry, pic: entry.pic, cells: [entry.name, entry.mp + ' MP'], selectable: entry.usable,
                colors: [null, entry.usable ? null : 'red'], desc: entry.usable ? entry.desc : entry.why};
        }), line + ' \u2014 ' + (section === 'items' ? 'carries nothing.' : 'knows none.'));
        if (box.element) box.element.setAttribute('aria-label', HUB_USE_TITLES[section] + ': ' + line);
        hub_say(line);
    };
    return Promise.resolve(hub.call('field_options', charid)).then(function (answer) {
        if (!answer[0]) {
            box.close();
            hub_say("That hero isn't in your party.");
            return;
        }
        box.show(answer[0]);
    });
}

// An item picked: use it, transfer it to another hero, or discard it.
function hub_item_menu(box, item) {
    var use = item.use;
    return hub_pick(item.name + ': what now?', [
        {value: 'use', cells: ['Use', ''], selectable: !!(use && use.usable),
            desc: !use ? 'It has no use.' : (use.usable ? item.desc : use.why)},
        {value: 'give', cells: ['Transfer', ''], desc: 'Give the ' + (item.qty > 1 ? item.qty + ' of them' : 'item') + ' to another hero.'},
        {value: 'discard', cells: ['Discard', ''], desc: 'Throw it away.'},
    ], function (what) {
        if (what === 'use') {
            return hub_use_target(box, Object.assign({kind: 'item', name: item.name, index: item.index}, use));
        }
        if (what === 'give') {
            return hub_use_target(box, {kind: 'give', name: 'Transfer ' + item.name, index: item.index, aim: 'hero', living: false});
        }
        var discard = {kind: 'discard', index: item.index};
        if (item.qty > 1) {
            return new QuantityDialog({title: 'Discard ' + item.name, prompt: 'How many?', min: 1, max: item.qty, value: item.qty,
                parent: hub.options.stage, frame: hub.options.frame,
                on_confirm: function (qty) { hub_use_do(box, discard, null, null, qty); }}).open();
        }
        return hub_pick('Throw away the ' + item.name + '?', [
            {value: true, cells: ['Yes, throw it away', '']},
            {value: false, cells: ['No, keep it', '']},
        ], function (yes) { if (yes) hub_use_do(box, discard, null, null, 1); });
    });
}

// Who $choice is aimed at: nobody to ask for the whole party; else a
// group, or a hero, from a list.
function hub_use_target(box, choice) {
    if (choice.aim === 'party') {
        return hub_use_do(box, choice, null, null);
    }
    var down = function (hero) { return choice.living && hero.hp[0] <= 0; };
    if (choice.aim === 'group') {
        return hub_pick(choice.name + ': which group?', hub.state.groups.map(function (group, g) {
            var all_down = group.every(down);
            return {value: g, cells: ['Group ' + (g + 1), group.map(function (h) { return h.name; }).join(', ')],
                selectable: !all_down, desc: all_down ? 'Everyone in this group is down.' : ''};
        }), function (g) { hub_use_do(box, choice, g, null); });
    }
    var actor = box.options.actor.charid;
    return hub_pick(choice.name + ': on whom?', hub_heroes().map(function (hero) {
        var self = choice.kind === 'give' && hero.charid === actor;
        return {value: hero, cells: [hero.name, 'HP ' + hero.hp[0] + '/' + hero.hp[1]],
            selectable: !down(hero) && !self,
            desc: self ? 'Already has it.' : (down(hero) ? hero.name + ' is down.' : 'MP ' + hero.mp[0] + '/' + hero.mp[1])};
    }), function (hero) { hub_use_do(box, choice, hero.group, hero.position); });
}

function hub_use_do(box, choice, group, position, qty) {
    return Promise.resolve(hub.call('use_thing', box.options.actor.charid, choice.kind, choice.index, group, position, qty || 0))
        .then(function (answer) {
            var problem = answer[0], message = answer[1], state = answer[2], options = answer[3];
            if (state) hub_show(state);
            if (options) box.show(options);
            hub_say(problem || message || 'Done.'); // after show(), which says who's acting
        });
}

// --- the shop ---
//
// A conversation the server writes (Store::hub_dialog()): the vend tag
// opens the wares, the hawk tag what the party could sell, each in the
// list window, and the talk carries on when the list is closed. An NPC's
// store in the overworld will be the same tags in its own words.

function hub_shop() {
    return Promise.resolve(hub.call('store_open')).then(function (answer) {
        if (!answer[0]) {
            hub_say('Pick a team first.');
            return null;
        }
        var talk = new ConversationDialog({
            title: 'The Shop',
            text: answer[0],
            parent: hub.options.stage,
            delay: hub.options.text_delay,
            // The talk steps aside while a list is up (the list window
            // sits below dialog frames), and comes back when it closes.
            on_tag: function (command, parts) {
                var menu = command === 'vend' ? hub_vend : (command === 'hawk' ? hub_hawk : null);
                if (menu === null) return Promise.resolve(null);
                talk.frame.hide();
                return menu(parts).then(function (label) {
                    if (!talk.closed) talk.frame.show();
                    return label;
                });
            },
        });
        talk.open();
        hub.shop = talk;
        return talk;
    });
}

// A vend tag's wares: [{itemid, price}]; a blank price is the item's own.
function hub_vend_wares(parts) {
    var wares = [];
    for (var i = 0; i + 1 < parts.length; i += 2) {
        var item = items[parts[i]];
        if (!item) continue;
        wares.push({itemid: parseInt(parts[i], 10), price: parts[i + 1] === '' ? item.price : parseInt(parts[i + 1], 10)});
    }
    return wares;
}

function hub_team_of(hero) {
    var found = null;
    hub.state.teams.forEach(function (team) { if (team.teamid === hero.team) found = team; });
    return found;
}

function hub_most_gold() {
    return hub.state.teams.reduce(function (most, team) { return Math.max(most, team.gold); }, 0);
}

function hub_gold_line() {
    return hub.state.teams.map(function (team) { return team.name + ': ' + team.gold + ' gold'; }).join(', ');
}

// Several of an item fit in one slot: anything but equipment, and ammo.
function hub_stacks(item) {
    return !item.equip_type || item.equip_type.length === 0 || item.equip_type.join(',').indexOf('ammo') !== -1;
}

// The wares; resolves (with null) when the list is closed.
function hub_vend(parts) {
    return new Promise(function (resolve) {
        var wares = hub_vend_wares(parts);
        var rows = function () {
            var most = hub_most_gold();
            return wares.map(function (ware) {
                var item = items[ware.itemid];
                return {value: ware, pic: (hub.options.item_images || '') + item.menu_pic, cells: [item.name, ware.price + ' g'],
                    selectable: ware.price <= most, colors: [null, ware.price <= most ? null : 'red'],
                    desc: item.description + (ware.price <= most ? '' : ' (More than you have.)')};
            });
        };
        var box = hub_list_window({
            label: 'Buy',
            on_choose: function (ware) { hub_buy(box, ware, rows); },
            on_close: function () { resolve(null); },
        });
        box.show(rows(), 'Nothing for sale.');
        hub_say(hub_gold_line());
    });
}

function hub_buy(box, ware, rows) {
    var item = items[ware.itemid];
    return hub_pick('Buy ' + item.name + ' for whom?', hub_heroes().map(function (hero) {
        var team = hub_team_of(hero);
        var afford = team !== null && team.gold >= ware.price;
        return {value: hero, cells: [hero.name, team ? team.gold + ' g' : ''], selectable: afford,
            desc: afford ? (team.name + ' pays.') : ((team ? team.name : 'Their team') + " can't afford it.")};
    }), function (hero) {
        var team = hub_team_of(hero);
        var send = function (qty) {
            return Promise.resolve(hub.call('store_buy', ware.itemid, hero.charid, qty)).then(function (answer) {
                if (answer[2]) hub_show(answer[2]);
                box.show(rows());
                hub_say(answer[0] || answer[1]);
            });
        };
        if (!hub_stacks(item) || Math.floor(team.gold / ware.price) <= 1) {
            return send(1);
        }
        return new QuantityDialog({title: 'Buy ' + item.name, prompt: 'How many for ' + hero.name + '?', min: 1, max: 99,
            price: ware.price, gold: team.gold, parent: hub.options.stage, frame: hub.options.frame,
            on_confirm: send}).open();
    });
}

// What the party could sell (the server's list and prices); resolves
// (with null) when the list is closed.
function hub_hawk(parts) {
    return new Promise(function (resolve) {
        var box = hub_list_window({
            label: 'Sell',
            on_choose: function (good) { hub_sell(box, good); },
            on_close: function () { resolve(null); },
        });
        box.reload = function () {
            return Promise.resolve(hub.call('store_goods')).then(function (answer) {
                box.show((answer[0] || []).map(function (good) {
                    var item = items[good.itemid] || {};
                    return {value: good, pic: item.menu_pic ? (hub.options.item_images || '') + item.menu_pic : null,
                        cells: [good.hero + ': ' + good.name + (good.qty > 1 ? ' \u00d7' + good.qty : ''), Math.round(good.each) + ' g'],
                        desc: good.qty > 1 ? Math.round(good.each) + ' gold each.' : (item.description || '')};
                }), 'Nothing to sell.');
                hub_say(hub_gold_line());
            });
        };
        box.reload();
    });
}

function hub_sell(box, good) {
    var send = function (qty) {
        return Promise.resolve(hub.call('store_sell', good.charid, good.index, qty)).then(function (answer) {
            if (answer[2]) hub_show(answer[2]);
            return box.reload().then(function () { hub_say(answer[0] || answer[1]); });
        });
    };
    if (good.qty > 1) {
        return new QuantityDialog({title: 'Sell ' + good.name, prompt: 'How many?', min: 1, max: good.qty, value: good.qty,
            price: good.each, parent: hub.options.stage, frame: hub.options.frame, on_confirm: send}).open();
    }
    return hub_pick('Sell ' + good.hero + "'s " + good.name + ' for ' + Math.round(good.each) + ' gold?', [
        {value: true, cells: ['Yes, sell it', '']},
        {value: false, cells: ['No, keep it', '']},
    ], function (yes) { if (yes) send(1); });
}

// --- team management ---

// Heroes whose job or look is no longer offered have to pick new ones
// (TeamManagement): said on the status line whenever the party is drawn.
function hub_say_needs(state) {
    var names = Object.keys(state.needs || {});
    if (names.length === 0) return;
    hub_say(names.map(function (name) {
        return name + ' needs a new ' + state.needs[name].join(' and ');
    }).join('; ') + ' \u2014 see Manage team.');
}

// Every hero in the party; picking one offers a new job or a new look
// (when they may have one). Stays open, redrawn, until cancelled.
function hub_manage() {
    var frame = (hub.options.frame || dialog_frame)({title: 'Manage team', parent: hub.options.stage, width: 580, height: 420});
    var view = new SelectionListDomView(frame.body, [{align: 'left'}, {align: 'right', width: 260}], 6);
    var box = {element: frame.element, options: null};
    var close = function () {
        dialog_close(box);
        frame.remove();
    };
    var list = new SelectionList({
        page_size: 6,
        on_choose: function (row) { hub_manage_hero(box, row.value); },
        on_cancel: close,
    });
    view.bind(list);
    box.list = list;
    box.handle_key = function (key) { return list.handle_key(key); };
    box.cancel = function () { list.cancel(); };
    box.show = function (options) {
        box.options = options;
        var at = list.highlighted !== null ? list.position + list.highlighted : (list.chosen || 0);
        list.set_rows(options.heroes.map(function (hero) {
            var trouble = !hero.job_ok || !hero.look_ok;
            return {value: hero, pic: hero.picture || null, cells: [hero.name, 'Lv ' + hero.level + ' ' + hero.job],
                colors: [null, trouble ? 'red' : null],
                desc: trouble ? 'Needs a new ' + [!hero.job_ok ? 'job' : null, !hero.look_ok ? 'look' : null].filter(Boolean).join(' and ') + '.'
                    : 'Looks like ' + hero.look + '.'};
        }));
        at = Math.min(at, options.heroes.length - 1);
        while (list.position + list.page_size <= at) list.next_page();
        list.highlight(at - list.position);
    };
    frame.element.setAttribute('role', 'dialog');
    frame.element.setAttribute('aria-label', 'Manage team');
    frame.show();
    dialog_open(box);
    hub.manage = box;
    return Promise.resolve(hub.call('manage_options')).then(function (answer) {
        if (!answer[0]) {
            close();
            hub_say('Pick a team first.');
            return;
        }
        box.show(answer[0]);
    });
}

function hub_manage_hero(box, hero) {
    var job_desc = !hero.job_ok ? 'Their job is no longer offered: pick a new one, and keep everything.'
        : (hero.level <= 1 ? 'Reach level 2 before changing jobs.'
            : 'Back to level 1, keeping ' + hero.kept + '% of their stats.');
    return hub_pick(hero.name + ': what changes?', [
        {value: 'job', cells: ['New job', hero.job], selectable: hero.can_change_job, desc: job_desc},
        {value: 'look', cells: ['New look', hero.look], selectable: hero.can_change_look,
            desc: hero.can_change_look ? 'Their look is no longer offered: pick a new one.' : 'Looks can only change when theirs is no longer offered.'},
    ], function (what) {
        if (what === 'job') return hub_manage_job(box, hero);
        return hub_manage_look(box, hero);
    });
}

function hub_manage_job(box, hero) {
    return hub_pick('A new job for ' + hero.name, box.options.jobs.map(function (job) {
        var same = job[1] === hero.job;
        return {value: job, cells: [job[1], same ? 'now' : ''], selectable: !same};
    }), function (job) {
        var send = function () { return hub_manage_send(box, hero.charid, 'job', job[0]); };
        if (hero.kept >= 100) return send();
        return hub_pick('Change ' + hero.name + "'s job to " + job[1] + '?', [
            {value: true, cells: ['Yes', ''], desc: 'Back to level 1, keeping ' + hero.kept + '% of their stats.'},
            {value: false, cells: ['No', '']},
        ], function (yes) { if (yes) send(); });
    });
}

function hub_manage_look(box, hero) {
    return hub_pick('A new look for ' + hero.name, box.options.looks.map(function (look) {
        return {value: look, pic: look[2], cells: [look[1], '']};
    }), function (look) { hub_manage_send(box, hero.charid, 'look', look[0]); });
}

function hub_manage_send(box, charid, what, id) {
    return Promise.resolve(hub.call('change_hero', charid, what, id)).then(function (answer) {
        if (answer[3]) hub_show(answer[3]);
        if (answer[2]) box.show(answer[2]);
        hub_say(answer[0] || answer[1]);
    });
}

// --- alliance ---

// Tokens, the alliance (if any), and what tokens can buy now
// (AllianceScreen). Stays open, redrawn, until cancelled.
function hub_alliance() {
    var frame = (hub.options.frame || dialog_frame)({title: 'Alliance', parent: hub.options.stage, width: 560, height: 360});
    var header = dialog_element('div', 'dialogdesc');
    frame.body.appendChild(header);
    var view = new SelectionListDomView(frame.body, [{align: 'left'}], 4);
    var box = {element: frame.element, view: null};
    var close = function () {
        dialog_close(box);
        frame.remove();
    };
    var list = new SelectionList({
        page_size: 4,
        on_choose: function (row) {
            var choice = row.value;
            if (choice.action === 'form') return hub_alliance_form(box);
            return hub_alliance_send(box, choice.action, choice.teamid === null ? [] : [choice.teamid]);
        },
        on_cancel: close,
    });
    view.bind(list);
    box.list = list;
    box.handle_key = function (key) { return list.handle_key(key); };
    box.cancel = function () { list.cancel(); };
    box.show = function (data) {
        box.view = data;
        header.textContent = 'Tokens: ' + data.balance + (data.status ? '. ' + data.status : '') + (data.hint ? ' ' + data.hint : '');
        list.set_rows(data.choices.map(function (choice) {
            return {value: choice, cells: [choice.label], desc: choice.desc};
        }));
        if (data.choices.length > 0) list.highlight(0);
        else view.set_description('Nothing to buy right now.');
    };
    frame.element.setAttribute('role', 'dialog');
    frame.element.setAttribute('aria-label', 'Alliance');
    frame.show();
    dialog_open(box);
    hub.alliance = box;
    return Promise.resolve(hub.call('alliance_view')).then(function (answer) {
        if (!answer[0]) {
            close();
            hub_say('Pick a team first.');
            return;
        }
        box.show(answer[0]);
    });
}

// Which teams to bring: each row toggles one (up to the most that can
// join), then the last row forms the alliance.
function hub_alliance_form(box) {
    var data = box.view;
    var picked = [];
    var shown = Math.min(4, data.formable.length + 1);
    var frame = (hub.options.frame || dialog_frame)({title: 'Bring which teams?', parent: hub.options.stage, width: 480, height: 116 + shown * 46});
    var view = new SelectionListDomView(frame.body, [{align: 'left'}, {align: 'right', width: 60}], 4);
    var chooser = {element: frame.element};
    var close = function () {
        dialog_close(chooser);
        frame.remove();
    };
    var list = new SelectionList({
        page_size: 4,
        on_choose: function (row, index) {
            if (row.value === 'form') {
                close();
                return hub_alliance_send(box, 'form', picked.slice());
            }
            var at = picked.indexOf(row.value);
            if (at === -1) picked.push(row.value); else picked.splice(at, 1);
            draw(index); // (the list lets go of its highlight when choosing)
        },
        on_cancel: close,
    });
    var draw = function (at) {
        var full = picked.length >= data.max_join;
        var rows = data.formable.map(function (team) {
            var on = picked.indexOf(team[0]) !== -1;
            return {value: team[0], cells: [team[1], on ? '\u2713' : ''], selectable: on || !full,
                desc: on ? 'Coming along. Pick again to leave them.' : (full ? 'Up to ' + data.max_join + ' can join.' : 'Bring them along.')};
        });
        rows.push({value: 'form', cells: ['Form the alliance', picked.length + ' token' + (picked.length === 1 ? '' : 's')],
            selectable: picked.length > 0, desc: picked.length > 0 ? 'For ' + data.minutes + ' minutes of fighting.' : 'Pick at least one team.'});
        list.set_rows(rows);
        while (list.position + list.page_size <= at) list.next_page();
        list.highlight(at - list.position);
    };
    view.bind(list);
    chooser.handle_key = function (key) { return list.handle_key(key); };
    chooser.cancel = function () { list.cancel(); };
    chooser.list = list;
    frame.element.setAttribute('role', 'dialog');
    frame.element.setAttribute('aria-label', 'Bring which teams?');
    frame.show();
    dialog_open(chooser);
    draw(0);
    return chooser;
}

function hub_alliance_send(box, action, teamids) {
    return Promise.resolve(hub.call('alliance_act', action, teamids)).then(function (answer) {
        if (answer[3]) hub_show(answer[3]);
        if (answer[2]) box.show(answer[2]);
        hub_say(answer[0] || answer[1]);
    });
}
