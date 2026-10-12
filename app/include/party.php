<?php

/**
 * party.php
 * party object
 * @version 0.1.0
 * @copyright 2003 Mike Wilmes
 **/

require_once INCLUDE_GAME . 'group.php';
require_once INCLUDE_GAME . 'hero.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'pointer_index.php';
require_once INCLUDE_GAME . 'action_target.php';

// Groups in a player's party: four of GROUP::MAX_COUNT (5), room for 20.
define('PARTY_MAX_COUNT', 4);

class PARTY implements ActionScope, DehydrateInterface {
    use DehydrateTrait;

    public array $groups;
    public array $teams;
    public array $team_names;
    public string $name;
    public array $allies;
    public array $enemies;
    public bool $monster;
    #var $location;

    function __construct() {
        $this->groups = array();
        $this->teams = array();
        $this->team_names = array();
        $this->name = '';
        $this->allies = array();
        $this->enemies = array();
        $this->monster = false;
    }

    function add(GROUP $group): bool {
        if (count($this->groups) < GROUP::MAX_COUNT) {
            $this->groups[count($this->groups)] = $group;
            if ($group->name == '' && count($group->characters) > 0)
                $group->name = $group->characters[0]->name . "'s group";
            return false;
        }
        return true;
    }

    function remove(int $index): bool {
        if ($index < $this->count()) {
            array_splice($this->groups, $index, 1);
            $this->groups = array_merge($this->groups);
            return false;
        }
        return true;
    }

    function count(): int {
        return count($this->groups);
    }

    function to_js(): string {
        foreach ($this->groups as $index => $group)
            $output[$index] = $group->to_js();
        $output = "[" . implode(",", $output) . "]";
        return "{groups:$output}";
    }

    const DEHYDRATE_VERSION = 1;
    private const DEHYDRATE_PROPERTIES = ['teams', 'team_names', 'name', 'allies', 'enemies', 'monster'];

    protected function dehydrate_nested(): array {
        return ['groups' => array_map(fn($group) => $group->dehydrate(), $this->groups)];
    }

    protected static function hydrate_nested(self $object, array $data): void {
        $object->groups = array_map(fn($d) => GROUP::hydrate($d), $data['groups']);
    }

    function get_pxp(): int {
        $pxp = 0;
        foreach (array_keys($this->groups) as $group)
            $pxp += $this->groups[$group]->get_pxp();
        return $pxp;
    }

    function is_dead(): bool {
        foreach ($this->groups as $index => $group) {
            if (!$group->is_dead()) {
                return false;
            }
        }
        return true;
    }

    //This function rebuilds the list of allies and enemies for this party.
    function find_party_alignment(ActionScope $fight): void {
        //Reset the ally and enemy lists.
        $this->allies = array();
        $this->enemies = array();

        //This is the simple cut-down version.
        //If you are not this party, then you are an enemy. (Each for self)
        foreach (array_keys($fight->parites) as $index)
            if ($fight->parites[$index] === $this)
                $this->allies[] = $index;
            else
                $this->enemies[] = $index;
    }

    function get_ally_parties(ActionScope $fight): array {
        return $this->allies;
    }

    function get_enemy_parties(ActionScope $fight): array {
        return $this->enemies;
    }

    function get_non_ally_parties(ActionScope $fight): array {
        return array_diff(array_keys($fight->parties), $this->allies);
    }

    function get_non_enemy_parties(ActionScope $fight): array {
        return array_diff(array_keys($fight->parties), $this->enemies);
    }

    function get_character_list(?bool $living): array {
        $retval = array();
        foreach (array_keys($this->groups) as $index) {
            $result = $this->groups[$index]->get_character_list($living);
            foreach ($result as $char)
                $retval[] = "$index;$char";
        }
        return $retval;
    }

    function get_character(string $key): CHARACTER {
        $parts = split_index_key($key);
        if (count($parts) != 2) {
            log_error("Unexpected number of parts in key '$key'");
            throw new \Exception("Unexpected number of parts in key '$key'");
        }
        list($group_index, $character_index) = $parts;
        return $this->groups[$group_index]->get_character($character_index);
    }

    function add_team(TEAM $team): bool {
        // Get the party size.
        $count = array_sum(array_map(fn($group) => $group->count(), $this->groups));
        //Verify that there are not too many total people
        if ($count + $team->count() >= GROUP::MAX_COUNT * PARTY_MAX_COUNT)
            return true;
        // If the party is empty, then name the party after the team.
        if ($count == 0) {
            $this->name = $team->name;
        }
        // If the party has exactly one team at this point, 
        // rename the party after the team.
        if (count($this->teams) == 1) {
            $first_teamid = array_key_first($this->teams);
            $first_team_name = $this->team_names[$first_teamid];
            $this->name = "The {$first_team_name} alliance";
        }
        //Add teamid to teams array
        $this->teams[$team->teamid] = array();
        // Add the team name.
        $this->team_names[$team->teamid] = $team->name;
        //Add characters to party
        #Find a group to add whole team to.
        if ($this->count() < PARTY_MAX_COUNT)
            $group = $this->count();
        else
            $group = 0;
        while (
            $group < PARTY_MAX_COUNT
            && isset($this->groups[$group])
            && $this->groups[$group]->count() + $team->count() > GROUP::MAX_COUNT
        )
            $group++;
        #If no available group exists, then start from the beginning.
        if ($group == PARTY_MAX_COUNT)
            $group = 0;
        foreach (array_keys($team->characters) as $index) {
            $this->teams[$team->teamid][] = $team->characters[$index]->charid;
            while (
                isset($this->groups[$group])
                && $this->groups[$group]->count() == GROUP::MAX_COUNT
            )
                $group++;
            $character = $team->characters[$index];
            $this->insert_character($group, null, $character);
        }
        return false;
    }

    function remove_team(int $teamid): bool {
        if (!array_key_exists($teamid, $this->teams)) {
            return true;
        }
        //Remove all characters in this team from the party
        foreach ($this->teams[$teamid] as $charid) {
            $position = $this->get_character_position_by_id($charid);
            if ($position !== null) {
                list($group_index, $char_index) = $position;
                $this->remove_character($group_index, $char_index);
            }
        }
        //Remove the teamid from the teams array
        unset($this->teams[$teamid]);
        unset($this->team_names[$teamid]);
        return false;
    }

    function get_character_key(CHARACTER $character): ?string {
        $result = $this->get_character_position($character);
        if (!is_null($result)) {
            return make_index_key(...$result);
        }
        return null;
    }

    function get_character_position(CHARACTER $character): ?array {
        foreach (array_keys($this->groups) as $index) {
            $result = $this->groups[$index]->get_character_position($character);
            if (!is_null($result)) {
                return [$index, ...$result];
            }
        }
        return null;
    }

    function get_character_position_by_id(int $charid): ?array {
        foreach (array_keys($this->groups) as $index) {
            $result = $this->groups[$index]->get_character_position_by_id($charid);
            if (!is_null($result)) {
                return [$index, ...$result];
            }
        }
        return null;
    }

    function find_character(int $charid): string|bool {
        foreach (array_keys($this->groups) as $index) {
            $result = $this->groups[$index]->find_character($charid);
            if ($result !== true) {
                return "$index;$result";
            }
        }
        return true;
    }

    function insert_character(int $group, ?int $position, CHARACTER &$character): bool {
        //Check group index.
        if (!isset($this->groups[$group]) && $group > 1 && !isset($this->groups[$group - 1]))
            return true;
        //Make the group if needed.
        if (!isset($this->groups[$group]))
            $this->add(new GROUP());
        //Insert character.
        return $this->groups[$group]->add_hero($character, $position);
    }

    function remove_character(int $group, int $position): bool {
        //Check group index.
        if (!isset($this->groups[$group]))
            return true;
        //Remove character.
        $result = $this->groups[$group]->remove_fighter($position);
        //If that was the last person in that group, then remove the group.
        if ($this->groups[$group]->count() == 0)
            $this->remove($group);
        return $result;
    }

    function move_character(int $old_group, int $old_position, int $new_group, int $new_position): bool {
        //Check old_group index.
        if (!isset($this->groups[$old_group]))
            return true;
        //Check new_group size.
        if (
            isset($this->groups[$new_group])
            && $old_group != $new_group
            && $this->groups[$new_group]->count() >= GROUP::MAX_COUNT
        )
            return true;
        //Save the character.
        $character = $this->get_character("$old_group;$old_position");
        if ($character === true)
            return true;
        //Check if this is the last person in the group
        $group_count = $this->groups[$old_group]->count();
        //Remove character from old position
        #var_dump($this);
        $result = $this->remove_character($old_group, $old_position);
        if ($result === true)
            return true;
        //Fix group number if needed.
        if ($group_count == 1 && $new_group > $old_group)
            $new_group--;
        //Insert character into new location
        //return
        #var_dump($this);
        return $this->insert_character($new_group, $new_position, $character);
        #var_dump($this);
    }

    function get_teams_in_party(Database $db): array {
        $teams = array();
        foreach (array_keys($this->teams) as $teamid) {
            $teams[$teamid] = TEAM::load($db, $teamid);
        }
        return $teams;
    }

    function store_party(Database $db): void {
        foreach (array_keys($this->groups) as $group_index)
            foreach (array_keys($this->groups[$group_index]->characters) as $char_index)
                HERO::save_character($db, $this->groups[$group_index]->characters[$char_index]);
    }

    function refresh_party(Database $db): void {
        $char_list = array();
        foreach (array_keys($this->groups) as $group_index) {
            foreach (array_keys($this->groups[$group_index]->characters) as $char_index) {
                $charid = $this->groups[$group_index]->characters[$char_index]->charid;
                $this->groups[$group_index]->characters[$char_index] = HERO::load_character($db, $charid);
            }
        }
    }

    function distribute_gold(Database $db, int $gold): float {
        $this->store_party($db);
        $take = floor($gold / count($this->teams));
        foreach (array_keys($this->teams) as $teamid) {
            $team = TEAM::load($db, $teamid);
            $team->gold += $take;
            $team->save($db);
        }
        return $take;
    }

    // Client-sanitized view of this party, scoped to what the
    // requesting player actually has control over or can rightfully
    // see -- extracted from FIGHT::to_client()'s own inline party
    // projection (identical behavior, not a rewrite), so a NewParty
    // action message's party payload can be sanitized the exact same
    // way before it ever reaches a client, rather than duplicating
    // this logic a second time.
    // $own_teamid is the viewer's team: a positive team ID for a hero
    // player, CHARACTER::MONSTER_TEAMID for a monster controller, or
    // null for a spectator, who owns nothing.
    // $show_classes: this is the party the viewer sits in or is
    // watching, so its heroes' classes are visible even when the viewer
    // owns no team in it (a replay viewer). Class only -- the private
    // per-team fields still need an exact team match.
    // $own_teamid: the viewer's team, or all of their teams (an alliance).
    function to_client(int|array|null $own_teamid, bool $show_classes = false): array {
        $own_teamids = $own_teamid === null ? [] : array_map('intval', (array) $own_teamid);
        $owns_this_party = array_intersect($own_teamids, array_map('intval', array_keys($this->teams))) !== [];
        $groups = [];
        // Whether the viewer's own teamid belongs to this party AT
        // ALL, not to this specific character's team -- a multi-team
        // party shares this across every character in it, regardless
        // of which of the party's own teams they're actually on.
        $is_own_party = $show_classes || $owns_this_party;
        foreach ($this->groups as $group_index => $group) {
            $characters = [];
            foreach ($group->characters as $char_index => $character) {
                // The null check matters: fights recorded before
                // monsters carried MONSTER_TEAMID still hold monsters
                // with teamid null, and null === null would hand a
                // spectator every one of those monsters' private data.
                $is_own_team = in_array($character->teamid, $own_teamids, true);
                $characters[$char_index] = $character->to_client($is_own_team, $is_own_party);
            }
            // name: used to identify the target of a group-targeted
            // ability -- confirmed missing entirely from an earlier
            // version of this projection. Not identity/tactical
            // information the way a character's own charid/target
            // are (see CHARACTER::to_client()'s own reasoning for
            // personalityid, the same logic applies here): a group is
            // already visible on screen, so its name doesn't reveal
            // anything worth withholding.
            $groups[$group_index] = ['name' => $group->name, 'characters' => $characters];
        }
        return [
            'name' => $this->name,
            'groups' => $groups,
            // The client's own object_create_stats() checks
            // party.teams.length to distinguish a real
            // player-controlled hero party from a player-controlled
            // monster party (the eparty special case in
            // init_fight.php) -- confirmed by a real client crash
            // otherwise, reading .length on a property this
            // projection didn't include at all. Real content
            // (teamid => [charid, ...]) only for the requesting
            // player's own party, matching CHARACTER::to_client()'s
            // own never-leak-identity-for-non-own rule; any other
            // party gets an empty array -- which is also simply the
            // correct, natural value for a monster-only party
            // regardless (monsters were never added via
            // PARTY::add_team(), so teams is never populated for them
            // at all).
            'teams' => $owns_this_party ? $this->teams : [],
            // A monster party: the fight screen lays out a player's own
            // monster party differently (small stat boxes in the group
            // rows, an action box per monster) -- fight_common.js,
            // hero_slide.js and stat_display.js read party.monster.
            // Without it, a monster player's group slid their boxes off
            // to the heroes' place and never showed the action boxes.
            // Not private: monsters are on screen for everyone.
            'monster' => $this->monster,
        ];
    }

    #[Override]
    public function get_parties(): array {
        return [$this];
    }

    #[Override]
    public function get_scope_type(): ScopeType {
        return ScopeType::FIELD;
    }
}