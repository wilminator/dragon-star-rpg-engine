<?php
/**
 * Defines generate_fixture_cache(), which (re)writes
 * tests/fixtures/cache/{items,jobs,abilities,personalities,monsters}.php.
 *
 * These are constant, hand-designed fixture rows -- not copies of real
 * game data -- covering the norms and extremes each class's tests need
 * (a "nothing equipped" item, deterministic and non-deterministic job
 * growth, an active/inactive ability pair, a spread of monster pxp
 * levels for range-filtered lookups, etc). Building rows by hand
 * as raw arrays risks getting a nested JSON field subtly wrong; this
 * instead constructs real objects via their actual constructors
 * (EffectSpec, AnimationEffect, ITEM, JOB, ABILITY, MONSTER,
 * PERSONALITY) and lets their own jsonSerialize()/translate_to_store()
 * logic produce the encoded fields, so fixture data is guaranteed to
 * round-trip correctly through each class's own translate_and_build().
 *
 * This file only DEFINES functions when require'd -- it has no side
 * effects on its own, so tests/bootstrap.php can require it safely on
 * every run. Two ways it actually runs:
 *
 *   1. Automatically, via tests/bootstrap.php, which calls
 *      generate_fixture_cache() with no extras at the start of every
 *      test run. This regenerates the base fixture set fresh every
 *      time, so the committed cache/*.php files can never silently
 *      drift out of sync with this script's own logic.
 *   2. From an individual test, via the AugmentsFixtureCache trait
 *      (tests/AugmentsFixtureCache.php), which calls this with EXTRA
 *      rows layered on top of the base set for the duration of one
 *      test only, then restores the base set afterward.
 *
 * Do not hand-edit the generated cache/*.php files directly -- change
 * the fixture definitions in base_item_rows()/base_job_rows()/
 * base_ability_rows()/base_personality_rows()/base_monster_rows()
 * below instead.
 */

if (!defined('INCLUDE_GAME')) {
    define("INCLUDE_GAME", __DIR__ . "/../../app/include/");
}
require_once INCLUDE_GAME . "character.php";
require_once INCLUDE_GAME . "job.php";
require_once INCLUDE_GAME . "monster.php";
require_once INCLUDE_GAME . "personality.php";

function build_item_row(int $itemid, string $name, ?array $equip_type, int $attack_targets = 0, int $attack_count = 1, int $attack_attribute = 0, string $ammo_type = '', string $menu_pic = 'bignone.png'): array {
    $use_effect_spec = new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 0, 0, 0);
    return [
        'itemid' => $itemid,
        'name' => $name,
        'price' => 10,
        'use_effect_spec' => json_encode($use_effect_spec),
        'one_use' => false,
        'equip_type' => $equip_type === null ? null : json_encode($equip_type),
        'stat_increase' => null,
        'stat_percent_increase' => null,
        'attack_targets' => $attack_targets,
        'attack_count' => $attack_count,
        'attack_attribute' => $attack_attribute,
        'ammo_type' => $ammo_type,
        'description' => "Fixture item $name",
        'menu_pic' => $menu_pic,
        'fight_effect_type' => 'none',
        'fight_animation_effect' => json_encode(new AnimationEffect()),
        'use_animation_effect' => json_encode(new AnimationEffect()),
        'state' => 0,
    ];
}

function build_job_row(int $jobid, string $name, int $need, array $upgrade, array $abilities = []): array {
    return [
        'jobid' => $jobid,
        'name' => $name,
        'need' => $need,
        'upgrade' => json_encode($upgrade),
        'abilities' => json_encode($abilities),
        'state' => 0,
    ];
}

function build_ability_row(int $abilityid, string $name, int $state, int $type = 0, int $mp_used = 0, string $menu_pic = 'bignone.png'): array {
    $effect_spec = new EffectSpec(Effect::HURT, EffectSpec::TARGET_INDIVIDUAL, 5, 2, 0);
    return [
        'abilityid' => $abilityid,
        'name' => $name,
        'type' => $type,
        'effect_spec' => json_encode($effect_spec),
        'mp_used' => $mp_used,
        'description' => "Fixture ability $name",
        'menu_pic' => $menu_pic,
        'skill_effect_type' => 'none',
        'impact' => json_encode(new AnimationEffect()),
        'state' => $state,
    ];
}

// $image: a picture in app/images/fighters/ (the assets in the repo), used
// for every pose the way the game's own personalities do; null leaves
// every animation a bare placeholder.
function build_personality_row(int $personalityid, string $name, ?string $image = null): array {
    $anim = json_encode(new AnimationEffect());
    $pose = $anim;
    $base = $anim;
    $die = $anim;
    $flee = $anim;
    $attack = $anim;
    if ($image !== null) {
        $base = json_encode(new AnimationEffect('base_animation', [$image], [], [0]));
        $pose = json_encode(new AnimationEffect('pose_animation', [$image], [], [500, 500]));
        $die = json_encode(new AnimationEffect('die_animation', [$image], [], [500, 10, 5]));
        $flee = json_encode(new AnimationEffect('flee_animation', [$image], [], [0]));
        $attack = json_encode(new AnimationEffect('attack_close_animation', [$image, $image, $image], [], [25, 500, 500]));
    }
    $direction_set = ['up' => ['images' => [], 'times' => []], 'down' => ['images' => [], 'times' => []], 'left' => ['images' => [], 'times' => []], 'right' => ['images' => [], 'times' => []]];
    return [
        'personalityid' => $personalityid,
        'name' => $name,
        'base' => $base, 'equip' => $pose, 'flee' => $flee, 'hit' => $pose, 'die' => $die,
        'attack_close' => $attack, 'attack_throw' => $attack, 'attack_shoot' => $attack,
        'skill' => $pose, 'spell' => $pose, 'item' => $pose,
        'overworld' => json_encode(['stand' => $direction_set, 'move' => $direction_set]),
        'state' => 0,
    ];
}

function build_monster_row(int $monsterid, string $name, int $personalityid, array $stats, int $pxp): array {
    return [
        'monsterid' => $monsterid,
        'name' => $name,
        'personalityid' => $personalityid,
        'pxp' => $pxp,
        'gold' => 5,
        'stats' => json_encode($stats),
        'abilities' => json_encode([]),
        'items' => json_encode([]),
        'equipment' => json_encode([]),
        'ai_action' => 0,
        'ai_goal' => 0,
        'ai_target' => 0,
        'ai_experience' => 0,
        'state' => 0,
    ];
}

/** @return array<int, array> itemid => row */
function base_item_rows(): array {
    $rows = [
        // 0: required by CHARACTER::calculate_pxp()'s "nothing
        // equipped" weapon-slot fallback -- confirmed empirically that
        // this itemid must always exist for ANY character to construct.
        build_item_row(0, 'Nothing', []),
        // 1: a basic stackable, non-equippable consumable.
        build_item_row(1, 'Stackable Potion', [], menu_pic: 'medkit.png'),
        // 2: a basic hand-equippable weapon.
        build_item_row(2, 'Basic Sword', ['hand'], menu_pic: 'sword.png'),
        // 3: a basic body armor piece.
        build_item_row(3, 'Basic Armor', ['body']),
        // 4: an ammo-type item.
        build_item_row(4, 'Basic Arrows', ['ammo'], ammo_type: 'bow', menu_pic: '556round.png'),
    ];
    return array_combine(array_column($rows, 'itemid'), $rows);
}

/** @return array<int, array> jobid => row */
function base_job_rows(): array {
    $stat_names = ["HP", "MP", "Speed", "Accuracy", "Strength", "Dodge", "Block", "Power", "Resistance", "Focus"];
    $zero_upgrade = array_fill_keys($stat_names, 0);
    $fifty_five_upgrade = array_fill_keys($stat_names, 55);

    $rows = [
        // 1: zero stat growth -- JOB::don()/level()'s rand()-based
        // growth collapses to a deterministic 0 regardless of the
        // roll (confirmed empirically). For testing leveling
        // structure (level/exp/need progression) without stat-value
        // noise.
        build_job_row(1, 'Zero-Growth Warrior', 100, $zero_upgrade),
        // 2: upgrade=55 for every stat -- collapses don()'s
        // rand(0, 55-55) range to a single deterministic value
        // (confirmed empirically: Strength ends at exactly 50).
        build_job_row(2, 'Deterministic Warrior', 100, $fifty_five_upgrade),
        // 3: zero stat growth, but grants ability 1 at level 2 -- for
        // JOB::grant_abilities() / CHARACTER::level()'s
        // ability-granting path.
        build_job_row(3, 'Ability Granter', 50, $zero_upgrade, [['ability' => 1, 'level' => 2]]),
    ];
    return array_combine(array_column($rows, 'jobid'), $rows);
}

/** @return array<int, array> abilityid => row */
function base_ability_rows(): array {
    $rows = [
        // 1: active -- job 3 above grants this at level 2.
        build_ability_row(1, 'Fixture Active Skill', 0, menu_pic: 'fireball.png'),
        // 2: inactive -- for scrub_inactive_objects()/filter_active() tests.
        build_ability_row(2, 'Fixture Inactive Skill', 1),
        // 3: a second active ability, different type (spell, not skill).
        build_ability_row(3, 'Fixture Active Spell', 0, type: 1, menu_pic: 'heal.png'),
    ];
    return array_combine(array_column($rows, 'abilityid'), $rows);
}

/** @return array<int, array> personalityid => row */
function base_personality_rows(): array {
    $rows = [
        // 1: the only personality fixture needed so far -- a monster or
        // character referencing this just needs a valid personalityid
        // to exist, not any particular animation behavior.
        // Its pictures are snuggles.png, one of the fighter images in
        // app/images/fighters/, so a fixture fight or hub shows faces.
        build_personality_row(1, 'Fixture Personality', 'snuggles.png'),
    ];
    return array_combine(array_column($rows, 'personalityid'), $rows);
}

/** @return array<int, array> monsterid => row */
function base_monster_rows(): array {
    $stat_names = ["HP", "MP", "Speed", "Accuracy", "Strength", "Dodge", "Block", "Power", "Resistance", "Focus"];

    // MONSTER::pick_monsters() loops, widening its pxp search spread,
    // until it finds at least $qty + 2 monsters -- confirmed directly
    // that this never terminates if the total fixture pool is smaller
    // than whatever $qty a caller asks for, no matter how wide the
    // spread grows (a 10-second hang with only 3 monsters and qty=2).
    // 15 monsters, spanning a wide pxp range so pick_monsters() finds
    // real matches (not just enough to avoid hanging) for any
    // realistic target pxp, covers every $qty this codebase's own
    // create_monster_party() can ask for.
    $rows = [];
    for ($i = 1; $i <= 15; $i++) {
        $pxp = (int) round(10 * (1.5 ** $i)); // ~15 to ~4,400, log-spaced
        $stat_value = max(1, (int) round($pxp / 20));
        $stats = array_fill_keys($stat_names, $stat_value);
        $rows[] = build_monster_row($i, "Fixture Monster $i", 1, $stats, $pxp);
    }
    return array_combine(array_column($rows, 'monsterid'), $rows);
}

/**
 * (Re)writes all five cache files. $extra_* rows are layered on top
 * of (and, by matching id, can override) the base set -- keyed by
 * itemid/jobid/abilityid/personalityid/monsterid so a caller's extra
 * row with an id already in the base set replaces it rather than
 * duplicating it.
 *
 * @param array<int, array> $extra_items
 * @param array<int, array> $extra_jobs
 * @param array<int, array> $extra_abilities
 * @param array<int, array> $extra_personalities
 * @param array<int, array> $extra_monsters
 */
function generate_fixture_cache(
    array $extra_items = [],
    array $extra_jobs = [],
    array $extra_abilities = [],
    array $extra_personalities = [],
    array $extra_monsters = []
): void {
    $items = array_values(base_item_rows() + $extra_items);
    $jobs = array_values(base_job_rows() + $extra_jobs);
    $abilities = array_values(base_ability_rows() + $extra_abilities);
    $personalities = array_values(base_personality_rows() + $extra_personalities);
    $monsters = array_values(base_monster_rows() + $extra_monsters);

    // cache/ is intentionally never committed -- it's generated build
    // output, not source -- so a fresh checkout has no such directory
    // yet. file_put_contents() below doesn't create missing parent
    // directories on its own, so without this, first run after a
    // fresh clone silently fails to write anything (file_put_contents()
    // returns false and warns, but doesn't throw) and every
    // file-cache-backed class fails downstream instead, far from
    // this actual cause.
    // Written to CACHE_DIR (tests/bootstrap.php points it at
    // tests/fixtures/cache/, or at a per-process folder under ParaTest).
    $dir = defined('CACHE_DIR') ? CACHE_DIR : __DIR__ . '/cache/';
    if (!is_dir($dir)) {
        @mkdir($dir, recursive: true);
    }

    $header = "<?php\n// GENERATED by tests/fixtures/generate_fixtures.php -- do not hand-edit.\n// See tests/fixtures/cache/README.md for what each row is for.\n";
    // Each file is written whole under another name, then renamed into
    // place: anything reading it sees the old version or the new, never
    // half of one.
    $files = ['items' => $items, 'jobs' => $jobs, 'abilities' => $abilities, 'personalities' => $personalities, 'monsters' => $monsters];
    foreach ($files as $name => $rows) {
        $temp = $dir . $name . '.php.' . getmypid() . '.tmp';
        file_put_contents($temp, $header . "return " . var_export($rows, true) . ";\n");
        rename($temp, $dir . $name . '.php');
    }
}

// CLI entry point: `php tests/fixtures/generate_fixtures.php` writes
// just the base set, with no extras -- this is what's committed.
if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    generate_fixture_cache();
    echo "Fixtures regenerated.\n";
}
