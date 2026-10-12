'use strict';

// Group buttons down the left of the fight screen: the opponents' and
// the player's own never overlap, whatever the group counts.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function layout(own_groups, enemy_groups, monster_player = false) {
    const parties = [
        { teams: monster_player ? [] : { 5: [1] }, groups: Array(own_groups).fill({}) },
        { teams: [], groups: Array(enemy_groups).fill({}) },
    ];
    const ctx = loadBrowserScripts(['html_objects.js'], {
        player_party: 0, teamid: monster_player ? -1 : 5,
        get_party: (p) => parties[p], get_party_length: (p) => parties[p].groups.length,
    });
    return ctx;
}

test('four groups a side: the enemy buttons end above the player\'s', () => {
    for (const own of [1, 2, 3, 4]) {
        for (const enemy of [1, 2, 3, 4]) {
            const ctx = layout(own, enemy);
            const enemy_bottom = ctx.enemy_group_top(1) + 50 * (enemy - 1) + 48;
            const own_top = 428 - 50 * Math.max(0, own - 3);
            assert.ok(enemy_bottom <= own_top, `${own} vs ${enemy}: enemy ends at ${enemy_bottom}, own starts at ${own_top}`);
            assert.ok(ctx.enemy_group_top(1) <= 200, 'never lower than before');
        }
    }
    assert.equal(layout(3, 3).enemy_group_top(1), 200, 'room to spare: where it always was');
});

test('a player on the monsters has no buttons below, so the enemy\'s stay put', () => {
    assert.equal(layout(4, 4, true).enemy_group_top(1), 200);
});
