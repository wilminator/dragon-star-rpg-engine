// The combat log: each round in words (the server's CombatLog), each
// fighter's lines added as their action plays. Shown as a panel beside the fight when
// the player's text_log setting is on; otherwise kept visually hidden
// but still read out by screen readers (role="log").
//
//   combat_log_round(round, turns) a round starts playing: its heading
//   combat_log_turn(index)         a fighter's turn plays: what they do
//   combat_log_landed()            their hit lands: what came of it
//   combat_log_flush()             the round's played: anything left
//   combat_log_add(round, lines)   a round's heading and lines at once
//   combat_log_show(on)            shows or hides the panel
//
// A turn from the server is {act: [...], results: [...]} (CombatLog::
// turns()); the results wait for the hit so the log doesn't give the
// outcome away. No pause after the round: the lines keep pace with the
// animations, so they've been read by the time commands open.

var COMBAT_LOG_KEEP_ROUNDS = 50;

function combat_log_panel() {
    var panel = document.getElementById('combatlog');
    if (panel) return panel;
    panel = document.createElement('div');
    panel.id = 'combatlog';
    panel.className = 'combatlog';
    panel.setAttribute('role', 'log');
    panel.setAttribute('aria-live', 'polite');
    panel.setAttribute('aria-label', 'Combat log');
    document.body.appendChild(panel);
    combat_log_show(combat_log_on());
    return panel;
}

function combat_log_on() {
    return typeof settings_get === 'function' && settings_get('text_log') === true;
}

function combat_log_show(on) {
    var panel = document.getElementById('combatlog');
    if (panel) {
        // Hidden from sight only: screen readers still hear each round.
        panel.className = on ? 'combatlog' : 'combatlog combatlog-hidden';
    }
    // In the hub, the fight's frame widens to make room for it.
    try {
        if (typeof window !== 'undefined' && window.parent && window.parent !== window
            && typeof window.parent.hub_fight_resize === 'function') {
            window.parent.hub_fight_resize(on);
        }
    } catch (e) {
        // framed by another site: nothing to resize
    }
}

// A round's heading and $lines (more can follow: combat_log_lines()).
// Returns the round's block.
function combat_log_add(round, lines) {
    var panel = combat_log_panel();
    var block = document.createElement('div');
    block.className = 'combatloground';
    var title = document.createElement('h3');
    title.textContent = 'Round ' + round;
    block.appendChild(title);
    panel.appendChild(block);
    while (panel.children.length > COMBAT_LOG_KEEP_ROUNDS) {
        panel.removeChild(panel.children[0]);
    }
    combat_log_lines(block, lines || []);
    return block;
}

function combat_log_lines(block, lines) {
    for (var i = 0; i < lines.length; i++) {
        var line = document.createElement('p');
        line.textContent = lines[i];
        block.appendChild(line);
    }
    var panel = document.getElementById('combatlog');
    if (panel) panel.scrollTop = panel.scrollHeight;
}

// The round being played: its turns' lines, added as each turn plays.
var combat_log_round_number = 0;
var combat_log_round_turns = [];
var combat_log_round_block = null;
var combat_log_pending = [];   // this turn's results, until the hit lands

function combat_log_parts(turn) {
    if (!turn) return {act: [], results: []};
    if (Array.isArray(turn)) return {act: turn, results: []};
    return {act: turn.act || [], results: turn.results || []};
}

// A round starts playing: its heading goes in now.
function combat_log_round(round, turns) {
    combat_log_flush();
    combat_log_round_number = round;
    combat_log_round_turns = turns || [];
    combat_log_round_block = null;
    var says = combat_log_round_turns.some(function (turn) {
        var parts = combat_log_parts(turn);
        return parts.act.length > 0 || parts.results.length > 0;
    });
    if (says) combat_log_round_block = combat_log_add(round, []);
    return 0;
}

// Turn $index of the round starts playing: what they do goes in; what
// came of it waits for combat_log_landed().
function combat_log_turn(index) {
    combat_log_flush();
    var parts = combat_log_parts(combat_log_round_turns[index]);
    if (!combat_log_round_block) return 0;
    if (parts.act.length > 0) combat_log_lines(combat_log_round_block, parts.act);
    combat_log_pending = parts.results.slice();
    return 0;
}

// The turn's hit lands (the first damage, miss or heal shown): its results.
function combat_log_landed() {
    combat_log_flush();
    return 0;
}

// Whatever results are still waiting (a turn whose hit never showed).
function combat_log_flush() {
    if (combat_log_pending.length > 0 && combat_log_round_block) {
        combat_log_lines(combat_log_round_block, combat_log_pending);
    }
    combat_log_pending = [];
}
