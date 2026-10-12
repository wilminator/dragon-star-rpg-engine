#!/usr/bin/env node
'use strict';

// Builds app/include/animations.json: what each fight animation
// (app/javascript/animations/) says it needs -- its images, where they
// live, its sounds and its timings -- read from the animations' own
// description() functions. The ability, item and personality editors
// (app/admin/) read that file to know which fields to offer.
//
// Run it after adding or changing an animation, and commit the result:
//
//     node utils/animation_manifest.js
//
// tests/javascript/animation_manifest.test.js fails while the file is out
// of date.

const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..');
const ANIMATIONS_DIR = path.join(ROOT, 'app', 'javascript', 'animations');
const PATHS_PHP = path.join(ROOT, 'app', 'include', 'paths.php');
const MANIFEST = path.join(ROOT, 'app', 'include', 'animations.json');

// The page globals the descriptions use for image locations, and the
// paths.php constants they come from (as the old get_animations.php set
// them).
const PATH_GLOBALS = {
    images: 'IMAGES_DIR', fighter_images: 'FIGHTER_IMAGES_DIR', menu_images: 'MENU_IMAGES_DIR',
    ability_images: 'ABILITY_IMAGES_DIR', item_images: 'ITEM_IMAGES_DIR', effect_images: 'EFFECT_IMAGES_DIR',
    sound: 'SOUND_DIR', music: 'MUSIC_DIR', javadir: 'JAVA_DIR',
};

// paths.php's define()s, with constants built from other constants
// (IMAGES_DIR."fighters/") worked out.
function readPaths(source) {
    const defined = {};
    const pattern = /define\s*\(\s*['"](\w+)['"]\s*,\s*([^;]+?)\)\s*;/g;
    let match;
    while ((match = pattern.exec(source)) !== null) {
        const parts = match[2].split('.').map((part) => part.trim());
        let value = '';
        let known = true;
        for (const part of parts) {
            const literal = /^(['"])(.*)\1$/.exec(part);
            if (literal) value += literal[2];
            else if (part in defined) value += defined[part];
            else known = false;
        }
        if (known) defined[match[1]] = value;
    }
    return defined;
}

function buildManifest() {
    const paths = readPaths(fs.readFileSync(PATHS_PHP, 'utf8'));
    const context = { console };
    context.window = context;
    for (const [global, constant] of Object.entries(PATH_GLOBALS)) {
        if (!(constant in paths)) throw new Error(`paths.php has no ${constant}`);
        context[global] = paths[constant];
    }
    vm.createContext(context);
    const files = fs.readdirSync(ANIMATIONS_DIR).filter((file) => file.endsWith('.js')).sort();
    for (const file of files) {
        const fullPath = path.join(ANIMATIONS_DIR, file);
        vm.runInContext(fs.readFileSync(fullPath, 'utf8'), context, { filename: fullPath });
    }
    const manifest = {};
    for (const name of Object.keys(context.animations || {}).sort()) {
        const describe = context.animations[name].prototype.description;
        if (typeof describe !== 'function') continue;
        manifest[name] = JSON.parse(JSON.stringify(describe.call(context.animations[name].prototype)));
    }
    return JSON.stringify(manifest, null, 4) + '\n';
}

if (require.main === module) {
    fs.writeFileSync(MANIFEST, buildManifest());
    console.log('Wrote ' + path.relative(ROOT, MANIFEST));
}

module.exports = { buildManifest, MANIFEST };
