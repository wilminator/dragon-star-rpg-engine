'use strict';

// app/include/animations.json (what each fight animation needs, for the
// admin editors) is generated from the animations themselves by
// utils/animation_manifest.js and committed. This fails when it's stale.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { buildManifest, MANIFEST } = require('../../utils/animation_manifest.js');

test('the committed animation manifest matches the animations (else run: node utils/animation_manifest.js)', () => {
    assert.equal(fs.readFileSync(MANIFEST, 'utf8'), buildManifest());
});

test('each animation says what images, sounds and timings it needs, and where its images live', () => {
    const manifest = JSON.parse(buildManifest());
    assert.ok(Object.keys(manifest).length > 0);
    for (const [name, spec] of Object.entries(manifest)) {
        for (const field of ['images', 'sounds', 'times']) assert.ok(Array.isArray(spec[field]), `${name}.${field}`);
        if (spec.imageloc) assert.equal(spec.imageloc.length, spec.images.length, `${name}: a location per image`);
        for (const where of spec.imageloc || []) assert.match(where, /^images\//, `${name}: a real path, not a placeholder`);
    }
});
