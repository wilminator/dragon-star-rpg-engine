<?php

/**
 * Managing heroes from the hub (TeamManagement): a new job for a hero
 * past level 1 (keeping level% of their stats), a free new job for one
 * whose job is gone, and a new look only for one whose look is gone.
 */
trait TeamManagementPortabilityTests {
    use AugmentsFixtureCache;

    // Job 4 and look 2, both retired.
    private function with_retired(callable $callback): mixed {
        $stats = array_fill_keys(["HP", "MP", "Speed", "Accuracy", "Strength", "Dodge", "Block", "Power", "Resistance", "Focus"], 55);
        $job = build_job_row(4, 'Retired Job', 100, $stats);
        $job['state'] = 1;
        $look = build_personality_row(2, 'Retired Look', 'bandit.png');
        $look['state'] = 1;
        return $this->with_extra_cache($callback, jobs: [4 => $job], personalities: [2 => $look]);
    }

    private function managed_team(): array {
        $this->reset_tables(PartyState::class, TEAM::class, HERO::class, MonsterToken::class, Alliance::class,
            FIGHT::class, FightPlayerState::class);
        $this->reset_hero_cache();
        $team = TEAM::create($this->db, 7, 'Managed', [
            ['name' => 'Vet', 'jobid' => 2, 'personalityid' => 1],
            ['name' => 'Newbie', 'jobid' => 2, 'personalityid' => 1],
        ]);
        [$vet, $newbie] = array_values($team->characters);
        $vet->level = 40;
        HERO::save_character($this->db, $vet);
        PartyState::choose_team($this->db, 7, $team->teamid);
        return [$vet, $newbie];
    }

    private function reloaded(CHARACTER $hero): CHARACTER {
        $this->reset_hero_cache();
        return HERO::load_character($this->db, $hero->charid);
    }

    public function test_the_options_say_who_may_change_what() {
        [$vet, $newbie] = $this->managed_team();
        $options = TeamManagement::options($this->db, 7);
        $rows = array_map(fn($h) => [$h['name'], $h['can_change_job'], $h['kept'], $h['can_change_look']], $options['heroes']);
        $this->assertSame([['Vet', true, 40, false], ['Newbie', false, 1, false]], $rows);
        $this->assertSame([[3, 'Ability Granter'], [2, 'Deterministic Warrior'], [1, 'Zero-Growth Warrior']], $options['jobs'], 'by name');
        $this->assertSame('Fixture Personality', $options['looks'][0][1]);
        $this->assertSame(FIGHTER_IMAGES_DIR . PERSONALITY::get(1)->base->images[0], $options['looks'][0][2]);
        $this->assertNull(TeamManagement::options($this->db, 8));
    }

    public function test_a_new_job_starts_over_at_level_1_keeping_level_percent() {
        [$vet] = $this->managed_team();
        $strength = $vet->base['Strength'];
        [$problem, $message] = TeamManagement::change_job($this->db, 7, $vet->charid, 3);
        $this->assertNull($problem);
        $this->assertSame("Vet's new job: Ability Granter.", $message);
        $after = $this->reloaded($vet);
        $this->assertSame(3, (int) $after->jobid);
        $this->assertSame(1, (int) $after->level);
        $this->assertEquals(round($strength * 0.4), $after->base['Strength']);
    }

    public function test_a_level_1_hero_keeps_their_job() {
        [, $newbie] = $this->managed_team();
        $this->assertSame('Newbie needs to reach level 2 first.', TeamManagement::change_job($this->db, 7, $newbie->charid, 3)[0]);
        $this->assertSame('Newbie already has that job.', TeamManagement::change_job($this->db, 7, $newbie->charid, 2)[0]);
        $this->assertSame("That job isn't offered.", TeamManagement::change_job($this->db, 7, $newbie->charid, 99)[0]);
        $this->assertSame("That hero isn't in your party.", TeamManagement::change_job($this->db, 8, $newbie->charid, 3)[0]);
    }

    public function test_a_hero_whose_job_is_gone_picks_a_new_one_for_free() {
        $this->with_retired(function () {
            [$vet, $newbie] = $this->managed_team();
            $hero = $this->reloaded($newbie);
            $hero->jobid = 4;
            HERO::save_character($this->db, $hero);
            $strength = $hero->base['Strength'];
            $this->assertSame(['Newbie' => ['job']], TeamManagement::needs(PartyState::party($this->db, 7)));
            $options = TeamManagement::options($this->db, 7);
            $this->assertSame([true, 100, 'No longer offered'], [$options['heroes'][1]['can_change_job'], $options['heroes'][1]['kept'], $options['heroes'][1]['job']]);
            $this->assertNull(TeamManagement::change_job($this->db, 7, $newbie->charid, 3)[0]);
            $this->assertEquals($strength, $this->reloaded($newbie)->base['Strength'], 'nothing lost');
            $this->assertSame([], TeamManagement::needs(PartyState::party($this->db, 7)));
        });
    }

    public function test_only_a_hero_whose_look_is_gone_may_change_it() {
        $this->with_retired(function () {
            [$vet] = $this->managed_team();
            $this->assertSame("Vet's look can't be changed.", TeamManagement::change_look($this->db, 7, $vet->charid, 1)[0]);
            $hero = $this->reloaded($vet);
            $hero->personalityid = 2;
            HERO::save_character($this->db, $hero);
            $this->assertSame(['Vet' => ['look']], TeamManagement::needs(PartyState::party($this->db, 7)));
            $this->assertSame("That look isn't offered.", TeamManagement::change_look($this->db, 7, $vet->charid, 2)[0]);
            [$problem, $message] = TeamManagement::change_look($this->db, 7, $vet->charid, 1);
            $this->assertNull($problem);
            $this->assertSame('Vet now looks like Fixture Personality.', $message);
            $this->assertSame(1, (int) $this->reloaded($vet)->personalityid);
        });
    }
}
