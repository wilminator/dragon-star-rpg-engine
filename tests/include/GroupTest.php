<?php

use PHPUnit\Framework\TestCase;

/**
 * GROUP never touches the database -- pure unit tests throughout.
 */
class GroupTest extends TestCase {

    /**
     * Constructed via reflection, bypassing CHARACTER's constructor --
     * it transitively calls ITEM::get() (via calculate_pxp()), which
     * reads a file-based cache not available in this context. HP and
     * pxp are set explicitly here, beyond charid/name, since several
     * GROUP methods (is_dead(), get_pxp(), count_living()) read them
     * directly.
     */
    private function make_fake_character(int $charid, string $name, int $hp = 10, int $pxp = 0): CHARACTER {
        $reflection = new ReflectionClass(CHARACTER::class);
        $character = $reflection->newInstanceWithoutConstructor();
        $character->charid = $charid;
        $character->name = $name;
        $character->current = ['HP' => $hp];
        $character->pxp = $pxp;
        return $character;
    }

    // --- add_hero() ---

    public function test_add_hero_appends_when_no_position_given() {
        $group = new GROUP();
        $a = $this->make_fake_character(1, 'Alice');
        $b = $this->make_fake_character(2, 'Bob');

        $group->add_hero($a);
        $group->add_hero($b);

        $this->assertSame($a, $group->characters[0]);
        $this->assertSame($b, $group->characters[1]);
    }

    public function test_add_hero_inserts_at_a_specific_position() {
        $group = new GROUP();
        $a = $this->make_fake_character(1, 'Alice');
        $b = $this->make_fake_character(2, 'Bob');
        $group->add_hero($a);

        // Insert Bob at position 0, ahead of Alice.
        $group->add_hero($b, 0);

        $this->assertSame($b, $group->characters[0]);
        $this->assertSame($a, $group->characters[1]);
    }

    public function test_add_hero_names_the_group_after_whoever_is_at_position_zero() {
        $group = new GROUP();
        $a = $this->make_fake_character(1, 'Alice');
        $group->add_hero($a);
        $this->assertSame("Alice's group", $group->name);

        // Inserting Bob ahead of Alice moves him to position 0 -- the
        // name should update to reflect him, not stay pinned to
        // whoever happened to join first.
        $b = $this->make_fake_character(2, 'Bob');
        $group->add_hero($b, 0);
        $this->assertSame("Bob's group", $group->name);
    }

    public function test_add_hero_rejects_once_the_group_is_full() {
        $group = new GROUP();
        for ($i = 0; $i < GROUP::MAX_COUNT; $i++) {
            $result = $group->add_hero($this->make_fake_character($i, "Member {$i}"));
            $this->assertFalse($result);
        }

        // One more, past MAX_COUNT, should be rejected.
        $result = $group->add_hero($this->make_fake_character(999, 'One Too Many'));

        $this->assertTrue($result);
        $this->assertSame(GROUP::MAX_COUNT, $group->count());
    }

    // --- add_monster() ---

    public function test_add_monster_appends_and_never_rejects_for_capacity() {
        $group = new GROUP();
        // Unlike add_hero(), add_monster() has no capacity check at all --
        // confirming this is the actual, current behavior, not assuming
        // it mirrors add_hero()'s cap.
        for ($i = 0; $i <= GROUP::MAX_COUNT; $i++) {
            $result = $group->add_monster($this->make_fake_character($i, "Monster {$i}"));
            $this->assertFalse($result);
        }

        $this->assertSame(GROUP::MAX_COUNT + 1, $group->count());
    }

    public function test_add_monster_does_not_set_the_group_name() {
        $group = new GROUP();
        $group->add_monster($this->make_fake_character(1, 'Goblin'));

        $this->assertSame('', $group->name);
    }

    // --- remove_fighter() ---

    public function test_remove_fighter_removes_at_the_given_index() {
        $group = new GROUP();
        $a = $this->make_fake_character(1, 'Alice');
        $b = $this->make_fake_character(2, 'Bob');
        $group->add_hero($a);
        $group->add_hero($b);

        $result = $group->remove_fighter(0);

        $this->assertFalse($result);
        $this->assertSame(1, $group->count());
        $this->assertSame($b, $group->characters[0]);
    }

    public function test_remove_fighter_rejects_an_out_of_bounds_index() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice'));

        $result = $group->remove_fighter(5);

        $this->assertTrue($result);
        $this->assertSame(1, $group->count());
    }

    // --- is_dead() ---

    public function test_is_dead_is_true_when_every_character_is_dead() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice', hp: 0));
        $group->add_hero($this->make_fake_character(2, 'Bob', hp: 0));

        $this->assertTrue($group->is_dead());
    }

    public function test_is_dead_is_false_if_any_character_is_alive() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice', hp: 0));
        $group->add_hero($this->make_fake_character(2, 'Bob', hp: 10));

        $this->assertFalse($group->is_dead());
    }

    public function test_an_empty_group_is_considered_dead() {
        // Vacuous truth: the loop over an empty $characters array never
        // runs, so $sum stays at its initial true. Worth confirming
        // explicitly, since this is easy to get backwards intuitively.
        $group = new GROUP();

        $this->assertTrue($group->is_dead());
    }

    // --- count_living() ---

    public function test_count_living_counts_only_characters_with_positive_hp() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice', hp: 10));
        $group->add_hero($this->make_fake_character(2, 'Bob', hp: 0));
        $group->add_hero($this->make_fake_character(3, 'Carol', hp: 5));

        $this->assertSame(2, $group->count_living());
    }

    // --- get_pxp() ---

    public function test_get_pxp_sums_every_characters_pxp() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice', pxp: 100));
        $group->add_hero($this->make_fake_character(2, 'Bob', pxp: 250));

        $this->assertSame(350, $group->get_pxp());
    }

    // --- get_character_list() ---

    public function test_get_character_list_null_returns_everyone() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice', hp: 10));
        $group->add_hero($this->make_fake_character(2, 'Bob', hp: 0));

        $this->assertSame(['0', '1'], $group->get_character_list(null));
    }

    public function test_get_character_list_true_returns_only_the_living() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice', hp: 10));
        $group->add_hero($this->make_fake_character(2, 'Bob', hp: 0));

        $this->assertSame(['0'], $group->get_character_list(true));
    }

    public function test_get_character_list_false_returns_only_the_dead() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice', hp: 10));
        $group->add_hero($this->make_fake_character(2, 'Bob', hp: 0));

        $this->assertSame(['1'], $group->get_character_list(false));
    }

    // --- get_character_key() / get_character_position() vs
    // --- get_character_position_by_id(): identity vs. value comparison ---

    public function test_get_character_key_and_position_match_by_object_identity() {
        $group = new GROUP();
        $a = $this->make_fake_character(1, 'Alice');
        $group->add_hero($a);

        // A different CHARACTER instance with the same charid should NOT
        // match -- these two methods compare by object identity (===),
        // not by charid value. get_character_position_by_id() below is
        // the value-based counterpart.
        $different_instance_same_charid = $this->make_fake_character(1, 'Alice');

        $this->assertSame(make_index_key(0), $group->get_character_key($a));
        $this->assertNull($group->get_character_key($different_instance_same_charid));

        $this->assertSame([0], $group->get_character_position($a));
        $this->assertNull($group->get_character_position($different_instance_same_charid));
    }

    public function test_get_character_position_by_id_matches_by_charid_value() {
        $group = new GROUP();
        $a = $this->make_fake_character(1, 'Alice');
        $group->add_hero($a);

        // Unlike the identity-based methods above, this one matches a
        // different instance sharing the same charid.
        $different_instance_same_charid = $this->make_fake_character(1, 'Alice');

        $this->assertSame([0], $group->get_character_position_by_id(1));
        $this->assertNull($group->get_character_position_by_id(999));
    }

    // --- get_character() ---

    public function test_get_character_throws_for_an_invalid_index() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice'));

        $this->expectException(Exception::class);
        $group->get_character(5);
    }

    // --- find_character() ---

    public function test_find_character_returns_the_index_when_found() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice'));
        $group->add_hero($this->make_fake_character(2, 'Bob'));

        $this->assertSame(1, $group->find_character(2));
    }

    public function test_find_character_returns_true_when_not_found() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice'));

        $this->assertTrue($group->find_character(999));
    }

    // --- Iterator interface ---

    public function test_group_is_directly_iterable() {
        $group = new GROUP();
        $a = $this->make_fake_character(1, 'Alice');
        $b = $this->make_fake_character(2, 'Bob');
        $group->add_hero($a);
        $group->add_hero($b);

        $visited = [];
        foreach ($group as $index => $character) {
            $visited[$index] = $character->name;
        }

        $this->assertSame([0 => 'Alice', 1 => 'Bob'], $visited);
    }

    // --- ArrayAccess interface ---

    public function test_array_access_get_and_isset() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice'));

        $this->assertTrue(isset($group[0]));
        $this->assertFalse(isset($group[5]));
        $this->assertSame('Alice', $group[0]->name);
    }

    public function test_array_access_set_always_throws() {
        $group = new GROUP();

        $this->expectException(Exception::class);
        $group[0] = $this->make_fake_character(1, 'Alice');
    }

    public function test_array_access_unset_removes_the_fighter() {
        $group = new GROUP();
        $group->add_hero($this->make_fake_character(1, 'Alice'));
        $group->add_hero($this->make_fake_character(2, 'Bob'));

        unset($group[0]);

        $this->assertSame(1, $group->count());
        $this->assertSame('Bob', $group->characters[0]->name);
    }

    // --- dehydrate() / hydrate() ---
    // Uses CHARACTER::create_hero() rather than make_fake_character()
    // above -- dehydrate() projects every DEHYDRATE_PROPERTIES entry
    // on each character, and make_fake_character()'s minimal
    // newInstanceWithoutConstructor() setup leaves most of those
    // uninitialized.

    public function test_dehydrate_projects_name_and_embeds_each_characters_own_dehydrate() {
        $group = new GROUP();
        $group->add_hero(CHARACTER::create_hero('Alice', 1, 1, null, 1));

        $data = $group->dehydrate();

        $this->assertSame("Alice's group", $data['name']);
        $this->assertSame('Alice', $data['characters'][0]['name']);
        $this->assertSame(CHARACTER::DEHYDRATE_VERSION, $data['characters'][0]['version']);
    }

    public function test_hydrate_reconstructs_real_character_instances() {
        $group = new GROUP();
        $group->add_hero(CHARACTER::create_hero('Alice', 1, 1, null, 1));
        $group->add_hero(CHARACTER::create_hero('Bob', 1, 1, null, 2));

        $rehydrated = GROUP::hydrate($group->dehydrate());

        $this->assertSame($group->name, $rehydrated->name);
        $this->assertCount(2, $rehydrated->characters);
        $this->assertInstanceOf(CHARACTER::class, $rehydrated->characters[0]);
        $this->assertSame('Alice', $rehydrated->characters[0]->name);
        $this->assertSame('Bob', $rehydrated->characters[1]->name);
    }

    public function test_dehydrate_survives_a_json_round_trip() {
        $group = new GROUP();
        $group->add_hero(CHARACTER::create_hero('Alice', 1, 1, null, 1));

        $json = json_encode($group->dehydrate());
        $rehydrated = GROUP::hydrate(json_decode($json, true));

        $this->assertInstanceOf(CHARACTER::class, $rehydrated->characters[0]);
        $this->assertSame(1, $rehydrated->characters[0]->charid);
    }
}
