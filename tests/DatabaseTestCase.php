<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real MySQL connection.
 *
 * Points directly at a pre-provisioned test database using MYSQL_HOST /
 * MYSQL_TEST_USER / MYSQL_TEST_PASSWORD / MYSQL_TEST_DATABASE. That user
 * is expected to be scoped to that one database only, with full DDL/DML
 * rights within it but no GRANT option -- so this deliberately does not
 * attempt to create a database or user of its own; it only connects to
 * one that already exists.
 *
 * "Fresh state per run" is achieved by resetting tables in place, via
 * the existing ensure_table_exists($db, reset: true) mechanism, rather
 * than by creating a new database -- the available user has the DDL
 * rights within its own database for that, even without database- or
 * user-creation privileges.
 *
 * A subclass calls reset_tables() with whichever entity classes it
 * actually exercises; this base class doesn't assume which tables any
 * given test needs, since that varies per test class.
 *
 * Under ParaTest each process uses a database of its own: see
 * database_name(). This call is now
 * load-bearing, not just a "start fresh" convenience: production code's
 * own save()/load() no longer create tables lazily at all (that used to
 * be the source of a real bug -- see common.php's
 * ensure_all_databases_exist() for why), so reset_tables() is the only
 * place these tests' tables ever get created in the first place, since
 * tests never touch login.php's centralized setup call.
 *
 * If the required environment variables aren't set, every test in the
 * class is skipped cleanly rather than attempting a connection that
 * would just fail.
 */
abstract class DatabaseTestCase extends TestCase {

    protected Database $db;

    protected function setUp(): void {
        foreach (['MYSQL_HOST', 'MYSQL_TEST_USER', 'MYSQL_TEST_PASSWORD', 'MYSQL_TEST_DATABASE'] as $var) {
            if (getenv($var) === false) {
                $this->markTestSkipped(
                    "MySQL integration tests require $var to be set; skipping."
                );
            }
        }

        $this->db = Database::mysql(
            getenv('MYSQL_HOST'),
            getenv('MYSQL_TEST_USER'),
            getenv('MYSQL_TEST_PASSWORD'),
            self::database_name()
        );
        forget_verified_tables(); // see tests/bootstrap.php
    }

    /**
     * MYSQL_TEST_DATABASE, or under ParaTest (TEST_TOKEN set, one number
     * per process) that name plus "_<n>": parallel processes each reset
     * tables freely in a database of their own. Each is made the first
     * time it's needed, which takes the grant utils/create_test_db_user.sh
     * gives (every database named dragonstar_test*).
     */
    public static function database_name(): string {
        static $made = [];
        $database = getenv('MYSQL_TEST_DATABASE');
        $token = preg_replace('/\D/', '', (string) getenv('TEST_TOKEN'));
        if ($token === '') {
            return $database;
        }
        $database .= '_' . $token;
        if (!isset($made[$database])) {
            $pdo = new PDO('mysql:host=' . getenv('MYSQL_HOST'), getenv('MYSQL_TEST_USER'), getenv('MYSQL_TEST_PASSWORD'));
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database`");
            $made[$database] = true;
        }
        return $database;
    }

    /**
     * Drops and recreates each given entity class's table, giving the
     * test a known-empty starting point. Pass the fully-qualified entity
     * class names (e.g. TEAM::class, HERO::class) this test actually
     * touches -- resetting tables a test doesn't use is unnecessary work.
     *
     * DbStoreTrait::ensure_table_exists() short-circuits via a private
     * static $table_verified flag *before* it even looks at the $reset
     * argument -- so without clearing that flag first, only the very
     * first call to this method across an entire test run would actually
     * reset anything; every call after that would silently no-op,
     * regardless of reset: true. That flag is reset here via reflection
     * (private, but per-class -- trait statics aren't shared across
     * classes that use the same trait, confirmed empirically) before
     * every call, so this genuinely resets every time it's invoked.
     */
    protected function reset_tables(string ...$entity_classes): void {
        foreach ($entity_classes as $class) {
            $verified_flag = new ReflectionProperty($class, 'table_verified');
            $verified_flag->setValue(null, false);
            $class::ensure_table_exists($this->db, reset: true);
        }
        // team_relations is a second table TEAM manages internally,
        // not covered by the loop above -- DbStoreTrait's
        // ensure_table_exists()/$table_verified only knows about
        // $table_name (teams), so this needs its own explicit reset.
        if (in_array(TEAM::class, $entity_classes)) {
            $relations_flag = new ReflectionProperty(TEAM::class, 'relations_table_verified');
            $relations_flag->setValue(null, false);
            TEAM::ensure_relations_table_exists($this->db, reset: true);
        }
    }

    /**
     * Clears HERO's own request-scoped charid -> CHARACTER cache.
     *
     * That cache is a private static array, persisting across every test
     * method within the same PHPUnit process -- the same shape of problem
     * as $table_verified above, just on a different property. It matters
     * here specifically because reset_tables(HERO::class) resets the
     * heroes table's auto-increment back to 1, so a later test's first
     * saved character is very likely to reuse a characterid an earlier
     * test already cached under. Without clearing this, a test could
     * "load" a character and silently get a stale object left over from
     * a previous test, rather than exercising a genuine fresh load.
     */
    protected function reset_hero_cache(): void {
        $cache = new ReflectionProperty(HERO::class, 'character_cache');
        $cache->setValue(null, []);
    }
}