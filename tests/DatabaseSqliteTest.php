<?php

require_once __DIR__ . '/../app/include/database.php';
require_once __DIR__ . '/DatabaseTests.php';

class DatabaseSqliteTest extends \PHPUnit\Framework\TestCase {
    use DatabaseTests;

    private Database $db;

    protected function setUp(): void {
        $this->db = Database::sqlite();
        $this->db->prepare($this->widgets_create_sql())->execute();
    }

    private function widgets_create_sql(): string {
        $id_column = $this->db->auto_increment_primary_key('id');
        return "CREATE TABLE IF NOT EXISTS widgets ($id_column, name TEXT, state INTEGER)";
    }
}
