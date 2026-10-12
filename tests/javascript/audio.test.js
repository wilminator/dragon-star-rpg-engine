'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadBrowserScripts } = require('./helpers/browser_context.js');

// Minimal Howler stand-in: records what was played, and reports itself
// as already loaded so load_sound()'s promise resolves immediately.
function freshAudio() {
    const played = [];
    class Howl {
        constructor(opts) { this.src = opts.src[0]; }
        state() { return 'loaded'; }
        once() {}
        volume() {}
        loop() {}
        pause() {}
        play() { played.push(this.src); }
    }
    const ctx = loadBrowserScripts(['audio.js'], { Howl, sound: 'sounds/', music: 'music/' });
    ctx.__played = played;
    return ctx;
}

test('play_sound() plays a clip that was never preloaded, instead of throwing', () => {
    // Regression: get_clip() returns null for anything not already in
    // audio_handles, and play_sound() called .volume() on that null.
    // LevelUp.mp3 is requested only by the fight dialog's `sound` tag
    // and is never preloaded, so the throw rejected render_dialog()
    // right before the level-up text -- which then never appeared.
    const ctx = freshAudio();
    assert.doesNotThrow(() => ctx.play_sound('LevelUp.mp3'));
    assert.deepEqual([...ctx.__played], ['sounds/LevelUp.mp3']);
});

test('play_sound() reuses an already-loaded clip rather than creating another', () => {
    const ctx = freshAudio();
    const first = ctx.play_sound('click.mp3');
    const second = ctx.play_sound('click.mp3');
    assert.equal(first, second);
});

test('play_sound() with an empty url does nothing', () => {
    const ctx = freshAudio();
    assert.equal(ctx.play_sound(''), null);
    assert.equal(ctx.__played.length, 0);
});
