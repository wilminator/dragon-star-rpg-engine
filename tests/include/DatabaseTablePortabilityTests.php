<?php

/**
 * Database::ensure_table_exists() and has_table(): an existing table is
 * never re-created, so no DDL runs inside a transaction. (On MySQL any
 * DDL commits the open transaction at once: Alliance's first purchase in
 * a process used to commit early and then fail at commit().)
 */
trait DatabaseTablePortabilityTests {

    public function test_has_table_answers_without_ddl() {
        $this->db->prepare("DROP TABLE IF EXISTS table_probe")->execute();
        $this->assertFalse($this->db->has_table('table_probe'));
        $this->db->ensure_table_exists("DROP TABLE IF EXISTS table_probe", "CREATE TABLE IF NOT EXISTS table_probe (id int NOT NULL PRIMARY KEY)");
        $this->assertTrue($this->db->has_table('table_probe'));
        $this->db->prepare("DROP TABLE table_probe")->execute();
    }

    public function test_checking_a_table_that_exists_keeps_the_transaction_open() {
        $create = "CREATE TABLE IF NOT EXISTS table_probe (id int NOT NULL PRIMARY KEY)";
        $this->db->ensure_table_exists("DROP TABLE IF EXISTS table_probe", $create, true);
        $this->db->begin_transaction();
        $this->db->prepare("INSERT INTO table_probe (id) VALUES (1)")->execute();
        $this->db->ensure_table_exists("DROP TABLE IF EXISTS table_probe", $create);
        $this->db->rollback(); // threw "There is no active transaction" on MySQL
        $count = $this->db->prepare("SELECT COUNT(*) FROM table_probe");
        $count->execute();
        $this->assertSame(0, (int) $count->fetchColumn(), 'the rollback undid the insert');
        $count->closeCursor();
        $this->db->prepare("DROP TABLE table_probe")->execute();
    }

    public function test_buying_an_alliance_right_after_the_tables_are_checked_fresh() {
        $this->reset_tables(TEAM::class, HERO::class, MonsterToken::class, Alliance::class, PartyState::class);
        $teams = [];
        foreach (['Red', 'Blue'] as $name) {
            $teams[] = TEAM::create($this->db, 7, $name, [['name' => "$name 1", 'jobid' => 2, 'personalityid' => 1]]);
        }
        PartyState::choose_team($this->db, 7, $teams[0]->teamid);
        MonsterToken::grant($this->db, 7, 2, 1);
        // As in a fresh PHP process: the classes haven't checked their tables yet.
        foreach ([MonsterToken::class, Alliance::class] as $class) {
            (new ReflectionProperty($class, 'table_verified'))->setValue(null, false);
        }
        $this->assertSame([null, 'Your alliance is formed.'], AllianceScreen::act($this->db, 7, 'form', [$teams[1]->teamid]));
        $this->assertSame(1, MonsterToken::balance($this->db, 7));
    }
}
