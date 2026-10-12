var sequence_number = 0;

function commit_commands() {
    // A spectator (or replay viewer) owns no team and must never commit
    // -- not even if the timer box reaches zero. The server refuses it
    // anyway; this keeps the client from trying.
    if (null === teamid) return;
    //Loop through heroes in player party.  If they are in one of the
    //player's own teams (several with an alliance), resubmit command.
    var group, character, hero, teamplayers = [];
    if (teamid !== -1) {
        var party_teams = get_party(player_party).teams;
        (typeof teamids !== 'undefined' ? teamids : [teamid]).forEach(function (id) {
            teamplayers = teamplayers.concat(party_teams[id] || []);
        });
    }
    for (group = 0; group < get_party_length(player_party); group++)
        for (character = 0; character < get_group_length(player_party, group); character++) {
            hero = get_hero(player_party, group, character);
            if (teamid === -1 || in_array(hero.charid, teamplayers))
                data_request('change_character_command', player_party, group, character, [hero.command, hero.target, hero.using], sequence_number);
        }

    //Unhighlight current hero, if any
    if (curr_hero) {
        var old_hero = curr_hero;
        curr_hero = null;
        unhighlight_hero(player_party, old_hero[0], old_hero[1]);
    }

    slide_in(player_slide.oldgroup);
    set_menu_state(MENU_STATE_FIGHT_WAIT);
    data_request('process_fight_commands', sequence_number);
    //document.commit.command_data.value=collect_hero_commands();
    //document.commit.submit();
    return false;
}

function serialize_command(hero) {
    var retval = hero.command.toString();
    retval += ',';
    retval += hero.using.toString();
    retval += ',';
    retval += hero.target[0].toString();
    retval += ',';
    retval += hero.target[1].toString();
    retval += ',';
    retval += hero.target[2].toString();
    return retval;
}

function collect_hero_commands() {
    var retval = [];
    var group;
    var individual;
    for (group = 0; group < get_party_length(player_party); group++)
        for (individual = 0; individual < get_group_length(player_party, group); individual++) {
            answer = serialize_command(get_hero(player_party, group, individual));
            retval.push(answer);
        }
    return retval.join(';');
}

function relay_player_commands(party, group, character) {
    var hero = get_hero(party, group, character);
    data_request('change_character_command', party, group, character, [hero.command, hero.target, hero.using], sequence_number);
}

// While a player waits -- in the command menu after acknowledging
// playback, or after committing -- check every 5 seconds whether the
// round has advanced or timed out, refreshing the timer each time. One
// loop only: a new call replaces any check already scheduled, and
// stop_fight_timeout_checks() ends it when a new round arrives.
var fight_timeout_check = null;

function wait_for_fight_timeout(timeout) {
    if (timeout != null)
        update_timerbox(timeout);
    stop_fight_timeout_checks();
    fight_timeout_check = setTimeout(check_fight_timeout, 5000);
}

function stop_fight_timeout_checks() {
    if (fight_timeout_check)
        clearTimeout(fight_timeout_check);
    fight_timeout_check = null;
}

function check_fight_timeout() {
    fight_timeout_check = null;
    data_request('check_fight_timeout', sequence_number);
}
