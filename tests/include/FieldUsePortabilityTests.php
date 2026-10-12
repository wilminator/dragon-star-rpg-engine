<?php

/**
 * Using items and abilities between fights (FieldUse): what a hero is
 * offered, and using, casting and giving -- saved, and only as offered.
 */
trait FieldUsePortabilityTests {
    use AugmentsFixtureCache;

    // A healing draught (one hero), a healing spell for everyone, and a
    // fire spell that only works in fights.
    private function with_field_things(callable $callback): mixed {
        $draught = build_item_row(750, 'Draught', null);
        $draught['use_effect_spec'] = json_encode(new EffectSpec(Effect::HEAL, EffectSpec::TARGET_INDIVIDUAL, 20, 0, 0));
        $draught['one_use'] = true;
        $mend = build_ability_row(760, 'Mend All', 0, type: 0, mp_used: 5);
        $mend['effect_spec'] = json_encode(new EffectSpec(Effect::HEAL, EffectSpec::TARGET_PARTY, 20, 0, 0));
        $fire = build_ability_row(761, 'Fire', 0, type: 0, mp_used: 5);
        return $this->with_extra_cache($callback, items: [750 => $draught], abilities: [760 => $mend, 761 => $fire]);
    }

    // Two heroes on one team, the first hurt, holding two draughts and
    // knowing both spells.
    private function field_team(): array {
        $this->reset_tables(PartyState::class, TEAM::class, HERO::class, MonsterToken::class, Alliance::class,
            FIGHT::class, FightPlayerState::class);
        $this->reset_hero_cache();
        $team = TEAM::create($this->db, 7, 'Menders', [
            ['name' => 'Ann', 'jobid' => 2, 'personalityid' => 1],
            ['name' => 'Bo', 'jobid' => 2, 'personalityid' => 1],
        ]);
        $heroes = array_values($team->characters);
        [$ann, $bo] = $heroes;
        $ann->inventory = [['item' => 750, 'qty' => 2]];
        $ann->abilities = [760, 761];
        $bo->current['HP'] = 5;
        HERO::save_character($this->db, $ann);
        HERO::save_character($this->db, $bo);
        PartyState::choose_team($this->db, 7, $team->teamid);
        return [$ann, $bo];
    }

    private function hero(int $charid): CHARACTER {
        $this->reset_hero_cache();
        return HERO::load_character($this->db, $charid);
    }

    public function test_a_hero_is_offered_their_items_spells_and_abilities_apart() {
        $this->with_field_things(function () {
            [$ann] = $this->field_team();
            $options = FieldUse::options_for($this->db, 7, $ann->charid);
            $this->assertSame('Ann', $options['actor']['name']);
            $this->assertSame([['Draught', 2, true, 'hero']],
                array_map(fn($i) => [$i['name'], $i['qty'], $i['use']['usable'], $i['use']['aim']], $options['items']));
            $this->assertSame([['Mend All', true, 'party'], ['Fire', false, 'hero']],
                array_map(fn($c) => [$c['name'], $c['usable'], $c['aim']], $options['spells']));
            $this->assertSame([], $options['abilities'], 'no skills');
            $this->assertSame("Can't be used outside a fight.", $options['spells'][1]['why']);
            $this->assertNull(FieldUse::options_for($this->db, 8, $ann->charid), "someone else's hero");
        });
    }

    public function test_discarding_throws_away_as_many_as_asked() {
        $this->with_field_things(function () {
            [$ann] = $this->field_team();
            $this->assertSame('How many?', FieldUse::use($this->db, 7, $ann->charid, 'discard', 0, null, null, 3)[0]);
            [$problem, $message] = FieldUse::use($this->db, 7, $ann->charid, 'discard', 0, null, null, 1);
            $this->assertNull($problem);
            $this->assertSame('Ann threw away 1 Draught.', $message);
            $this->assertEquals([['item' => 750, 'qty' => 1]], $this->hero($ann->charid)->inventory);
            FieldUse::use($this->db, 7, $ann->charid, 'discard', 0, null, null, 1);
            $this->assertSame([], $this->hero($ann->charid)->inventory);
        });
    }

    public function test_a_draught_heals_the_hero_it_is_given_to_and_is_used_up() {
        $this->with_field_things(function () {
            [$ann, $bo] = $this->field_team();
            [$problem, $message] = FieldUse::use($this->db, 7, $ann->charid, 'item', 0, 0, 1);
            $this->assertNull($problem);
            $this->assertNotSame('', $message);
            $this->assertSame(25, $this->hero($bo->charid)->current['HP']);
            $this->assertSame(1, $this->hero($ann->charid)->inventory[0]['qty']);
        });
    }

    public function test_a_spell_for_everyone_needs_no_target_and_costs_mp() {
        $this->with_field_things(function () {
            [$ann, $bo] = $this->field_team();
            $mp = $ann->current['MP'];
            $mend = array_values(array_filter(FieldUse::options($ann)['spells'], fn($c) => $c['name'] === 'Mend All'))[0];
            [$problem] = FieldUse::use($this->db, 7, $ann->charid, 'spell', $mend['index'], null, null);
            $this->assertNull($problem);
            $this->assertSame(25, $this->hero($bo->charid)->current['HP']);
            $this->assertEquals($mp - 5, $this->hero($ann->charid)->current['MP']);
        });
    }

    public function test_giving_moves_the_whole_stack() {
        $this->with_field_things(function () {
            [$ann, $bo] = $this->field_team();
            [$problem] = FieldUse::use($this->db, 7, $ann->charid, 'give', 0, 0, 1);
            $this->assertNull($problem);
            $this->assertSame([], $this->hero($ann->charid)->inventory);
            $this->assertEquals([['item' => 750, 'qty' => 2]], $this->hero($bo->charid)->inventory);
        });
    }

    public function test_nothing_happens_for_what_was_not_offered() {
        $this->with_field_things(function () {
            [$ann, $bo] = $this->field_team();
            $fire = array_values(array_filter(FieldUse::options($ann)['spells'], fn($c) => $c['name'] === 'Fire'))[0];
            $this->assertSame("Can't be used outside a fight.", FieldUse::use($this->db, 7, $ann->charid, 'spell', $fire['index'], 0, 1)[0]);
            $this->assertSame('Pick who to aim at.', FieldUse::use($this->db, 7, $ann->charid, 'item', 0, null, null)[0]);
            $this->assertSame('Ann already has it.', FieldUse::use($this->db, 7, $ann->charid, 'give', 0, 0, 0)[0]);
            $this->assertSame('Ann can\'t do that.', FieldUse::use($this->db, 7, $ann->charid, 'item', 5, 0, 1)[0]);
            $this->assertSame("That hero isn't in your party.", FieldUse::use($this->db, 8, $ann->charid, 'item', 0, 0, 1)[0]);
            $bo_now = $this->hero($bo->charid);
            $bo_now->current['HP'] = 0;
            HERO::save_character($this->db, $bo_now);
            $this->assertSame('Bo is down.', FieldUse::use($this->db, 7, $ann->charid, 'item', 0, 0, 1)[0]);
            $this->assertSame(2, $this->hero($ann->charid)->inventory[0]['qty'], 'nothing used up');
        });
    }
}
