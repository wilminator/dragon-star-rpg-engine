'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function freshDialogText() {
    return loadBrowserScripts(['dialog_text.js']);
}

// --- validate_tag_parts() ---
// Every tag in the documented lexicon, at correct arity (doesn't
// throw) and one off (throws). Same coverage as before, plus the
// four newly added tags: sound, music, mute, ack.

test('validate_tag_parts() accepts a correctly-shaped label tag', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['label', 'intro']));
});

test('validate_tag_parts() rejects a label tag with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['label']), /label tag requires two parts/);
    assert.throws(() => ctx.validate_tag_parts(['label', 'a', 'b']), /label tag requires two parts/);
});

test('validate_tag_parts() accepts a correctly-shaped jump tag', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['jump', 'end']));
});

test('validate_tag_parts() rejects a jump tag with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['jump']), /jump tag requires two parts/);
});

test('validate_tag_parts() accepts a correctly-shaped team tag', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['team', '5', 'skip']));
});

test('validate_tag_parts() rejects a team tag with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['team', '5']), /team tag requires three parts/);
});

test('validate_tag_parts() accepts a correctly-shaped party tag', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['party', '1', 'skip']));
});

test('validate_tag_parts() rejects a party tag with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['party', '1']), /party tag requires three parts/);
});

test('validate_tag_parts() accepts a question tag with one prompt/label pair, or several plus a trailing cancel label', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['question', 'Yes', 'yes_label']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['question', 'Yes', 'yes_label', 'No', 'no_label', 'cancel_label']));
});

test('validate_tag_parts() rejects a question tag with no prompt/label pair at all', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['question']), /question tag requires at least one prompt\/label pair/);
});

test('validate_tag_parts() accepts a vend tag with item/price pairs', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['vend', 'Potion', '10', 'Elixir', '50']));
});

test('validate_tag_parts() rejects a vend tag with no item/price pair', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['vend']), /vend tag requires at least one item\/price pair/);
});

test('validate_tag_parts() accepts a hawk tag with a margin and item/price pairs', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['hawk', '50', 'Potion', '10']));
});

test('validate_tag_parts() accepts a hawk tag with only a margin (and a cancel label)', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['hawk', '75']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['hawk', '75', 'start']));
});

test('validate_tag_parts() rejects a hawk tag without a margin', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['hawk']), /hawk tag requires a margin/);
    assert.throws(() => ctx.validate_tag_parts(['hawk', 'Potion', '10']), /hawk tag requires a margin/);
});

test('validate_tag_parts() accepts a correctly-shaped inn tag', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['inn', '10', 'no_label']));
});

test('validate_tag_parts() rejects an inn tag with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['inn', '10']), /inn tag requires three parts/);
});

test('validate_tag_parts() accepts a switch tag with clean prompt/label pairs', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['switch', 'A', 'a_label', 'B', 'b_label']));
});

test('validate_tag_parts() rejects a switch tag with a dangling unpaired parameter', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['switch', 'A', 'a_label', 'B']), /switch tag requires one or more complete prompt\/label pairs/);
});

test('validate_tag_parts() accepts a services tag with clean prompt/price/label triplets', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['services', 'Repair', '20', 'repair_label']));
});

test('validate_tag_parts() rejects a services tag with an incomplete triplet', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['services', 'Repair', '20']), /services tag requires one or more complete prompt\/price\/label triplets/);
});

test('validate_tag_parts() accepts heal/recharge/select_team/mute/ack with no parameters', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['heal']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['recharge']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['select_team']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['mute']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['ack']));
});

test('validate_tag_parts() rejects heal/recharge/select_team/mute/ack if given any parameters', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['heal', 'extra']), /heal tag takes no parameters/);
    assert.throws(() => ctx.validate_tag_parts(['mute', 'extra']), /mute tag takes no parameters/);
    assert.throws(() => ctx.validate_tag_parts(['ack', 'extra']), /ack tag takes no parameters/);
});

test('validate_tag_parts() accepts resurrect/cure/uncurse with price and label', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['resurrect', '100', 'no_label']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['cure', '20', 'no_label']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['uncurse', '20', 'no_label']));
});

test('validate_tag_parts() rejects resurrect/cure/uncurse with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['resurrect', '100']), /resurrect tag requires two parts/);
});

test('validate_tag_parts() accepts a correctly-shaped pause tag', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['pause', '500']));
});

test('validate_tag_parts() rejects a pause tag with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['pause']), /pause tag requires one part/);
});

test('validate_tag_parts() accepts correctly-shaped sound and music tags', () => {
    const ctx = freshDialogText();
    assert.doesNotThrow(() => ctx.validate_tag_parts(['sound', 'click.mp3']));
    assert.doesNotThrow(() => ctx.validate_tag_parts(['music', 'Fight End.mp3']));
});

test('validate_tag_parts() rejects sound and music tags with the wrong arity', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['sound']), /sound tag requires two parts/);
    assert.throws(() => ctx.validate_tag_parts(['music']), /music tag requires two parts/);
});

test('validate_tag_parts() rejects an unrecognized tag name', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.validate_tag_parts(['not_a_real_tag']), /unknown tag name not_a_real_tag/);
});

// --- generate_label_index() ---
// Replaces the old process_dialog_text()/text_blocks approach: rather
// than splitting the string into blocks, it scans for backtick-quoted
// tags and records each `label,name` tag's position (the index right
// after its closing backtick) in a plain label -> position map. The
// text itself is left untouched -- \n is no longer a block delimiter,
// just a literal character handled later during rendering.

test('generate_label_index() records a label at the position right after its closing backtick', () => {
    const ctx = freshDialogText();
    const text = '`label,intro`Hello';
    const labels = ctx.generate_label_index(text);
    assert.equal(labels.intro, text.indexOf('`label,intro`') + '`label,intro`'.length);
});

test('generate_label_index() records multiple labels at their own distinct positions', () => {
    const ctx = freshDialogText();
    const text = '`label,a`AAA`label,b`BBB';
    const labels = ctx.generate_label_index(text);
    assert.equal(labels.a, text.indexOf('`label,a`') + '`label,a`'.length);
    assert.equal(labels.b, text.indexOf('`label,b`') + '`label,b`'.length);
});

test('generate_label_index() ignores non-label tags entirely', () => {
    const ctx = freshDialogText();
    const labels = ctx.generate_label_index('`jump,end`some text`pause,500`');
    assert.equal(JSON.stringify(labels), '{}');
});

test('generate_label_index() returns an empty map for text with no tags', () => {
    const ctx = freshDialogText();
    assert.equal(JSON.stringify(ctx.generate_label_index('just plain text, no tags at all')), '{}');
});

test('generate_label_index() throws on an unpaired backtick', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.generate_label_index('some text `label,intro with no closing tick'), /unpaired backtick/);
});

test('generate_label_index() validates each tag it encounters, throwing on a malformed one', () => {
    const ctx = freshDialogText();
    assert.throws(() => ctx.generate_label_index('`jump`no label here'), /jump tag requires two parts/);
});

// --- remove_top_dialog_line() ---
// New signature: takes a single environment object ({text_buffer,
// char_cols}) and mutates its text_buffer property in place, rather
// than taking two params and returning the new string. Also now
// treats a <br> as ending the line immediately, even short of
// char_cols -- confirmed directly, along with everything else here.

test('remove_top_dialog_line() strips exactly char_cols plain characters from the front', () => {
    const ctx = freshDialogText();
    const env = { text_buffer: '0123456789REST', char_cols: 10 };
    ctx.remove_top_dialog_line(env);
    assert.equal(env.text_buffer, 'REST');
});

test('remove_top_dialog_line() counts an HTML entity as one visible character, not its literal length', () => {
    const ctx = freshDialogText();
    const env = { text_buffer: 'AB&nbsp;CDEFGHIJ', char_cols: 9 };
    ctx.remove_top_dialog_line(env);
    assert.equal(env.text_buffer, 'IJ');
});

test('remove_top_dialog_line() handles multiple consecutive entities (the real row-padding case)', () => {
    const ctx = freshDialogText();
    const env = { text_buffer: 'AB&nbsp;&nbsp;&nbsp;NEXTLINE', char_cols: 5 };
    ctx.remove_top_dialog_line(env);
    assert.equal(env.text_buffer, 'NEXTLINE');
});

test('remove_top_dialog_line() stops at a <br> even short of char_cols, and the tag itself does not count toward col', () => {
    const ctx = freshDialogText();
    const env = { text_buffer: 'AB<br>CD', char_cols: 10 };
    ctx.remove_top_dialog_line(env);
    assert.equal(env.text_buffer, 'CD');
});

test('remove_top_dialog_line() consumes everything and leaves empty when char_cols exceeds the content length', () => {
    const ctx = freshDialogText();
    const env = { text_buffer: 'short', char_cols: 100 };
    ctx.remove_top_dialog_line(env);
    assert.equal(env.text_buffer, '');
});

// --- render_dialog() end-to-end control flow ---
// The DOM-dependent pieces (new_render_text_environment,
// dialog_render_text, dialog_wait_on_next_button) are mocked directly,
// same pattern used elsewhere in this suite: verifies which branch is
// taken and what gets called, not actual pixel/DOM rendering.

function freshRenderDialog(teamid, player_party, teamids) {
    const shown = [];
    const calls = { sound: [], music: [], mute: 0, ack: 0, pause: [] };
    const globals = { teamid, player_party };
    if (teamids !== undefined) globals.teamids = teamids;
    const ctx = loadBrowserScripts(['dialog_text.js'], globals);
    ctx.new_render_text_environment = () => ({});
    ctx.dialog_render_text = async (env, text) => { if (text) shown.push(text); };
    ctx.dialog_wait_on_next_button = async () => { calls.ack++; };
    ctx.play_sound = (clip) => calls.sound.push(clip);
    ctx.play_music = (clip) => calls.music.push(clip);
    ctx.stop_music = () => calls.mute++;
    ctx.setTimeout = (fn, ms) => { calls.pause.push(ms); fn(); };
    return { ctx, shown, calls };
}

// The exact string generated server-side by FIGHT::process_fight_conclusion()
// (see format_conclusion_dialog() in fight.php) -- team sections chained
// with a shared ack/mute exit point.
const CONCLUSION_DIALOG = "`label,team_1``team,1,team_2`Alice has earned 38 XP.\nYour team received 10 gold.\n`jump,more_2``label,team_2``team,2,quiet_end`Bob has earned 0 XP.\nYour team received 10 gold.\n`jump,end``label,more_1``team,1,more_2`Alice has earned 38 XP.\nYour team received 10 gold.\n`jump,more_2``label,more_2``team,2,end`Bob has earned 0 XP.\nYour team received 10 gold.\n`jump,end``label,end``ack``label,quiet_end``mute`";

test('render_dialog() shows only the matching team\'s section, then acks and mutes', async () => {
    const { ctx, shown, calls } = freshRenderDialog(1, 0);
    await ctx.render_dialog('elem', 'btn', CONCLUSION_DIALOG);
    assert.equal(shown.join(''), 'Alice has earned 38 XP.\nYour team received 10 gold.\n');
    assert.equal(calls.ack, 1);
    assert.equal(calls.mute, 1);
});

test('render_dialog() skips a non-matching team and shows the next one\'s section', async () => {
    const { ctx, shown, calls } = freshRenderDialog(2, 1);
    await ctx.render_dialog('elem', 'btn', CONCLUSION_DIALOG);
    assert.equal(shown.join(''), 'Bob has earned 0 XP.\nYour team received 10 gold.\n');
    assert.equal(calls.ack, 1);
    assert.equal(calls.mute, 1);
});

test('render_dialog() shows every one of an alliance\'s teams, then acks once', async () => {
    const { ctx, shown, calls } = freshRenderDialog(1, 0, [1, 2]);
    await ctx.render_dialog('elem', 'btn', CONCLUSION_DIALOG);
    assert.equal(shown.join(''), 'Alice has earned 38 XP.\nYour team received 10 gold.\nBob has earned 0 XP.\nYour team received 10 gold.\n');
    assert.equal(calls.ack, 1);
    assert.equal(calls.mute, 1);
});

test('render_dialog() with an alliance\'s second team only shows that one', async () => {
    const { ctx, shown, calls } = freshRenderDialog(2, 0, [2]);
    await ctx.render_dialog('elem', 'btn', CONCLUSION_DIALOG);
    assert.equal(shown.join(''), 'Bob has earned 0 XP.\nYour team received 10 gold.\n');
    assert.equal(calls.ack, 1);
});

test('render_dialog() shows nothing and just mutes when no team matches', async () => {
    // quiet_end: nothing of this viewer's to acknowledge (the party's
    // own won/perished line, before the team sections, has its own ack).
    const { ctx, shown, calls } = freshRenderDialog(999, 0);
    await ctx.render_dialog('elem', 'btn', CONCLUSION_DIALOG);
    assert.deepEqual(shown, []);
    assert.equal(calls.ack, 0);
    assert.equal(calls.mute, 1);
});

test('render_dialog() falls through team checks to a party check, and on to a final fallback label', async () => {
    const ctx_text = "`label,team_1``team,1,party_0`TEAM_ONE`jump,end`"
        + "`label,party_0``party,0,fallback`PARTY_ZERO`jump,end`"
        + "`label,fallback`FALLBACK`label,end``ack`";

    let result = freshRenderDialog(99, 0);
    await result.ctx.render_dialog('e', 'b', ctx_text);
    assert.equal(result.shown.join(''), 'PARTY_ZERO');

    result = freshRenderDialog(99, 99);
    await result.ctx.render_dialog('e', 'b', ctx_text);
    assert.equal(result.shown.join(''), 'FALLBACK');
});

test('render_dialog() passes the correct single parameter to sound, music, and pause', async () => {
    const { ctx, calls } = freshRenderDialog(1, 0);
    await ctx.render_dialog('e', 'b', '`sound,click.mp3``music,Fight End.mp3``pause,500`');
    assert.deepEqual(calls.sound, ['click.mp3']);
    assert.deepEqual(calls.music, ['Fight End.mp3']);
    assert.deepEqual(calls.pause, [500]);
});

test('render_dialog() jump skips straight to the target label with no rendering of anything in between', async () => {
    const { ctx, shown } = freshRenderDialog(1, 0);
    await ctx.render_dialog('e', 'b', '`jump,skip`THIS SHOULD NOT APPEAR`label,skip`THIS SHOULD');
    assert.equal(shown.join(''), 'THIS SHOULD');
});
