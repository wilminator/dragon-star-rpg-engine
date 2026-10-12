'use strict';

// A monster player's action boxes: stacked into rows when the monsters
// stand closer together than a box is wide, kept on the stage, and only
// the chosen monster's box left on top.
const test = require('node:test');
const assert = require('node:assert');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function box() {
    return { style: {} };
}

test('boxes take one row when they fit side by side, more as the monsters crowd together', () => {
    const ctx = loadBrowserScripts(['object_api.js', 'fight_common.js']);
    assert.equal(ctx.monster_actionbox_rows(160), 1, 'up to 4 heroes: 160 apart');
    assert.equal(ctx.monster_actionbox_rows(120), 2, '5 monsters');
    assert.equal(ctx.monster_actionbox_rows(70), 3, '9 monsters');
    assert.equal(ctx.monster_actionbox_rows(20), 4, 'never more than 4 rows');
});

test('neighbours go to different rows, and no two in a row overlap, for 9 monsters', () => {
    const ctx = loadBrowserScripts(['object_api.js', 'fight_common.js']);
    const placed = [];
    for (let i = 0; i < 9; i++) {
        const b = box();
        ctx.place_monster_actionbox(b, 40 + i * 70, i, 70);
        placed.push([parseInt(b.style.left), parseInt(b.style.top)]);
    }
    assert.deepEqual(placed.map((p) => p[1]), [412, 376, 340, 412, 376, 340, 412, 376, 340]);
    for (let a = 0; a < placed.length; a++)
        for (let b = a + 1; b < placed.length; b++)
            if (placed[a][1] === placed[b][1])
                assert.ok(Math.abs(placed[a][0] - placed[b][0]) >= 162, `boxes ${a} and ${b} overlap`);
});

test('a box never runs off the stage', () => {
    const ctx = loadBrowserScripts(['object_api.js', 'fight_common.js']);
    const right = box(), left = box();
    ctx.place_monster_actionbox(right, 760, 0, 160);
    ctx.place_monster_actionbox(left, -30, 0, 160);
    assert.equal(parseInt(right.style.left), 800 - 162);
    assert.equal(parseInt(left.style.left), 0);
});

test('choosing another monster sends the last one\'s box back down', () => {
    const boxes = [box(), box(), box()];
    const heroes = boxes.map((b) => ({ html: { actionbox: b, stats: box() } }));
    const ctx = loadBrowserScripts(['object_api.js', 'fight_highlight.js'], {
        get_hero: (party, group, character) => heroes[character],
    });
    ctx.player_party = 1;
    ctx.player_slide = { newgroup: 0 };
    ctx.curr_hero = [0, 1];
    ctx.set_stats_zindex(1, 0, 1, true);
    assert.equal(boxes[1].style.zIndex, 207);
    ctx.curr_hero = [0, 2];
    ctx.set_stats_zindex(1, 0, 1, false);
    ctx.set_stats_zindex(1, 0, 2, true);
    assert.equal(boxes[1].style.zIndex, 200, 'no longer chosen: back with the rest');
    assert.equal(boxes[2].style.zIndex, 207);
    ctx.set_stats_zindex(1, 0, 0, true);
    assert.equal(boxes[0].style.zIndex, 206, 'under the pointer');
    ctx.set_stats_zindex(1, 0, 0, false);
    assert.equal(boxes[0].style.zIndex, 200);
});
