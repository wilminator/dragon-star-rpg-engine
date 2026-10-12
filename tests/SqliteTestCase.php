<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real database connection, backed by
 * an in-memory SQLite database rather than MySQL.
 *
 * Unlike DatabaseTestCase, this needs no environment variables and no
 * external service -- Database::sqlite() creates a fresh, private
 * :memory: database per test. That also means "fresh state per run" is
 * automatic: each test gets its own connection and its own database.
 * Production code's save()/load() no longer create tables lazily at
 * all, though (see common.php's ensure_all_databases_exist()), so
 * reset_tables() below still needs to explicitly call
 * ensure_table_exists() itself for each entity a test uses -- it's not
 * just a convenience anymore, it's the only thing that creates these
 * tests' tables in the first place.
 *
 * Exists specifically to give the cross-database portability work (the
 * DDL/upsert changes needed to also support Postgres and SQLite, not
 * just MySQL) real, repeatable test coverage -- rather than one-off,
 * throwaway verification that isn't part of the test suite at all.
 */
abstract class SqliteTestCase extends TestCase {

    protected Database $db;

    protected function setUp(): void {
        $this->db = Database::sqlite();
        // A fresh, empty database each time: see forget_verified_tables().
        forget_verified_tables();
    }

    /**
     * Ensures the given entity classes' tables exist in this test's
     * own, fresh :memory: database, and resets their $table_verified
     * flag first.
     *
     * This call is load-bearing, not just a "start fresh" convenience:
     * production code's own save()/load() no longer create tables
     * lazily at all (that used to be the source of a real bug -- see
     * common.php's ensure_all_databases_exist() for why), so this is
     * the only place these tests' tables ever get created, since tests
     * never touch login.php's centralized setup call.
     *
     * The flag reset matters because each test method here gets its
     * own fresh, empty :memory: database, but $table_verified is a
     * static, persisting across every test method in the same PHPUnit
     * process (trait statics aren't shared across different classes
     * using the same trait, confirmed empirically, so this is safe to
     * do per-class). Without resetting it first, ensure_table_exists()
     * would see it already true from whichever earlier test first
     * created that entity's table, and silently skip creating it in
     * this test's own, separate database.
     */
    protected function reset_tables(string ...$entity_classes): void {
        foreach ($entity_classes as $class) {
            $verified_flag = new ReflectionProperty($class, 'table_verified');
            $verified_flag->setValue(null, false);
            $class::ensure_table_exists($this->db, reset: true);
        }
        // team_relations is a second table TEAM manages internally,
        // not covered by the loop above.
        if (in_array(TEAM::class, $entity_classes)) {
            $relations_flag = new ReflectionProperty(TEAM::class, 'relations_table_verified');
            $relations_flag->setValue(null, false);
            TEAM::ensure_relations_table_exists($this->db, reset: true);
        }
    }

    /**
     * Clears HERO's own request-scoped charid -> CHARACTER cache.
     *
     * Same reasoning as DatabaseTestCase::reset_hero_cache(): a private
     * static array persisting across every test method in the same
     * PHPUnit process. Each test here gets a fresh, empty database, so a
     * saved character is very likely to reuse a characterid an earlier
     * test already cached under -- without clearing this, "loading" a
     * character could silently return a stale object from a previous
     * test instead of a genuine fresh load.
     */
    protected function reset_hero_cache(): void {
        $cache = new ReflectionProperty(HERO::class, 'character_cache');
        $cache->setValue(null, []);
    }
}
