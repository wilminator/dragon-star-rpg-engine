'use strict';

// Flying damage numbers and blood (fly.js): every fly that starts an
// event finishes it, however short the picture it flies off. A damage
// number off a short picture used to get a NaN flight time and was
// dropped without finishing, so the round's playback waited forever.
const test = require('node:test');
const assert = require('node:assert');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

function load() {
    const counts = { started: 0, finished: 0 };
    const ctx = loadBrowserScripts(['object_api.js', 'fly.js'], {
        start_event: () => { counts.started++; },
        finish_event: () => { counts.finished++; },
        setInterval: () => 1,
    });
    return { ctx, counts };
}

test('a flight always lasts at least one tick, and is never NaN', () => {
    const { ctx } = load();
    for (const dy of [-7, -6, -5]) {
        for (const drop of [50, 49, 0, -200]) {
            const time = ctx.fly_time(dy, drop);
            assert.ok(Number.isInteger(time) && time >= 1, `dy ${dy}, drop ${drop}: ${time}`);
        }
    }
    assert.equal(ctx.fly_time(-5, 50), 10, 'a short picture: straight up and back down');
});

test('a fly with no time left, or a broken time, still finishes its event', () => {
    const { ctx, counts } = load();
    const killed = [];
    const obj = () => ({ style: {} });
    ctx.make_fly(0, 0, obj(), () => false, NaN, (f) => killed.push('nan'));
    ctx.make_fly(0, 0, obj(), () => false, 0, (f) => killed.push('zero'));
    ctx.make_fly(0, 0, obj(), () => false, 3, (f) => killed.push('three'));
    assert.equal(counts.started, 3);
    ctx.move_fly();
    assert.deepEqual(killed.sort(), ['nan', 'zero']);
    ctx.move_fly();
    ctx.move_fly();
    assert.deepEqual(killed.sort(), ['nan', 'three', 'zero']);
    assert.equal(counts.finished, 3, 'every started fly finished');
});

test('a damage number lingers a second: holding playback mid-round, not after the last hit', () => {
    const { ctx, counts } = load();
    const obj = () => ({ style: {}, parentNode: { removeChild() {} } });
    ctx.playback_queue = [[1], [0, 'present_group', 1, 0]];
    ctx.kill_damage_fly({ x: 0, y: 0, object: obj() });
    assert.equal(counts.started, 1, 'more to play: the next fighter waits for it');
    ctx.playback_queue = [[1]];
    ctx.kill_damage_fly({ x: 0, y: 0, object: obj() });
    assert.equal(counts.started, 1, 'only waits left: commands don\'t wait for it');
    for (let tick = 0; tick < 40; tick++) ctx.move_fly();
    assert.equal(counts.finished, 1, 'only the held one finishes an event');
});
