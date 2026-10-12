<?php

/**
 * Covers FightAction: save()/load() round-trips action_data/final_state
 * correctly, load_all_by_fightid() returns entries ordered by sequence,
 * and -- the specific thing this class exists to guarantee -- its own
 * upsert never disturbs fight_actions' existing prefight_data column,
 * since that's the legacy field this object is deliberately not
 * reproducing, not something it owns.
 *
 * Shared across all three drivers by whichever concrete test class
 * `use`s this trait. See FightActionPortabilitySqliteTest/
 * FightActionPortabilityMysqlTest/FightActionPortabilityPostgresTest.
 */
trait FightActionPortabilityTests {

    public function test_save_then_load_round_trips_action_data_and_final_state() {
        $this->reset_tables(FightAction::class);

        $action = new FightAction(1, 1, [['Turn', [0, 0, 0, 0]], ['Damage', [0, 0, 1, 0, 5, false]]], ['counter' => 1]);
        $action->save($this->db);

        $reloaded = FightAction::load($this->db, 1, 1);
        $this->assertSame($action->action_data, $reloaded->action_data);
        $this->assertSame($action->final_state, $reloaded->final_state);
    }

    public function test_final_state_can_be_null() {
        $this->reset_tables(FightAction::class);

        $action = new FightAction(1, 1, [['Turn', [0, 0, 0, 0]]]);
        $action->save($this->db);

        $reloaded = FightAction::load($this->db, 1, 1);
        $this->assertNull($reloaded->final_state);
    }

    public function test_load_all_by_fightid_returns_entries_ordered_by_sequence() {
        $this->reset_tables(FightAction::class);

        (new FightAction(1, 2, [['Turn', [0, 0, 0, 0]]]))->save($this->db);
        (new FightAction(1, 1, [['Turn', [0, 0, 0, 0]]]))->save($this->db);
        (new FightAction(1, 3, [['Turn', [0, 0, 0, 0]]]))->save($this->db);

        $all = FightAction::load_all_by_fightid($this->db, 1);
        $this->assertCount(3, $all);
        $this->assertSame([1, 2, 3], array_map(fn($a) => $a->sequence, $all));
    }

    public function test_load_all_from_database_returns_actions_across_every_fight() {
        $this->reset_tables(FightAction::class);
        (new FightAction(1, 1, [['Turn', [0, 0, 0, 0]]]))->save($this->db);
        (new FightAction(1, 2, [['Turn', [0, 0, 0, 0]]]))->save($this->db);
        (new FightAction(2, 1, [['Turn', [0, 0, 0, 0]]]))->save($this->db);

        $all = FightAction::load_all_from_database($this->db);

        $this->assertCount(3, $all);
        $this->assertEqualsCanonicalizing(
            [[1, 1], [1, 2], [2, 1]],
            array_map(fn($a) => [$a->fightid, $a->sequence], $all)
        );
    }

    public function test_saving_over_an_existing_row_does_not_disturb_prefight_data() {
        $this->reset_tables(FightAction::class);

        (new FightAction(1, 1, [['Turn', [0, 0, 0, 0]]]))->save($this->db);
        $stmt = $this->db->prepare("UPDATE fight_actions SET prefight_data = ? WHERE fightid = 1 AND sequence = 1");
        $stmt->execute(['some legacy snapshot']);

        $updated = new FightAction(1, 1, [['Turn', [0, 0, 0, 0]], ['Damage', [0, 0, 1, 0, 7, true]]], ['counter' => 2]);
        $updated->save($this->db);

        $check = $this->db->prepare("SELECT prefight_data FROM fight_actions WHERE fightid = 1 AND sequence = 1");
        $check->execute();
        $row = $check->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('some legacy snapshot', $row['prefight_data']);

        $reloaded = FightAction::load($this->db, 1, 1);
        $this->assertSame(['counter' => 2], $reloaded->final_state);
    }
}
