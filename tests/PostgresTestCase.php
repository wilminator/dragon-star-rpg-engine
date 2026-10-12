<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real Postgres connection.
 *
 * Points directly at a pre-provisioned test database using
 * POSTGRES_HOST / POSTGRES_TEST_USER / POSTGRES_TEST_PASSWORD /
 * POSTGRES_TEST_DATABASE. Same shape as DatabaseTestCase (its MySQL
 * equivalent) throughout -- see that class's own comments for the
 * reasoning behind reset_tables() and reset_hero_cache(), which apply
 * identically here.
 *
 * If the required environment variables aren't set, every test in the
 * class is skipped cleanly rather than attempting a connection that
 * would just fail.
 */
abstract class PostgresTestCase extends TestCase {

    protected Database $db;

    protected function setUp(): void {
        foreach (['POSTGRES_HOST', 'POSTGRES_TEST_USER', 'POSTGRES_TEST_PASSWORD', 'POSTGRES_TEST_DATABASE'] as $var) {
            if (getenv($var) === false) {
                $this->markTestSkipped(
                    "Postgres integration tests require $var to be set; skipping."
                );
            }
        }

        $this->db = Database::postgres(
            getenv('POSTGRES_HOST'),
            getenv('POSTGRES_TEST_USER'),
            getenv('POSTGRES_TEST_PASSWORD'),
            self::database_name()
        );
        forget_verified_tables(); // see tests/bootstrap.php
    }

    /**
     * POSTGRES_TEST_DATABASE, or under ParaTest (TEST_TOKEN set) that name
     * plus "_<n>", made the first time it's needed -- see
     * DatabaseTestCase::database_name(). The test user (the postgres
     * service's own) may create databases.
     */
    public static function database_name(): string {
        static $made = [];
        $database = getenv('POSTGRES_TEST_DATABASE');
        $token = preg_replace('/\D/', '', (string) getenv('TEST_TOKEN'));
        if ($token === '') {
            return $database;
        }
        $base = $database;
        $database .= '_' . $token;
        if (!isset($made[$database])) {
            $pdo = new PDO('pgsql:host=' . getenv('POSTGRES_HOST') . ';dbname=' . $base,
                getenv('POSTGRES_TEST_USER'), getenv('POSTGRES_TEST_PASSWORD'));
            $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $exists->execute([$database]);
            if ($exists->fetch() === false) {
                $pdo->exec('CREATE DATABASE "' . $database . '"');
            }
            $made[$database] = true;
        }
        return $database;
    }

    /**
     * Drops and recreates each given entity class's table, giving the
     * test a known-empty starting point. See DatabaseTestCase's own
     * version of this method for the full reasoning -- identical here.
     */
    protected function reset_tables(string ...$entity_classes): void {
        foreach ($entity_classes as $class) {
            $verified_flag = new ReflectionProperty($class, 'table_verified');
            $verified_flag->setValue(null, false);
            $class::ensure_table_exists($this->db, reset: true);
        }
        if (in_array(TEAM::class, $entity_classes)) {
            $relations_flag = new ReflectionProperty(TEAM::class, 'relations_table_verified');
            $relations_flag->setValue(null, false);
            TEAM::ensure_relations_table_exists($this->db, reset: true);
        }
    }

    /**
     * Clears HERO's own request-scoped charid -> CHARACTER cache. See
     * DatabaseTestCase's own version of this method for the full
     * reasoning -- identical here.
     */
    protected function reset_hero_cache(): void {
        $cache = new ReflectionProperty(HERO::class, 'character_cache');
        $cache->setValue(null, []);
    }
}
