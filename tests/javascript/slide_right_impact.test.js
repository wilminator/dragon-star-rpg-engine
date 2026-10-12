'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

// The image slides right across the screen and off it; each target is
// hit as the image's middle passes the target's middle.
function slide(targets, width = 400) {
    const hits = [];
    let finished = 0;
    const slider = { x: -width, width };
    const heroes = Object.fromEntries(targets.map((x, c) => [c, { html: { pic: { x, width: 100 } } }]));
    const ctx = loadBrowserScripts(['animations/slide_right_impact.js'], {
        animations: {},
        object_get_x: (o) => o.x,
        object_x: (o, value) => { o.x = value; },
        object_get_width: (o) => o.width,
        object_delete: () => {},
        get_hero: (p, g, c) => heroes[c],
        default_impact_process_event: (event) => hits.push({ target: event[1][2], slider_x: slider.x }),
        finish_event: () => { finished++; },
    });
    const self = {
        slide_object: slider,
        times: [2000, 25],
        events: targets.map((x, c) => ['Damage', [1, 1, c, 40, false]]),
        setTimeout() {},
    };
    let steps = 0;
    while (finished === 0 && steps++ < 1000) {
        ctx.slide_right_impact_move.call(self);
    }
    return { hits, finished, last_x: slider.x };
}

test('every target is hit as the middle of the image passes it, the far end of a big group too', () => {
    const { hits, finished } = slide([20, 100, 180, 260, 340, 420, 500, 580, 660]);
    assert.equal(finished, 1);
    assert.deepEqual(hits.map((h) => h.target), [0, 1, 2, 3, 4, 5, 6, 7, 8]);
    for (const h of hits) {
        const middle = h.slider_x + 200;
        assert.ok(middle > [20, 100, 180, 260, 340, 420, 500, 580, 660][h.target] + 50, `target ${h.target} hit once passed`);
        assert.ok(middle - 25 <= [20, 100, 180, 260, 340, 420, 500, 580, 660][h.target] + 50, `target ${h.target} hit right away, not late`);
    }
});

test('the image carries on until it has left the screen', () => {
    const { last_x } = slide([100]);
    assert.ok(last_x > 800);
});
