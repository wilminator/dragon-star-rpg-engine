var postfight = null;
var fight = null;
var combat_playback = null;
// Set by receive_fight_data()'s own redirect argument, checked once
// playback finishes in combat_callback() -- replaces the client-side
// test_own_party_dead()/test_other_parties_dead() checks and the
// separate finish_fight() round trip they used to trigger. The server
// already knows, as of the response this came with, whether the
// fight is over (or this session was never authorized to watch it
// live at all) and exactly where to send this session next.
var pending_redirect = null;
// The cumulative set of ability/item ids known to be in play for
// this fight, as of the last resource manifest received -- see
// receive_resource_manifest()'s own comment for why this exists and
// why it accumulates rather than replaces on each call.
var known_ability_ids = [];
var known_item_ids = [];
//var fight_screen=null;

//Must implement, pull out client data from fight
var fight_client_data = null;

// Called once at fight start (see receive_fight_data()'s own
// postfight==null check), and meant to be called again whenever new
// resources enter play mid-fight (a NewParty message, once that
// exists) -- see FIGHT::collect_resource_ids()'s own docblock for
// why the manifest exists at all: character.abilities/.inventory,
// which cache_fight_sounds()/preload_hero_images() used to read
// directly, are withheld from any non-own-team character for every
// viewer, and from EVERY character for a spectator specifically
// (client_teamid forced to -1 matches no real team). Accumulates
// into known_ability_ids/known_item_ids rather than replacing them,
// since a later manifest (after a party joins) describes everything
// currently in play, and re-caching an id already loaded is harmless
// even if not maximally efficient -- simplicity over that
// optimization here, since this only runs rarely (fight start, and
// otherwise only on an actual mid-fight roster change).
function receive_resource_manifest(ability_ids, item_ids) {
    var index;
    for (index in ability_ids)
        if (known_ability_ids.indexOf(ability_ids[index]) === -1)
            known_ability_ids.push(ability_ids[index]);
    for (index in item_ids)
        if (known_item_ids.indexOf(item_ids[index]) === -1)
            known_item_ids.push(item_ids[index]);
    verify_cache();
}

function show_fight_message_by_hero(party, group, character) {
    var hero = get_hero(party, group, character);
    var y = object_get_y(hero.html.pic) + (get_picture_y(hero.html.pic) - 25) / 2;
    var x = hero.x_offset + (hero.x_offset < 400 ? get_picture_x(hero.html.pic) : -200);
    show_fight_message(x, y);
}

function present_group(party, group) {
    hero_slide_center(party, group);
    return 0;
}

function number_bounce(party, group, character, value, color) {
    if (typeof settings_hidden === 'function' && settings_hidden('hide_hp_popups')) return 0; // the player's setting
    object = get_hero(party, group, character).html.pic;
    x = object_get_x(object) + get_picture_x(object) / 2;
    y = object_get_y(object) + get_picture_y(object) / 2;
    if (party == player_party)
        y -= 24;
    //make_damage_fly(x,y,value,color);
    make_damage_fly(object, value, color);
    return 0;
}

function blood_splat(party, group, character, value, multiplier) {
    if (typeof settings_hidden === 'function' && settings_hidden('hide_gore')) return 0; // the player's setting
    object = get_hero(party, group, character).html.pic;
    x = object_get_x(object) + get_picture_x(object) / 2;
    y = object_get_y(object) + get_picture_y(object) / 2;
    //make_blood_fly(x,y,Math.ceil(Math.log(value)/Math.log(10))*multiplier);
    make_blood_fly(object, Math.ceil(Math.log(value) / Math.log(10)) * multiplier);
    return 0;
}

function alter_stat(party, group, character, stat, value) {
    var object = get_hero(party, group, character).current;
    var variable = "object." + stat;
    eval(variable + "+=" + value.toString() + ";");
    eval("if(" + variable + "<0) " + variable + "=0;");
    if (stat != 'HP') update_stats(party, group, character);
    return 0;
}

function alter_hp_bar(party, group, character, value) {
    var object = get_hero(party, group, character);
    object.HPDT += value;
    if (object.HPDT < 0) object.HPDT = 0;
    init_update_hp(party, group, character);
    return object.HPDT;
}

function alter_mp_bar(party, group, character, value) {
    var object = get_hero(party, group, character);
    object.MPDT += value;
    if (object.MPDT < 0) object.MPDT = 0;
    init_update_mp(party, group, character);
    return 0;
}

//function consume_item(party,group,character,item,cleanup)
function consume_item(party, group, character, item) {
    var hero = get_hero(party, group, character);
    var thing = hero.inventory[item]
    thing.qty--;
    //if(thing.qty==0 && cleanup)
    if (thing.qty == 0) {
        hero.inventory.splice(item, 1);
        hero.command = 6;
    }
    display_action(group, character);
    return 0;
}

function equip_item(party, group, character, item, location) {
    var hero = get_hero(party, group, character);
    hero.equipment[location] = item;
    return 0;
}

function unequip_item(party, group, character, location) {
    var hero = get_hero(party, group, character);
    hero.equipment[location] = null;
    return 0;
}

function init_fight() {
    //request our fight data so we can get setup.
    set_loader_message('Loading fight data...');
    set_loader_percentage(0);
    object_show(object_get('loader'));
    object_hide(fight_screen);
    set_menu_state(MENU_STATE_FIGHT_WAIT);

    //Create a timerbox. Spectators see it too; it just never triggers
    //anything for them (see commit_commands()).
    if (null !== teamid || is_spectator()) {
        object_create_timerbox(fight_screen);
    }

    //Init postfight.
    postfight = null;

    //Start the fight by getting the data.
    request_fight_data();
    return true;
}

function request_fight_data() {
    if (is_spectator()) {
        data_request('request_spectator_data', spectate_guid, player_party, spectate_team);
        return;
    }
    console.log(`Requesting fight data ${fightid}`);
    data_request('request_fight_data', fightid);
}

// --- spectating ---
// A spectator page is rendered with spectate_guid set (see
// html_combat()). A spectator owns no team (teamid is null), never
// commits, and never asks the server to process a timed-out round.
// After each round plays back, it polls read-only for the next one.

var SPECTATOR_POLL_MS = 3000;

function is_spectator() {
    return !!window.spectate_guid;
}

function schedule_spectator_poll() {
    setTimeout(poll_spectator, SPECTATOR_POLL_MS);
}

function poll_spectator() {
    data_request('poll_spectator_data', spectate_guid, player_party, sequence_number, spectate_team);
}

// Nothing new yet: refresh the timer and ask again shortly. A new round
// arrives as an ordinary receive_fight_data() instead.
function receive_spectator_wait(sequence, timeout) {
    update_timerbox(timeout);
    schedule_spectator_poll();
}

// The fight is over (and its last round has already played), or it
// can't be watched any more: leave for the page the server named.
function receive_spectator_end(redirect) {
    pending_redirect = redirect;
    fight_terminate();
}

// Not allowed to watch this fight from this party (or it no longer
// exists). Same exit as receive_spectator_end().
function spectator_access_denied(redirect) {
    receive_spectator_end(redirect);
}

function receive_fight_data(sequence, pre_fight, actions, post_fight, timeout, redirect, round_log) {
    // A new round is here: no more checking for the old one while it
    // plays back. Acknowledging this round restarts the checks.
    stop_fight_timeout_checks();
    var is_first_call = (postfight == null);
    if (is_first_call) {
        //Music!
        play_music(post_fight.music);
        //Background!
        fight_screen.style.backgroundImage = 'url(' + background_images + post_fight.background + ')';
    }

    set_loader_percentage(50);
    sequence_number = sequence;
    synchronize_fight_data(pre_fight);
    //data_request('client_error','Bad party',{fight_data:fight});
    postfight = post_fight;
    show_human_control_notice(human_control_notice(post_fight.human_controlled, player_party));
    //!!TEST!! No conversion done! Convert now!
    combat_playback = convert_action_list_to_playlist(actions);
    // The combat log's heading as the round starts to play; each turn's
    // lines follow with its animation (combat_log_turn entries).
    if (typeof combat_log_round === 'function') {
        combat_playback.unshift([0, 'combat_log_round', sequence, round_log || []]);
    }
    pending_redirect = redirect;
    set_loader_percentage(100);
    set_menu_state(MENU_STATE_FIGHT_NONE);
    update_timerbox(timeout);

    if (is_first_call) {
        // Wait for the resource manifest before running the initial
        // preload+verify+begin cascade, rather than running it here
        // and again once the manifest arrives -- see
        // receive_resource_manifest()'s own comment. Requested here
        // (not before this point), since it's a real RPC call and
        // there's otherwise nothing gained by queuing it earlier.
        // verify_cache()'s own cascade (which begin_fight() sits at
        // the end of) only starts once receive_resource_manifest()
        // triggers it -- calling it here too, before the manifest
        // response can possibly have arrived, risked verify_cache()
        // deciding everything was already loaded from a smaller,
        // incomplete set and starting the fight before the
        // manifest-driven assets were even queued, let alone loaded.
        if (is_spectator()) {
            data_request('request_spectator_resource_manifest', spectate_guid, player_party, spectate_team);
        } else {
            data_request('request_resource_manifest', fightid);
        }
    } else {
        //Proceed with standard init code
        verify_cache();
    }
}

function verify_cache() {
    // Replaces the old three-stage setTimeout polling chain
    // (this used to be the first of three: verify_cache ->
    // verify_fight_html -> verify_audio_precache -> begin_fight, each
    // re-checking itself every 100ms) with a single Promise.all() --
    // everything preloads in parallel rather than being checked in
    // three sequential passes, and nothing needs re-polling to find
    // out when it's done. No load bar: with today's connection speeds
    // this resolves fast enough that a progress UI isn't needed (see
    // set_loader_percentage/set_loader_message -- neither has a
    // caller left after this).
    //
    // is_fight_html_loaded()'s own separate wait (for the DOM <img>
    // elements begin_fight()/fight_layout() create) is dropped
    // entirely, not ported: those are the exact same URLs already
    // waited for below, so the browser serves them from cache the
    // instant those DOM elements are created -- there's nothing left
    // to wait for by the time begin_fight() runs.
    var promises = [].concat(
        preload_hero_images(),
        cache_fight_sounds(),
        push_image_array(static_images),
        cache_images()
    );
    Promise.all(promises).then(function(results) {
        // Bundled into one report, not one call per failure -- see
        // report_load_anomalies()'s own comment for why, and for
        // what a "later" quality-feedback anomaly report is meant to
        // look like more generally.
        var failures = results.filter(function(r) { return r && r.success === false; });
        if (failures.length > 0) {
            report_load_anomalies(failures);
        }
        begin_fight();
    });
}

function report_load_anomalies(failures) {
    // The first real caller of call_server() -- deliberately built on
    // the new, non-queued transport (see rpc.js's own comment) rather
    // than proxy.js's data_request('client_error', ...), which this
    // is a sibling of, not a replacement for: client_error is for a
    // detected data/logic problem, one at a time, as it happens;
    // this is telemetry, and bundled on purpose -- a fight's worth of
    // failed loads (settled all together by the same Promise.all()
    // above) becomes one report, not N separate ones. Not awaited:
    // begin_fight() proceeds regardless of whether the report itself
    // succeeds, since losing a quality-feedback signal is not a
    // reason to hold up the fight the player is actually here for.
    // A spectator's reports name the fight they're watching.
    call_server('report_anomalies', failures.map(function(f) {
        return { type: 'resource_load_failed', kind: f.kind, url: f.url };
    }), window.spectate_guid || null).catch(function(error) {
        console.error('report_load_anomalies failed:', error);
    });
}

function begin_fight() {
    //Cleanup/reset html
    fight_layout();
    //setup hero slide
    player_slide.oldparty = player_party;
    hero_center(player_slide.oldparty, player_slide.oldgroup);

    //setup monster slide
    //later we will pick an enemy; for now pick someone not us.
    opponent_slide.oldparty = (player_party == 0 ? 1 : 0);
    hero_center(opponent_slide.oldparty, opponent_slide.oldgroup);

    //Now show things.
    object_show(fight_screen);
    object_hide(object_get('loader'));
    //Action!
    set_playback_queue(combat_playback, combat_callback);
}

function combat_callback() {
    //At this point, adopt the postfight as our real data.
    synchronize_fight_data(postfight);
    //Cleanup/reset html
    fight_layout();

    // Any combat log results still waiting go in now.
    if (typeof combat_log_flush === 'function') {
        combat_log_flush();
    }
    after_round_played();
}

function after_round_played() {
    // The server already knows, as of the last receive_fight_data()
    // response, whether this fight is over or this session was never
    // authorized to watch it live at all, and exactly where to send
    // it next -- no client-side death check, and no separate
    // finish_fight() round trip to ask, needed at all.
    //
    // No artificial pause here: that is set_playback_queue's
    // own completion callback, so by the time it fires, the whole
    // queue -- including a FightDialog entry's promise_event/[1]
    // barrier wait -- has already drained. The player has already
    // seen the results and dismissed the dialog before this point is
    // ever reached; terminating immediately is correct, not abrupt.
    if (pending_redirect) {
        fight_terminate();
        return;
    }

    if (is_spectator()) {
        schedule_spectator_poll();
        return;
    }

    if (null !== teamid) {
        // Tell the server this round has fully played back here (round
        // 0: the fight has loaded). Once every player has, the deadline
        // drops to command-entry time. The answer restarts the timeout
        // checks, which keep this player's timer current while they
        // enter commands.
        data_request('ack_playback', sequence_number);
        slide_out(player_slide.oldgroup);
        set_menu_state(MENU_STATE_FIGHT_PLAYER);
        if (typeof combat_keys_say === 'function') {
            combat_keys_say('Your turn: choose commands. Arrow keys choose a hero, Enter their action, C commits.');
        }
    }
}

// The fight is over for this page. $redirect: where to go, when the
// server names it with the call (a fight that's gone); otherwise where
// the last round said. Inside the hub (app.php shows this page in a
// frame), the hub takes over instead of the page leaving.
function fight_terminate(redirect) {
    if (redirect) {
        pending_redirect = redirect;
    }
    stop_music();
    kill_tranceiver();
    var hub_window = fight_hub_window();
    if (hub_window) {
        hub_window.hub_fight_over(pending_redirect);
        return;
    }
    window.location = pending_redirect;
}

// The hub's window when this page is its fight frame, else null.
function fight_hub_window() {
    try {
        if (window.parent && window.parent !== window && typeof window.parent.hub_fight_over === 'function') {
            return window.parent;
        }
    } catch (e) {
        // another site framing this page: not the hub
    }
    return null;
}

// What to tell this player about other people controlling the parties
// they're fighting: text, or null for nothing. human_controlled: the
// party indexes a player controls right now (from the server).
function human_control_notice(human_controlled, own_party) {
    var others = (human_controlled || []).filter(function (index) { return index !== own_party; });
    return others.length > 0 ? 'A player is controlling the monsters.' : null;
}

// Shows (or hides) that notice at the top of the fight screen.
function show_human_control_notice(text) {
    if (typeof document === 'undefined' || !fight_screen || !fight_screen.appendChild) return;
    var notice = document.getElementById('human_control_notice');
    if (!notice) {
        if (text === null) return;
        notice = document.createElement('div');
        notice.id = 'human_control_notice';
        notice.style.cssText = 'position:absolute;top:4px;left:50%;transform:translateX(-50%);z-index:50;'
            + 'background:rgba(0,0,64,0.85);color:#ff8;border:2px solid #33f;padding:2px 10px;font-weight:bold;';
        fight_screen.appendChild(notice);
    }
    notice.textContent = text || '';
    notice.style.display = text === null ? 'none' : 'block';
}
