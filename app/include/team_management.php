<?php
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'paths.php';

/**
 * Managing the heroes of the party's teams from the hub (what
 * manage_team.php once did for an existing team; it now only makes new
 * teams, from team selection). The same rules as that page:
 *
 *   a new job   a hero past level 1 may change job: back to level 1,
 *               keeping level% of their stats (none lost from level 100
 *               on). A hero whose job is no longer offered must pick a
 *               new one, and loses nothing.
 *   a new look  only for a hero whose look is no longer offered.
 */
final class TeamManagement {
    // Active jobs and looks, by name: [id => name], [id => [name, picture]].
    public static function jobs(): array {
        $jobs = array_filter(JOB::load_all_from_cache(), fn($job) => $job->state === ObjectState::ACTIVE);
        $list = array_map(fn($job) => $job->name, $jobs);
        asort($list);
        return $list;
    }

    public static function looks(): array {
        $looks = array_filter(PERSONALITY::load_all_from_cache(), fn($p) => $p->state === ObjectState::ACTIVE);
        uasort($looks, fn($a, $b) => strcmp($a->name, $b->name));
        return array_map(fn($p) => ['name' => $p->name, 'picture' => FIGHTER_IMAGES_DIR . ($p->base->images[0] ?? '')], $looks);
    }

    // Stats kept on a change to a new job, in percent.
    public static function kept(CHARACTER $hero): int {
        if (!array_key_exists($hero->jobid, self::jobs())) {
            return 100; // their job is gone: a free change
        }
        return $hero->level >= 100 ? 100 : (int) $hero->level;
    }

    // What the dialog shows: every hero in the party, with what can be
    // changed, and the choices.
    //   ['heroes' => [['charid', 'name', 'level', 'job', 'job_ok', 'look',
    //     'look_ok', 'picture', 'can_change_job', 'kept',
    //     'can_change_look'], ...], 'jobs' => [[id, name], ...],
    //    'looks' => [[id, name, picture], ...]]
    public static function options(Database $db, int $playerid): ?array {
        $party = PartyState::party($db, $playerid);
        if ($party === null) {
            return null;
        }
        $jobs = self::jobs();
        $looks = self::looks();
        $heroes = [];
        foreach ($party->groups as $group) {
            foreach ($group->characters as $hero) {
                $job_ok = array_key_exists($hero->jobid, $jobs);
                $look_ok = array_key_exists($hero->personalityid, $looks);
                $heroes[] = [
                    'charid' => $hero->charid, 'name' => $hero->name, 'level' => (int) $hero->level,
                    'job' => $job_ok ? $jobs[$hero->jobid] : 'No longer offered', 'job_ok' => $job_ok,
                    'look' => $look_ok ? $looks[$hero->personalityid]['name'] : 'No longer offered', 'look_ok' => $look_ok,
                    'picture' => $look_ok ? $looks[$hero->personalityid]['picture'] : '',
                    'can_change_job' => !$job_ok || $hero->level > 1,
                    'kept' => self::kept($hero),
                    'can_change_look' => !$look_ok,
                ];
            }
        }
        $job_rows = [];
        foreach ($jobs as $id => $name) {
            $job_rows[] = [$id, $name];
        }
        $look_rows = [];
        foreach ($looks as $id => $look) {
            $look_rows[] = [$id, $look['name'], $look['picture']];
        }
        return ['heroes' => $heroes, 'jobs' => $job_rows, 'looks' => $look_rows];
    }

    // Heroes who must pick a new job or look before they can play:
    // [name => [what, ...]].
    public static function needs(PARTY $party): array {
        $jobs = self::jobs();
        $looks = self::looks();
        $needs = [];
        foreach ($party->groups as $group) {
            foreach ($group->characters as $hero) {
                if (!array_key_exists($hero->jobid, $jobs)) $needs[$hero->name][] = 'job';
                if (!array_key_exists($hero->personalityid, $looks)) $needs[$hero->name][] = 'look';
            }
        }
        return $needs;
    }

    private static function hero(Database $db, int $playerid, int $charid): array {
        if ((new FightCommunicator($db, $playerid))->is_in_active_fight()) {
            return ['Finish your fight first.', null];
        }
        $party = PartyState::party($db, $playerid);
        $position = $party?->get_character_position_by_id($charid);
        if ($position === null) {
            return ["That hero isn't in your party.", null];
        }
        return [null, $party->groups[$position[0]]->characters[$position[1]]];
    }

    // [problem or null, message]
    public static function change_job(Database $db, int $playerid, int $charid, int $jobid): array {
        [$problem, $hero] = self::hero($db, $playerid, $charid);
        if ($problem !== null) {
            return [$problem, ''];
        }
        $jobs = self::jobs();
        if (!array_key_exists($jobid, $jobs)) {
            return ["That job isn't offered.", ''];
        }
        if ($jobid === (int) $hero->jobid) {
            return ["{$hero->name} already has that job.", ''];
        }
        $free = !array_key_exists($hero->jobid, $jobs);
        if (!$free && $hero->level <= 1) {
            return ["{$hero->name} needs to reach level 2 first.", ''];
        }
        $hero->jobid = $jobid;
        JOB::get($jobid)->don($hero, no_reset: $free);
        HERO::save_character($db, $hero);
        return [null, "{$hero->name}'s new job: {$jobs[$jobid]}."];
    }

    // [problem or null, message]
    public static function change_look(Database $db, int $playerid, int $charid, int $personalityid): array {
        [$problem, $hero] = self::hero($db, $playerid, $charid);
        if ($problem !== null) {
            return [$problem, ''];
        }
        $looks = self::looks();
        if (!array_key_exists($personalityid, $looks)) {
            return ["That look isn't offered.", ''];
        }
        if (array_key_exists($hero->personalityid, $looks)) {
            return ["{$hero->name}'s look can't be changed.", ''];
        }
        $hero->personalityid = $personalityid;
        HERO::save_character($db, $hero);
        return [null, "{$hero->name} now looks like {$looks[$personalityid]['name']}."];
    }
}
