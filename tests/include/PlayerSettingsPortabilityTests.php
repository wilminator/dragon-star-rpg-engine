<?php

/**
 * A player's settings (player_settings.php): defaults until saved, saved
 * per account, and only known settings with sensible values kept.
 */
trait PlayerSettingsPortabilityTests {

    public function test_a_player_who_never_saved_gets_the_defaults() {
        $this->reset_tables(PlayerSettings::class);
        $this->assertSame(PlayerSettings::DEFAULTS, PlayerSettings::load($this->db, 42));
    }

    public function test_saving_keeps_each_players_own_settings_and_merges_changes() {
        $this->reset_tables(PlayerSettings::class);
        PlayerSettings::save($this->db, 42, ['music_volume' => 40, 'hide_gore' => true]);
        PlayerSettings::save($this->db, 42, ['sound_muted' => true]);
        PlayerSettings::save($this->db, 7, ['music_muted' => true]);

        $mine = PlayerSettings::load($this->db, 42);
        $this->assertSame(40, $mine['music_volume']);
        $this->assertTrue($mine['hide_gore']);
        $this->assertTrue($mine['sound_muted'], 'a later save adds to the earlier one');
        $this->assertFalse($mine['music_muted'], "someone else's setting isn't mine");
        $this->assertTrue(PlayerSettings::load($this->db, 7)['music_muted']);
    }

    public function test_only_known_settings_and_valid_values_are_kept() {
        $this->reset_tables(PlayerSettings::class);
        $saved = PlayerSettings::save($this->db, 42, [
            'music_volume' => 250, 'sound_volume' => -5, 'hide_actions' => 'yes', 'hide_hp_popups' => 1,
            'is_admin' => true, 'time_multiplier' => 9,
        ]);
        $this->assertSame(100, $saved['music_volume'], 'clamped to 100');
        $this->assertSame(0, $saved['sound_volume'], 'clamped to 0');
        $this->assertFalse($saved['hide_actions'], 'not a yes/no: left as it was');
        $this->assertTrue($saved['hide_hp_popups']);
        $this->assertSame(array_keys(PlayerSettings::DEFAULTS), array_keys($saved), 'nothing unknown is stored');
        $this->assertSame($saved, PlayerSettings::load($this->db, 42));
    }

    public function test_a_page_gets_the_settings_as_js_and_a_visitor_gets_the_defaults() {
        $this->reset_tables(PlayerSettings::class);
        PlayerSettings::save($this->db, 42, ['music_volume' => 30]);
        $this->assertSame(30, json_decode(PlayerSettings::to_js($this->db, 42), true)['music_volume']);
        $this->assertEquals(PlayerSettings::DEFAULTS, json_decode(PlayerSettings::to_js($this->db, null), true), 'JSON writes 1.0 as 1');
    }

    // --- round time ---

    public function test_round_time_takes_only_the_offered_multipliers() {
        $this->reset_tables(PlayerSettings::class);
        $this->assertSame(1.5, PlayerSettings::save($this->db, 42, ['time_multiplier' => 1.5])['time_multiplier']);
        $this->assertSame(1.5, PlayerSettings::save($this->db, 42, ['time_multiplier' => 3])['time_multiplier'], '3 is not offered');
        $this->assertSame(1.5, PlayerSettings::save($this->db, 42, ['time_multiplier' => 'slow'])['time_multiplier']);
        $this->assertSame(2.0, PlayerSettings::save($this->db, 42, ['time_multiplier' => '2'])['time_multiplier']);
        $this->assertSame(2.0, PlayerSettings::load($this->db, 42)['time_multiplier']);
        $this->assertSame(0.0, PlayerSettings::hp_bonus(1.0));
        $this->assertSame(0.05, PlayerSettings::hp_bonus(1.5));
        $this->assertSame(0.10, PlayerSettings::hp_bonus(2.0));
    }

    private function time_fight(): FIGHT {
        $fight = new FIGHT('bg', 'music');
        $heroes = new PARTY();
        $hero = CHARACTER::create_hero('Hero', 1, 1, null);
        $hero->teamid = 5;
        $hero->base['HP'] = 30;
        $hero->current['HP'] = 30;
        $group = new GROUP();
        $group->add_hero($hero);
        $heroes->add($group);
        $monsters = new PARTY();
        $monsters->monster = true;
        $monster_group = new GROUP();
        foreach ([[200, 100], [55, 55]] as [$max, $now]) {
            $monster = CHARACTER::create_hero('Monster', 999, 1, null);
            $monster->teamid = CHARACTER::MONSTER_TEAMID;
            $monster->base['HP'] = $max;
            $monster->current['HP'] = $now;
            $monster_group->add_hero($monster);
        }
        $monsters->add($monster_group);
        $fight->add($heroes);
        $fight->add($monsters);
        return $fight;
    }

    private function monster_hp(FIGHT $fight): array {
        return array_map(fn($m) => [$m->base['HP'], $m->current['HP']], $fight->parties[1]->groups[0]->characters);
    }

    public function test_a_hero_player_asking_for_more_time_makes_the_monsters_tougher() {
        $this->reset_tables(PlayerSettings::class);
        PlayerSettings::save($this->db, 42, ['time_multiplier' => 2]);
        $fight = $this->time_fight();
        $pxp_before = $fight->parties[1]->groups[0]->characters[0]->calculate_pxp();
        FightSetup::apply_time_settings($this->db, $fight, [['playerid' => 42, 'teamid' => 5, 'player_party' => 0]]);
        $this->assertSame(2.0, $fight->time_multiplier);
        $this->assertSame([[220, 110], [61, 61]], $this->monster_hp($fight), '+10% max HP, the same share of it');
        $this->assertGreaterThan($pxp_before, $fight->parties[1]->groups[0]->characters[0]->pxp, 'worth more PXP');
        $this->assertSame(30, $fight->parties[0]->groups[0]->characters[0]->base['HP'], 'the heroes are untouched');
    }

    public function test_a_monster_player_asking_for_more_time_costs_nobody_hp() {
        $this->reset_tables(PlayerSettings::class);
        PlayerSettings::save($this->db, 77, ['time_multiplier' => 1.5]);
        $fight = $this->time_fight();
        $heroes_before = $fight->parties[0]->groups[0]->characters[0]->base['HP'];
        FightSetup::apply_time_settings($this->db, $fight, [
            ['playerid' => 42, 'teamid' => 5, 'player_party' => 0],
            ['playerid' => 77, 'teamid' => CHARACTER::MONSTER_TEAMID, 'player_party' => 1],
        ]);
        $this->assertSame(1.5, $fight->time_multiplier, 'the highest anyone asked for');
        $this->assertSame([[200, 100], [55, 55]], $this->monster_hp($fight));
        $this->assertSame($heroes_before, $fight->parties[0]->groups[0]->characters[0]->base['HP']);
    }

    public function test_the_hero_side_decides_the_hp_even_when_a_monster_player_asks_for_more_time() {
        $this->reset_tables(PlayerSettings::class);
        PlayerSettings::save($this->db, 42, ['time_multiplier' => 1.5]);
        PlayerSettings::save($this->db, 77, ['time_multiplier' => 2]);
        $fight = $this->time_fight();
        FightSetup::apply_time_settings($this->db, $fight, [
            ['playerid' => 42, 'teamid' => 5, 'player_party' => 0],
            ['playerid' => 77, 'teamid' => CHARACTER::MONSTER_TEAMID, 'player_party' => 1],
        ]);
        $this->assertSame(2.0, $fight->time_multiplier);
        $this->assertSame([[210, 105], [58, 58]], $this->monster_hp($fight), "+5%: the hero player's choice");
    }

    public function test_round_time_scales_and_survives_saving_the_fight() {
        $this->reset_tables(PlayerSettings::class);
        $fight = $this->time_fight();
        $states = [new FightPlayerState(1, 42, 5, 0, true, 0)];
        $this->assertSame(40, FightCommunicator::command_timeout_seconds($fight, $states));
        $fight->time_multiplier = 1.5;
        $this->assertSame(60, FightCommunicator::command_timeout_seconds($fight, $states));
        $again = FIGHT::hydrate($fight->dehydrate());
        $this->assertSame(1.5, $again->time_multiplier);
        $this->assertSame(1.0, FIGHT::hydrate(array_diff_key($fight->dehydrate(), ['time_multiplier' => 0]))->time_multiplier,
            'fights saved before the setting read as normal time');
    }

    public function test_controls_maps_keep_known_actions_and_sensible_values() {
        $this->reset_tables(PlayerSettings::class);
        $saved = PlayerSettings::save($this->db, 42, [
            'key_map' => (object) ['up' => 'w', 'commit' => 'x', 'fly' => 'f', 'back' => '<script>', 'down' => ''],
            'pad_map' => ['confirm' => 2, 'commit' => '9', 'settings' => 99, 'nonsense' => 1],
        ]);
        $this->assertSame(['up' => 'w', 'commit' => 'x'], $saved['key_map'], 'known actions, harmless key names');
        $this->assertSame(['confirm' => 2, 'commit' => 9], $saved['pad_map'], 'buttons 0-31');
        $this->assertSame($saved['key_map'], PlayerSettings::load($this->db, 42)['key_map']);
        $this->assertSame([], PlayerSettings::save($this->db, 42, ['key_map' => []])['key_map'], 'reset');
    }
}
