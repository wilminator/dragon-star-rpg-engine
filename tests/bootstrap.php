<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Polyfills for PHP 8.4-specific functions the app relies on
// (array_any() in manage_team.php/personality.php, array_all() in
// pointer_index.php). The app targets PHP 8.4 (per composer.json and
// the Docker image); this sandbox's available PHP is 8.3. Guarded by
// function_exists() so these never activate -- and can never shadow
// the real, native implementation -- when actually running under 8.4.
// Add array_find()/array_find_key() here too if the app starts using
// them; neither is in use as of this writing.
if (!function_exists('array_any')) {
    function array_any(array $array, callable $callback): bool {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) return true;
        }
        return false;
    }
}
if (!function_exists('array_all')) {
    function array_all(array $array, callable $callback): bool {
        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) return false;
        }
        return true;
    }
}

define('INCLUDE_GAME', __DIR__ . '/../app/include/');

// Must be defined before any class file loads: ITEM/JOB/ABILITY's default
// $cache_path property values are baked in at class-definition time as
// CACHE_DIR . '<name>.php', and common.php's own CACHE_DIR define is
// conditional (if (!defined('CACHE_DIR'))) specifically to allow this
// override. Points every file-cache-backed class at tests/fixtures/cache/
// instead of the real app cache -- see tests/fixtures/cache/README.md for
// what's in it and why.
//
// Under ParaTest (TEST_TOKEN set, one number per process), each process
// gets its own copy in the temp folder instead: tests that layer extra
// rows onto the cache (AugmentsFixtureCache) rewrite it, which would
// otherwise change it under every other process's feet.
$test_token = getenv('TEST_TOKEN');
define('CACHE_DIR', $test_token !== false && $test_token !== ''
    ? sys_get_temp_dir() . '/dragonstar-fixtures-' . preg_replace('/\D/', '', $test_token) . '/'
    : __DIR__ . '/fixtures/cache/');

// Regenerate the base fixture set fresh on every run, so the committed
// cache/*.php files can never drift out of sync with what
// generate_fixtures.php actually defines -- if someone edits the
// fixture definitions there and forgets to re-run it manually, this
// closes that gap automatically. Individual tests can still layer
// extra, test-specific rows on top via the AugmentsFixtureCache trait
// (tests/AugmentsFixtureCache.php), which restores this same base set
// afterward.
require_once __DIR__ . '/fixtures/generate_fixtures.php';
generate_fixture_cache();
require_once __DIR__ . '/AugmentsFixtureCache.php';

require_once INCLUDE_GAME . 'team.php';
// A throwaway encryption key in slot 1 (see include/secret_box.php), so
// anything stored encrypted -- authenticator secrets, the mail refresh
// token -- works in tests. Tests exercising key changes use their own
// folders and put this one back afterward.
$test_key_dir = sys_get_temp_dir() . '/dragonstar-test-keys-' . getmypid();
@mkdir($test_key_dir, 0700, true);
file_put_contents("$test_key_dir/dragonstar_key_1", base64_encode(random_bytes(32)));
@unlink("$test_key_dir/dragonstar_key_2");
putenv("ENCRYPTION_KEY_DIR=$test_key_dir");

// Messages logged while tests run have no site database to go to, so
// they land in the fallback file -- a throwaway one, not the site's.
require_once INCLUDE_GAME . 'game_log.php';
GameLog::use_fallback_file(sys_get_temp_dir() . '/dragonstar-test-errorlog-' . getmypid() . '.txt');
register_shutdown_function(fn() => @unlink(sys_get_temp_dir() . '/dragonstar-test-errorlog-' . getmypid() . '.txt'));

// No housekeeping at the end of the test run (see include/housekeeping.php).
require_once INCLUDE_GAME . 'housekeeping.php';
Housekeeping::set_after_response_enabled(false);

// "This table has been made" flags ($table_verified and the like) are
// static, so they outlive the database they were set against. Every
// database test base forgets them all before each test: a test's tables
// then get (re)made in its own database as needed, rather than assumed
// because an earlier test -- maybe on another database entirely, when
// ParaTest mixes SQLite and MySQL tests in one process -- made them.
function forget_verified_tables(): void {
    foreach (get_declared_classes() as $class) {
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if (str_ends_with($property->getName(), 'table_verified') && $property->getDeclaringClass()->getName() === $class) {
                $property->setValue(null, false);
            }
        }
    }
}
