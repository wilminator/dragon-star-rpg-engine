<?php

/**
 * Equipping from the hub (EquipmentChange): the field action the old
 * equip page uses, on the player's own hero, saved -- and nothing for a
 * hero, item or slot the equip dialog couldn't have offered.
 */
trait EquipmentChangePortabilityTests {

    private function armed_hero(int $playerid = 7): CHARACTER {
        $this->reset_tables(PartyState::class, TEAM::class, HERO::class, MonsterToken::class, Alliance::class,
            FIGHT::class, FightPlayerState::class);
        $this->reset_hero_cache();
        $team = TEAM::create($this->db, $playerid, 'Armed', [['name' => 'Al', 'jobid' => 1, 'personalityid' => 1]]);
        $hero = $team->characters[array_key_first($team->characters)];
        $hero->inventory = [['item' => 2, 'qty' => 1], ['item' => 3, 'qty' => 1], ['item' => 1, 'qty' => 2]];
        HERO::save_character($this->db, $hero);
        PartyState::choose_team($this->db, $playerid, $team->teamid);
        return $hero;
    }

    private function reload(CHARACTER $hero): CHARACTER {
        $this->reset_hero_cache();
        return HERO::load_character($this->db, $hero->charid);
    }

    public function test_equipping_puts_the_item_where_asked_and_saves_it() {
        $hero = $this->armed_hero();
        [$problem] = EquipmentChange::make($this->db, 7, $hero->charid, 0, 'rhand');
        $this->assertNull($problem);
        $saved = $this->reload($hero);
        $this->assertSame(0, $saved->equipment['rhand'], 'the right hand, as picked');
        $this->assertNull($saved->equipment['lhand']);

        [$problem] = EquipmentChange::make($this->db, 7, $hero->charid, 1, 'body');
        $this->assertNull($problem);
        $this->assertSame(1, $this->reload($hero)->equipment['body']);
    }

    public function test_taking_off_empties_the_slot() {
        $hero = $this->armed_hero();
        EquipmentChange::make($this->db, 7, $hero->charid, 0, 'lhand');
        [$problem] = EquipmentChange::make($this->db, 7, $hero->charid, -1, 'lhand');
        $this->assertNull($problem);
        $this->assertNull($this->reload($hero)->equipment['lhand']);
    }

    public function test_nothing_changes_for_what_the_dialog_could_not_offer() {
        $hero = $this->armed_hero();
        $this->assertSame("That can't go there.", EquipmentChange::make($this->db, 7, $hero->charid, 2, 'body')[0], 'a potion');
        $this->assertSame("That can't go there.", EquipmentChange::make($this->db, 7, $hero->charid, 0, 'head')[0]);
        $this->assertSame("That can't go there.", EquipmentChange::make($this->db, 7, $hero->charid, -1, 'feet')[0], 'nothing there');
        $this->assertSame("That hero isn't in your party.", EquipmentChange::make($this->db, 8, $hero->charid, 0, 'lhand')[0]);
        $saved = $this->reload($hero);
        $this->assertSame([], array_filter($saved->equipment, fn($index) => $index !== null));
    }
}
