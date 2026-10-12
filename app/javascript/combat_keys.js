// The fight screen from the keyboard (WCAG 2.1.1): the base of the dialog
// stack while a fight is on, so it has the keys whenever no list or
// equip window is open over it (those take their own keys, dialogs.js).
//
//   choosing a hero   Left / Right: the previous / next of your heroes who
//                     can act; Up / Down: the first of them in the group
//                     above / below; Enter or Space: their action menu
//   action menu       the arrows move over the icons shown (4 by 2);
//                     Enter or Space picks one; Escape goes back
//   choosing a target Left / Right: the next fighter in the group (only
//                     living ones, unless the action can reach the fallen);
//                     Up / Down: the next group, the enemy's first, then
//                     your own; Enter or Space: that's the target;
//                     Escape cancels
//   C                 commits your commands (the Commit button)
//
// What the keys land on is said to screen readers: the hero and their HP,
// the action, the target, and when commands are sent (combat_keys_say()).
//
// Needs dialogs.js (dialog_set_base), and the fight's own functions:
// select_hero(), action_menu_selection(), target_cancel(), menu_state.

var COMBAT_ACTION_ICONS = 8;
var COMBAT_ACTION_COLUMNS = 4;

var combat_keys = {
    action_highlight: null, // the action menu icon the keys are on
    target: null,           // [party, group, character] the target cursor is on

    handle_key: function (key) {
        if (typeof menu_state === 'undefined') return false;
        // A focused button (Settings, say) presses itself.
        var focused = typeof document !== 'undefined' ? document.activeElement : null;
        if ((key === 'Enter' || key === ' ') && focused && focused.tagName === 'BUTTON') return false;
        switch (menu_state) {
            case MENU_STATE_FIGHT_PLAYER: return combat_keys_player(key);
            case MENU_STATE_FIGHT_ACTION: return combat_keys_action(key);
            case MENU_STATE_FIGHT_TARGET: return combat_keys_target(key);
        }
        return false;
    },
};

// --- saying it (screen readers) ---

function combat_keys_say(text) {
    if (typeof document === 'undefined') return;
    var region = document.getElementById('combatannounce');
    if (!region) {
        region = document.createElement('div');
        region.id = 'combatannounce';
        region.className = 'srstatus';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
    }
    region.textContent = text;
}

function combat_keys_describe(party, group, character) {
    var fighter = get_hero(party, group, character);
    if (!fighter) return '';
    var hp = fighter.current ? fighter.current.HP : null;
    var text = fighter.name || 'Someone';
    if (hp !== null && hp !== undefined) {
        text += hp > 0 ? ', ' + hp + (fighter.base ? ' of ' + fighter.base.HP : '') + ' HP' : ', fallen';
    }
    return text;
}

// [group, character] of each of this player's heroes who can act now: in
// their party, on one of their teams (all of it for a monster player),
// and alive. In screen order.
function combat_keys_heroes() {
    var heroes = [];
    if (typeof teamid === 'undefined' || teamid === null) return heroes; // a spectator
    var own = null;
    if (teamid !== -1) {
        own = [];
        var party_teams = get_party(player_party).teams || {};
        (typeof teamids !== 'undefined' ? teamids : [teamid]).forEach(function (id) {
            own = own.concat(party_teams[id] || []);
        });
    }
    for (var group = 0; group < get_party_length(player_party); group++) {
        for (var character = 0; character < get_group_length(player_party, group); character++) {
            var hero = get_hero(player_party, group, character);
            if (!hero || !hero.current || hero.current.HP <= 0) continue;
            if (own !== null && own.indexOf(hero.charid) === -1) continue;
            heroes.push([group, character]);
        }
    }
    return heroes;
}

function combat_keys_player(key) {
    if (key === 'c' || key === 'C') {
        // Commit, as the button does.
        if (typeof commit_commands === 'function') {
            commit_commands();
            combat_keys_say('Commands sent. Waiting for the round.');
        }
        return true;
    }
    var heroes = combat_keys_heroes();
    if (heroes.length === 0) return false;
    var at = -1;
    if (typeof curr_hero !== 'undefined' && curr_hero) {
        for (var i = 0; i < heroes.length; i++) {
            if (heroes[i][0] === curr_hero[0] && heroes[i][1] === curr_hero[1]) at = i;
        }
    }
    var next = null;
    switch (key) {
        case 'ArrowRight':
            next = at === -1 ? 0 : (at + 1) % heroes.length;
            break;
        case 'ArrowLeft':
            next = at === -1 ? heroes.length - 1 : (at + heroes.length - 1) % heroes.length;
            break;
        case 'ArrowDown':
        case 'ArrowUp': {
            // The first of them in the next group that has any, round.
            var group = at === -1 ? -1 : heroes[at][0];
            var groups = [];
            heroes.forEach(function (h) { if (groups.indexOf(h[0]) === -1) groups.push(h[0]); });
            var g = groups.indexOf(group);
            var target = key === 'ArrowDown'
                ? groups[(g + 1) % groups.length]
                : groups[g <= 0 ? groups.length - 1 : g - 1];
            for (var j = 0; j < heroes.length && next === null; j++) {
                if (heroes[j][0] === target) next = j;
            }
            break;
        }
        case 'Enter':
        case ' ':
            if (at === -1) {
                select_hero(player_party, heroes[0][0], heroes[0][1]); // choose one first
            }
            // Choosing the chosen hero again opens their action menu.
            select_hero(player_party, curr_hero[0], curr_hero[1]);
            combat_keys.action_highlight = null;
            if (menu_state === MENU_STATE_FIGHT_ACTION) combat_keys_action_move(null);
            return true;
        default:
            return false;
    }
    if (next === null) return false;
    // Already on them (the only one who can act): stay, rather than
    // choosing them again, which would open their action menu.
    if (next !== at) select_hero(player_party, heroes[next][0], heroes[next][1]);
    combat_keys_say(combat_keys_describe(player_party, heroes[next][0], heroes[next][1])
        + (heroes.length > 1 ? '. ' + (next + 1) + ' of ' + heroes.length : ''));
    return true;
}

// The action menu's icons that are showing, by position (0-7).
function combat_keys_action_shown(index) {
    var icon = object_get('action' + index);
    return !!icon && icon.style.visibility !== 'hidden';
}

// Moves the highlight: $step = +/-1 (across) or +/-4 (up and down), or
// null for the first icon showing.
function combat_keys_action_move(step) {
    var from = combat_keys.action_highlight;
    var to = null;
    if (step === null || from === null) {
        for (var i = 0; i < COMBAT_ACTION_ICONS && to === null; i++) {
            if (combat_keys_action_shown(i)) to = i;
        }
    } else {
        // Along the row or column, skipping hidden icons, wrapping round.
        var candidate = from;
        for (var tries = 0; tries < COMBAT_ACTION_ICONS; tries++) {
            if (Math.abs(step) === 1) {
                var row = Math.floor(candidate / COMBAT_ACTION_COLUMNS) * COMBAT_ACTION_COLUMNS;
                candidate = row + (candidate - row + step + COMBAT_ACTION_COLUMNS) % COMBAT_ACTION_COLUMNS;
            } else {
                candidate = (candidate + step + COMBAT_ACTION_ICONS) % COMBAT_ACTION_ICONS;
            }
            if (candidate === from) break;
            if (combat_keys_action_shown(candidate)) {
                to = candidate;
                break;
            }
        }
        if (to === null) to = from;
    }
    if (from !== null && from !== to && object_get('action' + from)) {
        unhighlight_action_menu_icon(object_get('action' + from));
    }
    combat_keys.action_highlight = to;
    if (to !== null) {
        var icon = object_get('action' + to);
        highlight_action_menu_icon(icon);
        combat_keys_say(icon.getAttribute ? (icon.getAttribute('title') || icon.getAttribute('alt') || '') : '');
    }
    return to;
}

function combat_keys_action(key) {
    if (combat_keys.action_highlight === null) combat_keys_action_move(null);
    switch (key) {
        case 'ArrowRight': combat_keys_action_move(1); return true;
        case 'ArrowLeft': combat_keys_action_move(-1); return true;
        case 'ArrowDown': combat_keys_action_move(COMBAT_ACTION_COLUMNS); return true;
        case 'ArrowUp': combat_keys_action_move(-COMBAT_ACTION_COLUMNS); return true;
        case 'Enter':
        case ' ': {
            var choice = combat_keys.action_highlight;
            if (choice === null) return true;
            unhighlight_action_menu_icon(object_get('action' + choice));
            combat_keys.action_highlight = null;
            action_menu_selection(choice);
            return true;
        }
        case 'Escape':
            if (combat_keys.action_highlight !== null) {
                unhighlight_action_menu_icon(object_get('action' + combat_keys.action_highlight));
            }
            combat_keys.action_highlight = null;
            // Choosing the chosen hero again, as a click would: back to
            // choosing heroes.
            select_hero(player_party, curr_hero[0], curr_hero[1]);
            return true;
    }
    return false;
}

// --- choosing a target ---

// [party, group] of every group that has someone who can be targeted:
// the enemy's first, then the player's own.
function combat_keys_target_groups() {
    var groups = [];
    var parties = [];
    for (var p = 0; p < (typeof fight !== 'undefined' && fight && fight.parties ? fight.parties.length : 2); p++) {
        if (p !== player_party) parties.push(p);
    }
    parties.push(player_party);
    parties.forEach(function (party) {
        for (var group = 0; group < get_party_length(party); group++) {
            if (combat_keys_targets(party, group).length > 0) groups.push([party, group]);
        }
    });
    return groups;
}

// Who in the group can be targeted: the living, or anyone for an action
// that reaches the fallen (target_dead).
function combat_keys_targets(party, group) {
    var found = [];
    for (var c = 0; c < get_group_length(party, group); c++) {
        var fighter = get_hero(party, group, c);
        if (fighter && ((fighter.current && fighter.current.HP > 0) || (typeof target_dead !== 'undefined' && target_dead == 1))) {
            found.push(c);
        }
    }
    return found;
}

// Puts the target cursor on $to ([party, group, character]).
function combat_keys_target_move(to) {
    var from = combat_keys.target;
    if (from) unhighlight_hero_range(from[0], from[1], from[2]);
    combat_keys.target = to;
    if (!to) return;
    if (typeof hero_slide_center === 'function') hero_slide_center(to[0], to[1]); // bring the group into view
    highlight_hero_range(to[0], to[1], to[2]);
    combat_keys_say('Target: ' + combat_keys_describe(to[0], to[1], to[2]));
}

function combat_keys_target(key) {
    var groups = combat_keys_target_groups();
    if (groups.length === 0) {
        if (key === 'Escape') { target_cancel(); return true; }
        return false;
    }
    var at = combat_keys.target;
    var g = -1;
    if (at) {
        for (var i = 0; i < groups.length; i++) {
            if (groups[i][0] === at[0] && groups[i][1] === at[1]) g = i;
        }
    }
    if (g === -1 && key === 'Escape') {
        combat_keys.target = null;
        target_cancel();
        combat_keys_say('Cancelled.');
        return true;
    }
    if (g === -1) {
        // First key while targeting: start on the first enemy who can be targeted.
        var first = groups[0];
        combat_keys_target_move([first[0], first[1], combat_keys_targets(first[0], first[1])[0]]);
        if (key !== 'Enter' && key !== ' ' && key !== 'Escape') return true;
        at = combat_keys.target;
        g = 0;
    }
    var inside = combat_keys_targets(at[0], at[1]);
    var c = inside.indexOf(at[2]);
    switch (key) {
        case 'ArrowRight':
            combat_keys_target_move([at[0], at[1], inside[(c + 1) % inside.length]]);
            return true;
        case 'ArrowLeft':
            combat_keys_target_move([at[0], at[1], inside[(c + inside.length - 1) % inside.length]]);
            return true;
        case 'ArrowDown':
        case 'ArrowUp': {
            var next = groups[(g + (key === 'ArrowDown' ? 1 : groups.length - 1)) % groups.length];
            combat_keys_target_move([next[0], next[1], combat_keys_targets(next[0], next[1])[0]]);
            return true;
        }
        case 'Enter':
        case ' ': {
            var chosen = combat_keys.target;
            unhighlight_hero_range(chosen[0], chosen[1], chosen[2]);
            combat_keys.target = null;
            select_hero(chosen[0], chosen[1], chosen[2]); // sets the target, as a click does
            combat_keys_say('Target set: ' + combat_keys_describe(chosen[0], chosen[1], chosen[2]));
            return true;
        }
        case 'Escape':
            if (combat_keys.target) unhighlight_hero_range(combat_keys.target[0], combat_keys.target[1], combat_keys.target[2]);
            combat_keys.target = null;
            target_cancel();
            combat_keys_say('Cancelled.');
            return true;
    }
    return false;
}

// The fight's keys are the base of the dialog stack on the fight page.
// (Checked once the page has loaded: the fight page loads its scripts in
// no particular order.)
if (typeof window !== 'undefined' && window.addEventListener) {
    window.addEventListener('load', function () {
        if (typeof dialog_set_base === 'function' && document.getElementById('actionmenu')) {
            dialog_set_base(combat_keys);
        }
    });
}
