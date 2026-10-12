<?php

/**
 * Covers Database's own core behavior: the PDO-based CRUD/transaction
 * methods, and the CREATE TABLE IF NOT EXISTS-based ensure_table_exists()
 * redesign, plus insert_query()/upsert_query().
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait -- each provides its own $this->db and its own
 * widgets table (via its own setUp(), since the table's primary key
 * needs Database::auto_increment_primary_key() to be portable, the
 * same as any real entity's DDL). See DatabaseSqliteTest/
 * DatabaseMysqlTest/DatabasePostgresTest for the three concrete classes.
 */
trait DatabaseTests {

    public function test_get_by_id_returns_null_not_false_on_no_match() {
        $this->assertNull($this->db->get_by_id('widgets', 'id', 999));
    }

    public function test_replace_row_inserts_then_updates_in_place() {
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'Widget A', 'state' => 0], 'id');
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'Widget A Updated', 'state' => 0], 'id');

        $row = $this->db->get_by_id('widgets', 'id', 1);
        $this->assertSame('Widget A Updated', $row['name']);
        $this->assertCount(1, $this->db->get_all('widgets', true));
    }

    public function test_get_all_filters_by_state_unless_include_inactive() {
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'A', 'state' => 0], 'id');
        $this->db->replace_row('widgets', ['id' => 2, 'name' => 'B', 'state' => 1], 'id');

        $this->assertCount(1, $this->db->get_all('widgets'));
        $this->assertCount(2, $this->db->get_all('widgets', true));
    }

    public function test_get_all_where_in_returns_empty_array_for_empty_input() {
        $this->assertSame([], $this->db->get_all_where_in('widgets', 'id', []));
    }

    public function test_delete_by_id_and_delete_where() {
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'A', 'state' => 0], 'id');
        $this->db->replace_row('widgets', ['id' => 2, 'name' => 'B', 'state' => 0], 'id');

        $this->db->delete_by_id('widgets', 'id', 1);
        $this->assertCount(1, $this->db->get_all('widgets', true));

        $this->db->delete_where('widgets', 'state', 0);
        $this->assertCount(0, $this->db->get_all('widgets', true));
    }

    public function test_transaction_rollback_discards_changes() {
        $this->db->begin_transaction();
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'A', 'state' => 0], 'id');
        $this->db->rollback();

        $this->assertNull($this->db->get_by_id('widgets', 'id', 1));
    }

    public function test_transaction_commit_persists_changes() {
        $this->db->begin_transaction();
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'A', 'state' => 0], 'id');
        $this->db->commit();

        $this->assertNotNull($this->db->get_by_id('widgets', 'id', 1));
    }

    public function test_ensure_table_exists_without_reset_preserves_existing_data() {
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'A', 'state' => 0], 'id');

        $this->db->ensure_table_exists(
            "DROP TABLE IF EXISTS widgets",
            $this->widgets_create_sql(),
            reset: false
        );

        $this->assertNotNull($this->db->get_by_id('widgets', 'id', 1));
    }

    public function test_ensure_table_exists_with_reset_wipes_existing_data() {
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'A', 'state' => 0], 'id');

        $this->db->ensure_table_exists(
            "DROP TABLE IF EXISTS widgets",
            $this->widgets_create_sql(),
            reset: true
        );

        $this->assertCount(0, $this->db->get_all('widgets', true));
    }

    public function test_insert_query_omits_the_given_primary_key_and_assigns_a_new_one() {
        $query = $this->db->insert_query('widgets', ['name', 'state']);
        $stmt = $this->db->prepare($query);
        $stmt->execute(['Widget A', 0]);

        $id = $this->db->mysql_do_insert_id();
        $this->assertNotNull($id);
        $this->assertSame('Widget A', $this->db->get_by_id('widgets', 'id', $id)['name']);
    }

    public function test_upsert_query_updates_an_existing_row_in_place() {
        $this->db->replace_row('widgets', ['id' => 1, 'name' => 'A', 'state' => 0], 'id');

        $query = $this->db->upsert_query('widgets', ['id', 'name', 'state'], 'id');
        $stmt = $this->db->prepare($query);
        $stmt->execute([1, 'A Updated', 0]);

        $this->assertSame('A Updated', $this->db->get_by_id('widgets', 'id', 1)['name']);
        $this->assertCount(1, $this->db->get_all('widgets', true));
    }
}
