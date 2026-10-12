<?php
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'inn.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'team_management.php';

/**
 * What the hub (app.php) shows, for its server calls: the party in
 * formation and each team's purse. Only the player's own heroes, and
 * only what the hub draws.
 *
 *   ['teams'  => [['teamid', 'name', 'gold', 'inn_price'], ...] lead first,
 *    'groups' => [[['charid', 'name', 'job', 'level', 'hp' => [now, most],
 *                   'mp' => [now, most], 'picture', 'team'], ...], ...],
 *    'alliance' => null or ['seconds_left', 'teams' => [names]],
 *    'can_rest' => bool,
 *    'needs' => [hero name => ['job' and/or 'look']]  (see TeamManagement)]
 */
final class HubState {
    public static function for_player(Database $db, int $playerid): ?array {
        $party = PartyState::party($db, $playerid);
        if ($party === null) {
            return null;
        }
        $teams = [];
        $team_objects = [];
        foreach (array_keys($party->teams) as $teamid) {
            $team = TEAM::load($db, (int) $teamid);
            $team_objects[] = $team;
            $teams[] = ['teamid' => $team->teamid, 'name' => $team->name, 'gold' => $team->gold, 'inn_price' => Inn::price($team)];
        }
        $groups = [];
        foreach ($party->groups as $group) {
            $heroes = [];
            foreach ($group->characters as $character) {
                $heroes[] = self::hero($character);
            }
            $groups[] = $heroes;
        }
        $alliance = Alliance::load($db, $playerid);
        return [
            'teams' => $teams,
            'groups' => $groups,
            'alliance' => $alliance !== null && $alliance->is_active() && count($teams) > 1
                ? ['seconds_left' => $alliance->seconds_left, 'teams' => array_column(array_slice($teams, 1), 'name')]
                : null,
            'can_rest' => Inn::short($team_objects) === [],
            // Heroes whose job or look is no longer offered: name => ['job', 'look']
            'needs' => TeamManagement::needs($party),
        ];
    }

    private static function hero(CHARACTER $character): array {
        try {
            $job = JOB::get((int) $character->jobid)->name;
        } catch (\Throwable $e) {
            $job = 'Unknown';
        }
        try {
            $picture = PERSONALITY::get((int) $character->personalityid)->base->images[0] ?? '';
        } catch (\Throwable $e) {
            $picture = '';
        }
        return [
            'charid' => $character->charid,
            'name' => $character->name,
            'job' => $job,
            'level' => $character->level,
            'hp' => [(int) $character->current['HP'], (int) $character->base['HP']],
            'mp' => [(int) $character->current['MP'], (int) $character->base['MP']],
            'picture' => $picture,
            'team' => $character->teamid,
        ];
    }
}
