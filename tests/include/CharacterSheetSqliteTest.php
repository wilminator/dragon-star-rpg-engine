<?php

require_once __DIR__ . '/../SqliteTestCase.php';
require_once INCLUDE_GAME . 'character_sheet.php';

/**
 * The status and equipment-benefits dialogs' data (CharacterSheet): whose
 * heroes may be read, what the sheet holds, and previews that change a
 * copy, never the hero.
 */
class CharacterSheetSqliteTest extends SqliteTestCase {
    use AugmentsFixtureCache;

    private function hero(): CHARACTER {
        $hero = CHARACTER::create_hero('Sir Test', 1, 1, 1);
        $hero->inventory = [['item' => 2, 'qty' => 1], ['item' => 3, 'qty' => 1], ['item' => 740, 'qty' => 1], ['item' => 1, 'qty' => 3]];
        return $hero;
    }

    private function with_strong_armor(callable $callback): mixed {
        $row = build_item_row(740, 'Strong Armor', ['body']);
        $zero = array_fill_keys(CharacterSheet::STATS, 0);
        $row['stat_increase'] = json_encode(['Strength' => 5] + $zero);
        $row['stat_percent_increase'] = json_encode($zero);
        return $this->with_extra_cache($callback, items: [740 => $row]);
    }

    public function test_only_the_players_own_heroes_can_be_read() {
        $this->reset_tables(TEAM::class, HERO::class);
        $this->reset_hero_cache();
        $team = TEAM::create($this->db, 42, 'Mine', [['name' => 'Al', 'jobid' => 1, 'personalityid' => 1]]);
        $charid = $team->characters[0]->charid;
        $this->assertNotNull(CharacterSheet::for_player($this->db, 42, $charid));
        $this->assertNull(CharacterSheet::for_player($this->db, 43, $charid), "someone else's hero");
        $this->assertNull(CharacterSheet::for_player($this->db, 42, 99999), 'no such hero');
    }

    public function test_the_sheet_has_the_job_level_stats_and_what_is_worn_where() {
        $this->with_strong_armor(function () {
            $hero = $this->hero();
            $this->assertSame(EquipOutcome::SUCCESS, $hero->equip_item(0, 1)->outcome);
            $sheet = CharacterSheet::sheet($hero);
            $this->assertSame('Sir Test', $sheet['name']);
            $this->assertSame(JOB::get(1)->name, $sheet['job']);
            $this->assertSame(1, $sheet['level']);
            $this->assertSame(CharacterSheet::STATS, array_keys($sheet['stats']));
            $this->assertCount(2, $sheet['stats']['HP'], 'current and most');
            $this->assertSame('Basic Sword', $sheet['equipment']['rhand'], 'side 1 is the right hand');
            $this->assertNull($sheet['equipment']['lhand']);
        });
    }

    public function test_a_preview_shows_the_change_without_making_it() {
        $this->with_strong_armor(function () {
            $hero = $this->hero();
            $strength = $hero->get_current('Strength', true);
            $preview = CharacterSheet::preview($hero, 2, 'body');
            $this->assertSame($strength, $preview['before']['Strength']);
            $this->assertSame($strength + 5, $preview['after']['Strength']);
            $this->assertSame(['body'], $preview['fills']);
            $this->assertSame([], $preview['empties']);
            $this->assertNull($hero->equipment['body'], 'the hero itself is untouched');
        });
    }

    public function test_a_preview_names_what_would_come_off() {
        $this->with_strong_armor(function () {
            $hero = $this->hero();
            $hero->equip_item(1, 0);
            $preview = CharacterSheet::preview($hero, 2, 'body');
            $this->assertSame(['body'], $preview['fills']);
            $this->assertSame(['body'], $preview['empties'], 'the Basic Armor comes off');
            $this->assertSame(1, $hero->equipment['body'], 'still wearing it for real');

            $off = CharacterSheet::preview($hero, -1, 'body');
            $this->assertSame([], $off['fills']);
            $this->assertSame(['body'], $off['empties']);
        });
    }

    public function test_the_hand_picked_is_the_hand_filled() {
        $this->with_strong_armor(function () {
            $hero = $this->hero();
            $this->assertSame(['lhand'], CharacterSheet::preview($hero, 0, 'lhand')['fills']);
            $this->assertSame(['rhand'], CharacterSheet::preview($hero, 0, 'rhand')['fills']);
        });
    }

    public function test_the_sheet_carries_what_the_equip_dialog_needs() {
        $this->with_strong_armor(function () {
            $hero = $this->hero();
            $hero->equip_item(0, 0);
            $sheet = CharacterSheet::sheet($hero);
            $this->assertSame(0, $sheet['worn']['lhand']);
            $this->assertNull($sheet['worn']['rhand']);
            $this->assertSame(['item' => 740, 'qty' => 1], $sheet['inventory'][2]);
        });
    }

    public function test_taking_off_a_two_handed_bow_takes_its_arrows_too() {
        $bow = build_item_row(741, 'Long Bow', ['lhand', 'rhand'], ammo_type: 'arrow');
        $arrows = build_item_row(742, 'Arrows', ['ammo'], ammo_type: 'arrow');
        $this->with_extra_cache(function () {
            $hero = CHARACTER::create_hero('Archer', 1, 1, 1);
            $hero->inventory = [['item' => 741, 'qty' => 1], ['item' => 742, 'qty' => 20]];
            $this->assertSame(EquipOutcome::SUCCESS, $hero->equip_item(0, 0)->outcome);
            $this->assertSame(['lammo'], $hero->equip_item(1, 1)->equipped_slots, "a two-handed weapon's ammo goes left");
            $off = $hero->unequip_item(0);
            $this->assertEqualsCanonicalizing(['lhand', 'rhand', 'lammo'], $off->equipped_slots);
            $this->assertNull($hero->equipment['lammo'], 'no arrows left behind');
        }, items: [741 => $bow, 742 => $arrows]);
    }

    public function test_impossible_previews_are_null() {
        $this->with_strong_armor(function () {
            $hero = $this->hero();
            $this->assertNull(CharacterSheet::preview($hero, 3, 'body'), 'a potion is not worn');
            $this->assertNull(CharacterSheet::preview($hero, 0, 'nowhere'));
            $this->assertNull(CharacterSheet::preview($hero, 0, 'head'), 'a sword is not a hat');
            $this->assertNull(CharacterSheet::preview($hero, -1, 'head'), 'nothing to take off');
        });
    }
}
