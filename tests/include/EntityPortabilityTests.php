<?php

/**
 * Generic cross-database portability test bodies for any DbStoreTrait
 * entity: save()/load() round-trips its name field correctly,
 * successive saves get distinct auto-incremented IDs, and saving an
 * already-saved instance updates in place rather than duplicating.
 *
 * A concrete test class provides entity_class() and make_instance()
 * (typically via a small, entity-specific trait alongside this one --
 * see AbilityPortabilityInstance etc) and extends one of
 * SqliteTestCase/DatabaseTestCase/PostgresTestCase for $this->db and
 * reset_tables().
 *
 * Deliberately narrower than TeamPortabilityTests/FightPortabilityTests:
 * those two test genuinely distinct behavior (TEAM's transaction-wrapped
 * create(), FIGHT's nested PARTY/GROUP/CHARACTER round-trip). These five
 * entities (ABILITY/ITEM/JOB/MONSTER/PERSONALITY) all share the same
 * plain DbStoreTrait save()/load() shape with no entity-specific
 * persistence logic of their own, so one shared, generic test body
 * covers the actual risk from this conversion -- that create_query()
 * runs without error on each driver, and that $save_columns' order
 * matches translate_to_store()'s.
 */
trait EntityPortabilityTests {

    abstract protected function entity_class(): string;
    abstract protected function make_instance(): object;

    public function test_save_then_load_round_trips_the_name_field() {
        $class = $this->entity_class();
        $this->reset_tables($class);

        $instance = $this->make_instance();
        $instance->save($this->db);
        $id_field = $this->id_field();
        $this->assertNotNull($instance->$id_field);

        $reloaded = $class::load($this->db, $instance->$id_field);
        $this->assertSame($instance->name, $reloaded->name);
    }

    public function test_successive_saves_get_distinct_ids() {
        $class = $this->entity_class();
        $this->reset_tables($class);

        $instance1 = $this->make_instance();
        $instance1->save($this->db);
        $instance2 = $this->make_instance();
        $instance2->save($this->db);

        $id_field = $this->id_field();
        $this->assertNotSame($instance1->$id_field, $instance2->$id_field);
    }

    public function test_saving_an_existing_instance_updates_in_place_not_a_duplicate() {
        $class = $this->entity_class();
        $this->reset_tables($class);

        $instance = $this->make_instance();
        $instance->save($this->db);
        $id_field = $this->id_field();
        $original_id = $instance->$id_field;

        $instance->name = 'Updated Name';
        $instance->save($this->db);

        $reloaded = $class::load($this->db, $original_id);
        $this->assertSame('Updated Name', $reloaded->name);
        $this->assertSame($original_id, $reloaded->$id_field);
    }

    private function id_field(): string {
        $property = new ReflectionProperty($this->entity_class(), 'table_key');
        $property->setAccessible(true);
        return $property->getValue();
    }
}
