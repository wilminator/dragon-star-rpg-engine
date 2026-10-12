<?php

require_once INCLUDE_GAME . 'party.php';

use PHPUnit\Framework\TestCase;

/**
 * General-purpose PARTY tests not specific to add_team()/remove_team()/
 * teams-and-gold (each of which already has its own dedicated test
 * file). dehydrate()/hydrate() never touch the database -- pure unit
 * tests throughout.
 */
class PartyTest extends TestCase {

    private function party_with_one_character(string $name, int $charid): PARTY {
        $party = new PARTY();
        $group = new GROUP();
        $group->add_hero(CHARACTER::create_hero($name, 1, 1, null, $charid));
        $party->add($group);
        return $party;
    }

    // --- dehydrate() / hydrate() ---

    public function test_dehydrate_projects_direct_properties_and_embeds_each_groups_own_dehydrate() {
        $party = $this->party_with_one_character('Alice', 1);
        $party->teams = [10 => [1]];
        $party->team_names = [10 => 'The Heroes'];
        $party->allies = [0];
        $party->enemies = [1, 2];
        $party->monster = true;

        $data = $party->dehydrate();

        $this->assertSame([10 => [1]], $data['teams']);
        $this->assertSame([10 => 'The Heroes'], $data['team_names']);
        $this->assertSame([0], $data['allies']);
        $this->assertSame([1, 2], $data['enemies']);
        $this->assertTrue($data['monster']);
        $this->assertSame(PARTY::DEHYDRATE_VERSION, $data['version']);
        $this->assertSame('Alice', $data['groups'][0]['characters'][0]['name']);
    }

    public function test_dehydrate_works_for_an_empty_party_with_no_team_ever_added() {
        // Regression guard: team_names was previously never initialized
        // in the constructor (unlike every other array property here),
        // which broke dehydrate() for any party that never called
        // add_team() -- e.g. every monster party.
        $party = new PARTY();

        $data = $party->dehydrate();

        $this->assertSame([], $data['team_names']);
    }

    public function test_hydrate_reconstructs_real_nested_group_and_character_instances() {
        $party = $this->party_with_one_character('Alice', 1);
        $party->teams = [10 => [1]];

        $rehydrated = PARTY::hydrate($party->dehydrate());

        $this->assertSame($party->teams, $rehydrated->teams);
        $this->assertInstanceOf(GROUP::class, $rehydrated->groups[0]);
        $this->assertInstanceOf(CHARACTER::class, $rehydrated->groups[0]->characters[0]);
        $this->assertSame('Alice', $rehydrated->groups[0]->characters[0]->name);
    }

    public function test_dehydrate_survives_a_json_round_trip_through_two_levels_of_nesting() {
        $party = $this->party_with_one_character('Alice', 1);

        $json = json_encode($party->dehydrate());
        $rehydrated = PARTY::hydrate(json_decode($json, true));

        $this->assertInstanceOf(GROUP::class, $rehydrated->groups[0]);
        $this->assertInstanceOf(CHARACTER::class, $rehydrated->groups[0]->characters[0]);
        $this->assertSame(1, $rehydrated->groups[0]->characters[0]->charid);
    }

    // --- to_client() and is_own_party ---
    // Regression: a party-mate on a different team within the same
    // multi-team party was displaying as class "Monster" during
    // playback -- CHARACTER::to_client() only included jobid for an
    // exact team match, not for anyone else sharing the viewer's party.

    public function test_to_client_shows_jobid_for_a_party_mate_on_a_different_team() {
        $alice = CHARACTER::create_hero('Alice', 1, 1, null, 1);
        $alice->teamid = 5;
        $bob = CHARACTER::create_hero('Bob', 1, 1, null, 2);
        $bob->teamid = 6;
        $group = new GROUP();
        $group->add_hero($alice);
        $group->add_hero($bob);
        $party = new PARTY();
        $party->add($group);
        $party->teams[5] = [1];
        $party->teams[6] = [2];

        // Viewer is on team 5 (Alice's own team); Bob is a party-mate
        // on team 6, within the same party.
        $data = $party->to_client(5);

        $this->assertSame(1, $data['groups'][0]['characters'][0]['jobid']); // Alice, own team
        $this->assertSame(1, $data['groups'][0]['characters'][1]['jobid']); // Bob, party-mate
        // Bob's genuinely private data still stays hidden.
        $this->assertArrayNotHasKey('command', $data['groups'][0]['characters'][1]);
    }

    public function test_to_client_omits_jobid_for_a_true_outsider() {
        $alice = CHARACTER::create_hero('Alice', 1, 1, null, 1);
        $alice->teamid = 5;
        $group = new GROUP();
        $group->add_hero($alice);
        $party = new PARTY();
        $party->add($group);
        $party->teams[5] = [1];

        // Viewer's team isn't part of this party at all.
        $data = $party->to_client(99);

        $this->assertArrayNotHasKey('jobid', $data['groups'][0]['characters'][0]);
    }

    // --- viewer ownership: hero player, monster controller, spectator ---

    public function test_monsters_are_created_with_the_monster_team_id() {
        $party = MONSTER::create_monster_party(200, 1, 2);

        foreach ($party->groups as $group) {
            foreach ($group->characters as $monster) {
                $this->assertSame(CHARACTER::MONSTER_TEAMID, $monster->teamid);
            }
        }
    }

    public function test_to_client_gives_a_monster_controller_full_data_for_their_monsters() {
        // Regression: monsters used to carry teamid null, so a player
        // controlling them (team -1) matched none of them and was sent
        // no command/target/abilities -- nothing to act with.
        $party = MONSTER::create_monster_party(200, 1, 2);

        $data = $party->to_client(CHARACTER::MONSTER_TEAMID);

        $monster = $data['groups'][0]['characters'][0];
        $this->assertArrayHasKey('abilities', $monster);
        $this->assertArrayHasKey('command', $monster);
    }

    public function test_to_client_says_which_parties_are_monsters() {
        // Regression: the flag was left out, so the fight screen laid a
        // monster player's party out as heroes -- their stat boxes slid
        // out of sight and no action boxes appeared.
        $monsters = MONSTER::create_monster_party(200, 1, 2);
        $this->assertTrue($monsters->to_client(CHARACTER::MONSTER_TEAMID)['monster']);
        $this->assertTrue($monsters->to_client(null)['monster'], 'spectators see it too: monsters are on screen');
        $this->assertFalse((new PARTY())->to_client(5)['monster']);
    }

    public function test_to_client_gives_a_spectator_no_private_data_for_monsters() {
        $party = MONSTER::create_monster_party(200, 1, 2);

        $data = $party->to_client(null);

        $this->assertArrayNotHasKey('abilities', $data['groups'][0]['characters'][0]);
    }

    public function test_to_client_gives_a_spectator_no_private_data_for_legacy_null_team_monsters() {
        // Fights recorded before monsters carried MONSTER_TEAMID still
        // hold monsters with teamid null. A spectator is also null, and
        // null === null must not count as ownership.
        $monster = CHARACTER::create_hero('OldMonster', 1, 1, null, 1);
        $monster->teamid = null;
        $group = new GROUP();
        $group->add_hero($monster);
        $party = new PARTY();
        $party->add($group);

        $data = $party->to_client(null);

        $this->assertArrayNotHasKey('abilities', $data['groups'][0]['characters'][0]);
        $this->assertArrayNotHasKey('command', $data['groups'][0]['characters'][0]);
    }

    public function test_to_client_gives_a_hero_player_no_private_data_for_monsters() {
        $party = MONSTER::create_monster_party(200, 1, 2);

        $data = $party->to_client(5);

        $this->assertArrayNotHasKey('abilities', $data['groups'][0]['characters'][0]);
    }

    public function test_to_client_show_classes_reveals_jobid_but_nothing_private() {
        // The party a replay viewer is watching: class visible, private
        // per-team fields still withheld.
        $hero = CHARACTER::create_hero('Alice', 1, 1, null, 1);
        $hero->teamid = 5;
        $group = new GROUP();
        $group->add_hero($hero);
        $party = new PARTY();
        $party->add($group);
        $party->teams[5] = [1];

        $data = $party->to_client(null, show_classes: true);

        $character = $data['groups'][0]['characters'][0];
        $this->assertSame(1, $character['jobid']);
        $this->assertArrayNotHasKey('command', $character);
        $this->assertArrayNotHasKey('abilities', $character);
    }
}
