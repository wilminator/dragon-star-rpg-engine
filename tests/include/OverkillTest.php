<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'fight_communicator.php';

/**
 * Overkill: tallied per party from a fight's Died messages, and the XP
 * bonus heroes get for beating monsters a player controlled.
 */
class OverkillTest extends TestCase {

    public function test_overkill_is_tallied_per_party_from_died_messages() {
        $messages = [
            ['Damage', [1, 0, 0, 50, false]],
            ['Died', [1, 0, 0, 50, 40]],
            ['Died', [1, 0, 1, 12, 2]],
            ['Died', [0, 0, 0, 30, 5]],
            ['Died', [1, 1, 0, 9]],          // recorded before overkill was
        ];
        $this->assertSame([1 => 42, 0 => 5], FightAction::overkill_taken($messages));
        $this->assertSame([], FightAction::overkill_taken([]));
    }

    private function fight_with_monsters(int $monster_hp): FIGHT {
        $hero = CHARACTER::create_hero('Hero', 999, 1, null);
        $hero->teamid = 5;
        $heroes = new PARTY();
        $group = new GROUP();
        $group->add_hero($hero);
        $heroes->add($group);
        $heroes->teams[5] = [$hero->charid];
        $monster = CHARACTER::create_hero('Imp', 999, 1, null);
        $monster->teamid = CHARACTER::MONSTER_TEAMID;
        $monster->base['HP'] = $monster_hp;
        $monsters = new PARTY();
        $mgroup = new GROUP();
        $mgroup->add_hero($monster);
        $monsters->add($mgroup);
        $fight = new FIGHT('bg', 'music');
        $fight->add($heroes);
        $fight->add($monsters);
        FightSetup::link_as_enemies($fight);
        return $fight;
    }

    public function test_the_bonus_is_overkill_over_the_monsters_hp_capped_at_15_percent() {
        $fight = $this->fight_with_monsters(200);
        $alive = [0 => true, 1 => false];
        $this->assertEqualsWithDelta([0 => 0.1], FightCommunicator::xp_bonus_shares($fight, $alive, [1 => 20], [1]), 1e-9);
        $this->assertEqualsWithDelta([0 => 0.15], FightCommunicator::xp_bonus_shares($fight, $alive, [1 => 500], [1]), 1e-9, 'capped');
    }

    public function test_no_bonus_without_a_player_on_the_monsters_a_win_or_any_overkill() {
        $fight = $this->fight_with_monsters(200);
        $this->assertSame([], FightCommunicator::xp_bonus_shares($fight, [0 => true, 1 => false], [1 => 20], []), 'the AI played them');
        $this->assertSame([], FightCommunicator::xp_bonus_shares($fight, [0 => false, 1 => true], [1 => 20], [1]), 'the heroes lost');
        $this->assertSame([], FightCommunicator::xp_bonus_shares($fight, [0 => true, 1 => false], [], [1]), 'no overkill');
    }
}
