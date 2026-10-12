<?php

require_once __DIR__ . '/../app/include/database.php';
require_once __DIR__ . '/DatabaseTests.php';
require_once __DIR__ . '/PostgresTestCase.php';

class DatabasePostgresTest extends \PHPUnit\Framework\TestCase {
    use DatabaseTests;

    private Database $db;

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
            PostgresTestCase::database_name() // per process under ParaTest
        );
        $this->db->prepare("DROP TABLE IF EXISTS widgets")->execute();
        $this->db->prepare($this->widgets_create_sql())->execute();
    }

    private function widgets_create_sql(): string {
        $id_column = $this->db->auto_increment_primary_key('id');
        return "CREATE TABLE IF NOT EXISTS widgets ($id_column, name TEXT, state INTEGER)";
    }
}
