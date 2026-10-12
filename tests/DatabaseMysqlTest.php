<?php

require_once __DIR__ . '/../app/include/database.php';
require_once __DIR__ . '/DatabaseTests.php';
require_once __DIR__ . '/DatabaseTestCase.php';

class DatabaseMysqlTest extends \PHPUnit\Framework\TestCase {
    use DatabaseTests;

    private Database $db;

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
            DatabaseTestCase::database_name() // per process under ParaTest
        );
        $this->db->prepare("DROP TABLE IF EXISTS widgets")->execute();
        $this->db->prepare($this->widgets_create_sql())->execute();
    }

    private function widgets_create_sql(): string {
        $id_column = $this->db->auto_increment_primary_key('id');
        return "CREATE TABLE IF NOT EXISTS widgets ($id_column, name TEXT, state INTEGER)";
    }
}
