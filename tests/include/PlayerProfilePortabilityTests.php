<?php

/**
 * Public profiles (PlayerProfile): handles, switches, search, and what
 * the public page shows.
 */
trait PlayerProfilePortabilityTests {

    private function reset_profiles(): void {
        $this->reset_tables(PlayerProfile::class, UserAccount::class, PLAYER::class, TEAM::class, HERO::class, FightSave::class,
            FIGHT::class, FightSemaphore::class, JOB::class);
    }

    private function account(string $username, string $email): UserAccount {
        $account = UserAccount::create($this->db, $username, 'correct horse battery staple', $email);
        return $account;
    }

    public function test_a_handle_is_checked() {
        $this->reset_profiles();
        $al = $this->account('alfred', 'al.b@example.com');
        $this->assertNotNull(PlayerProfile::update($this->db, $al, 'no', false, false), 'too short');
        $this->assertNotNull(PlayerProfile::update($this->db, $al, 'has space', false, false));
        $this->assertNotNull(PlayerProfile::update($this->db, $al, 'ALFRED', false, false), 'the username, any case');
        $this->assertNotNull(PlayerProfile::update($this->db, $al, 'al.b@example.com', false, false), 'the email');
        $this->assertNull(PlayerProfile::update($this->db, $al, 'AL_B', false, false), 'only the whole local part is barred');
        $this->assertNull(PlayerProfile::update($this->db, $al, 'Dragon_Slayer', false, false));
        $this->assertSame('Dragon_Slayer', PlayerProfile::load($this->db, $al->userid)->handle);

        $bea = $this->account('bea', 'bea@example.com');
        $this->assertSame('That handle is taken.', PlayerProfile::update($this->db, $bea, 'dragon_slayer', false, false));
        $this->assertNull(PlayerProfile::update($this->db, $al, 'dragon_SLAYER', false, false), 'your own, recased');
    }

    public function test_the_email_local_part_is_barred() {
        $this->reset_profiles();
        $al = $this->account('alfred', 'dragonfan@example.com');
        $this->assertNotNull(PlayerProfile::update($this->db, $al, 'DragonFan', false, false));
    }

    public function test_public_needs_a_handle_and_search_needs_public() {
        $this->reset_profiles();
        $al = $this->account('alfred', 'al@example.com');
        $this->assertNotNull(PlayerProfile::update($this->db, $al, '', true, true));
        $this->assertNull(PlayerProfile::update($this->db, $al, 'Knight', false, true));
        $this->assertFalse(PlayerProfile::load($this->db, $al->userid)->searchable, 'not listed while private');
        $this->assertNull(PlayerProfile::update($this->db, $al, 'Knight', true, false));
        $profile = PlayerProfile::load($this->db, $al->userid);
        $this->assertSame([true, false], [$profile->is_public, $profile->searchable]);
    }

    public function test_search_finds_only_public_listed_profiles() {
        $this->reset_profiles();
        $listed = $this->account('one', 'one@example.com');
        $unlisted = $this->account('two', 'two@example.com');
        $private = $this->account('three', 'three@example.com');
        PlayerProfile::update($this->db, $listed, 'DragonOne', true, true);
        PlayerProfile::update($this->db, $unlisted, 'DragonTwo', true, false);
        PlayerProfile::update($this->db, $private, 'DragonThree', false, false);

        $found = PlayerProfile::search($this->db, 'drag');
        $this->assertSame(['DragonOne'], array_column($found, 'handle'));
        $this->assertSame(PLAYER::guid_for($this->db, $listed->userid), $found[0]['guid']);
        $this->assertSame([], PlayerProfile::search($this->db, 'd'), 'too short');
        $this->assertSame([], PlayerProfile::search($this->db, '%'), 'wildcards match literally');
        $this->assertSame([], PlayerProfile::search($this->db, 'n_O'), 'an underscore matches only itself');
    }

    public function test_the_public_page_shows_teams_heroes_and_showcase_fights() {
        $this->reset_profiles();
        $al = $this->account('alfred', 'al@example.com');
        $guid = PLAYER::guid_for($this->db, $al->userid);
        $this->assertNull(PlayerProfile::public_view($this->db, $guid), 'private by default');
        PlayerProfile::update($this->db, $al, 'Knight', true, false);

        $team = TEAM::create($this->db, $al->userid, 'Red', [['name' => 'Sir A', 'jobid' => 1, 'personalityid' => 1]]);
        $fightids = [];
        foreach ([100, 200, 300, 400] as $created) {
            $fight = new FIGHT('bg', 'music');
            $fight->save($this->db);
            $this->db->prepare("UPDATE fights SET created_at = ? WHERE id = ?")->execute([$created, $fight->id]);
            $fightids[] = $fight->id;
        }
        FightSave::record_user_history($this->db, $fightids[0], $team->teamid);
        FightSave::retain($this->db, $fightids[1], $team->teamid);
        FightSave::consider_for_underdog($this->db, $fightids[2], $team->teamid, 240, 100);
        FightSave::consider_for_overkill($this->db, $fightids[3], $team->teamid, 130, 100);

        $view = PlayerProfile::public_view($this->db, $guid);
        $this->assertSame('Knight', $view['handle']);
        $this->assertSame([['name' => 'Sir A', 'job' => JOB::load_all_from_database($this->db)[1]->name ?? 'Unknown', 'level' => 1]],
            $view['teams'][0]['heroes']);
        $fights = $view['teams'][0]['fights'];
        $this->assertSame(['Overkill', 'Underdog win', 'Kept'], array_column($fights, 'label'), 'newest first; the rolling history is not shown');
        $this->assertSame('the monsters were 2.4× as strong', $fights[1]['rating']);
        $this->assertSame('overkill rating 1.30', $fights[0]['rating']);
        $this->assertStringContainsString('&team=' . $team->guid, $fights[2]['url']);
        $this->assertNull(PlayerProfile::public_view($this->db, str_repeat('0', 32)));
    }
}
