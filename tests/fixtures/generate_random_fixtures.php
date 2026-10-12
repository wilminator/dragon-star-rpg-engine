<?php
/**
 * Generates a large, genuinely random fixture set for FightSimulator's
 * stress-testing use.
 *
 * This exists alongside generate_fixtures.php, not instead of it: that
 * file's small, hand-tailored base set (3 specific jobs, each chosen
 * to isolate one specific behavior -- deterministic growth, zero
 * growth, ability granting) is exactly right for the tests that need
 * a known, predictable value to assert against. But a tailored set,
 * by its nature, only covers the cases it was designed to cover --
 * real edge cases (an unusual stat spread, a job with many ability
 * grants stacked at the same level, a monster pool spanning a wide
 * power range) are more likely to surface from genuine variety than
 * from a deliberately narrow, curated set. FightSimulator's stress
 * test wants exactly that variety, not predictability.
 *
 * Builds real JOB/ITEM/ABILITY/PERSONALITY/MONSTER objects with
 * randomized properties, saves them through the real DB layer, then
 * calls each class's own write_cache_file() to produce the cache
 * files from what's actually in the database -- the same mechanism
 * the admin panel itself uses to regenerate cache after an edit, not
 * a hand-built imitation of its output shape.
 *
 * Replaces the base fixture set for as long as it's in effect (not
 * layered on top of it, unlike AugmentsFixtureCache's small, per-test
 * additions) -- a caller needing the base set back afterward calls
 * generate_fixture_cache() again, the same restoration
 * AugmentsFixtureCache itself performs internally.
 */

if (!defined('INCLUDE_GAME')) {
    define("INCLUDE_GAME", __DIR__ . "/../../app/include/");
}
require_once INCLUDE_GAME . "database.php";
require_once INCLUDE_GAME . "character.php";
require_once INCLUDE_GAME . "job.php";
require_once INCLUDE_GAME . "item.php";
require_once INCLUDE_GAME . "ability.php";
require_once INCLUDE_GAME . "personality.php";
require_once INCLUDE_GAME . "monster.php";

const RANDOM_FIXTURE_STAT_NAMES = ["HP", "MP", "Speed", "Accuracy", "Strength", "Dodge", "Block", "Power", "Resistance", "Focus"];

function random_stat_spread(int $min, int $max): array {
    $spread = [];
    foreach (RANDOM_FIXTURE_STAT_NAMES as $name) {
        $spread[$name] = mt_rand($min, $max);
    }
    return $spread;
}

function random_ability(int $index): ABILITY {
    $effect_spec = new EffectSpec(
        mt_rand(0, 1) === 0 ? Effect::HURT : Effect::HEAL,
        EffectSpec::TARGET_INDIVIDUAL,
        mt_rand(1, 30),
        mt_rand(0, 8),
        0
    );
    $ability = new ABILITY(
        "Random Ability $index",
        mt_rand(0, 1), // type: skill (0) or spell (1)
        $effect_spec,
        mt_rand(0, 25), // mp_used
        "Randomly generated ability $index",
        'bignone.png',
        'none',
        new AnimationEffect()
    );
    $ability->state = ObjectState::ACTIVE;
    return $ability;
}

function random_item(int $index): ITEM {
    $equip_type = match (mt_rand(0, 3)) {
        0 => null, // not equippable -- a consumable
        1 => ['hand'],
        2 => ['body'],
        default => ['ammo'],
    };
    $item = new ITEM(
        "Random Item $index",
        mt_rand(1, 500), // price
        new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, mt_rand(1, 50), 0, 0),
        mt_rand(0, 1) === 1, // one_use
        $equip_type,
        [], // stat_increase
        [], // stat_percent_increase
        0, 1, 0, // attack_targets, attack_count, attack_attribute
        $equip_type !== null && $equip_type[0] === 'ammo' ? 'bow' : '',
        "Randomly generated item $index",
        'bignone.png',
        'none',
        new AnimationEffect(),
        new AnimationEffect()
    );
    $item->state = ObjectState::ACTIVE;
    return $item;
}

// $ability_ids: the pool a job's own random ability grants are drawn
// from -- passed in rather than looked up here, since jobs must be
// generated after abilities exist, and this keeps that ordering
// explicit at the call site rather than implicit in this function.
function random_job(int $index, array $ability_ids): JOB {
    $upgrade = [];
    foreach (RANDOM_FIXTURE_STAT_NAMES as $name) {
        $upgrade[$name] = round(mt_rand(0, 6000) / 100, 2); // 0.00-60.00
    }
    $grants = [];
    if (count($ability_ids) > 0) {
        $grant_count = mt_rand(0, min(4, count($ability_ids)));
        $chosen = (array) array_rand($ability_ids, max(1, $grant_count));
        for ($i = 0; $i < $grant_count; $i++) {
            $grants[] = ['ability' => $ability_ids[$chosen[$i % count($chosen)]], 'level' => mt_rand(2, 20)];
        }
    }
    $job = new JOB("Random Job $index", mt_rand(50, 200), $upgrade, $grants);
    $job->state = ObjectState::ACTIVE;
    return $job;
}

function random_personality(int $index): PERSONALITY {
    $anim = new AnimationEffect();
    $direction_set = ['up' => ['images' => [], 'times' => []], 'down' => ['images' => [], 'times' => []], 'left' => ['images' => [], 'times' => []], 'right' => ['images' => [], 'times' => []]];
    $personality = new PERSONALITY(
        "Random Personality $index",
        $anim, $anim, $anim, $anim, $anim, $anim, $anim, $anim, $anim, $anim, $anim,
        ['stand' => $direction_set, 'move' => $direction_set]
    );
    $personality->state = ObjectState::ACTIVE;
    return $personality;
}

// $personality_ids: same reasoning as $ability_ids above -- monsters
// need a valid, already-saved personalityid.
function random_monster(int $index, array $personality_ids): MONSTER {
    // Log-spaced power tiers, same reasoning as generate_fixtures.php's
    // base_monster_rows(): MONSTER::pick_monsters() needs enough
    // matches at whatever pxp range a caller searches, not just enough
    // monsters to exist somewhere, and a wide, non-uniform spread
    // covers realistic target pxp ranges better than a flat one would.
    $tier = mt_rand(1, 15);
    $stat_min = max(1, (int) round(10 * (1.3 ** $tier) / 20));
    $stat_max = $stat_min + mt_rand(1, 5);
    $monster = new MONSTER(
        "Random Monster $index",
        $personality_ids[array_rand($personality_ids)],
        mt_rand(1, 100), // gold
        random_stat_spread($stat_min, $stat_max),
        [], // abilities
        [], // items
        [], // equipment
        0, 0, 0, 0 // ai_action, ai_goal, ai_target, ai_experience
    );
    $monster->state = ObjectState::ACTIVE;
    return $monster;
}

function generate_random_fixture_cache(
    Database $db,
    int $job_count = 20,
    int $item_count = 15,
    int $ability_count = 15,
    int $personality_count = 5,
    int $monster_count = 40
): void {
    JOB::ensure_table_exists($db, true);
    ITEM::ensure_table_exists($db, true);
    ABILITY::ensure_table_exists($db, true);
    PERSONALITY::ensure_table_exists($db, true);
    MONSTER::ensure_table_exists($db, true);

    // ITEM::load_all_from_cache() already synthesizes an itemid=0
    // "Nothing" fallback automatically if the cache has none (see its
    // own implementation) -- no need to seed one here.
    $ability_ids = [];
    for ($i = 0; $i < $ability_count; $i++) {
        $ability = random_ability($i);
        $ability->save($db);
        $ability_ids[] = $ability->abilityid;
    }

    for ($i = 0; $i < $item_count; $i++) {
        random_item($i)->save($db);
    }
    // MONSTER's own constructor (via make_monster()/calculate_pxp())
    // needs ITEM::load_all_from_cache() to already work -- confirmed
    // directly, a fatal error otherwise -- so the item cache must
    // exist before any monster below is constructed, not written all
    // together at the end.
    ITEM::write_cache_file($db);
    (new ReflectionProperty(ITEM::class, 'cache'))->setValue(null, null);

    for ($i = 0; $i < $job_count; $i++) {
        random_job($i, $ability_ids)->save($db);
    }

    $personality_ids = [];
    for ($i = 0; $i < $personality_count; $i++) {
        $personality = random_personality($i);
        $personality->save($db);
        $personality_ids[] = $personality->personalityid;
    }

    for ($i = 0; $i < $monster_count; $i++) {
        random_monster($i, $personality_ids)->save($db);
    }

    JOB::write_cache_file($db);
    ABILITY::write_cache_file($db);
    PERSONALITY::write_cache_file($db);
    MONSTER::write_cache_file($db);

    // Same reasoning as AugmentsFixtureCache::reset_fixture_caches():
    // each class's in-memory $cache is only re-read from disk on a
    // null check, so without this, the next load_all_from_cache() call
    // would keep serving whatever was cached from before this ran.
    foreach ([JOB::class, ITEM::class, ABILITY::class, PERSONALITY::class, MONSTER::class] as $class) {
        (new ReflectionProperty($class, 'cache'))->setValue(null, null);
    }
}
