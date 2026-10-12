<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'combat_log.php';
require_once INCLUDE_GAME . 'fight.php';

/**
 * The combat log (CombatLog): a round in words, with a many-target
 * action summed up per side, named from the viewer's party.
 */
class CombatLogTest extends TestCase {

    // Party 0: Jordie and Mike. Party 1: six woodmen in one group.
    private function fight(): FIGHT {
        $fight = new FIGHT('bg', 'music');
        foreach ([['Jordie', 'Mike'], ['Woodman-A', 'Woodman-B', 'Woodman-C', 'Woodman-D', 'Woodman-E', 'Woodman-F']] as $names) {
            $party = new PARTY();
            $group = new GROUP();
            foreach ($names as $name) {
                $fighter = CHARACTER::create_hero($name, 1, 1, null);
                $fighter->base['HP'] = $fighter->current['HP'] = 100;
                $group->add_hero($fighter);
            }
            $party->add($group);
            $fight->add($party);
        }
        return $fight;
    }

    private function hits(array $targets, array $amounts, bool $critical = false): array {
        $list = [];
        foreach ($targets as $i => $c) {
            $list[] = ['Damage', [1, 0, $c, $amounts[$i], $critical && $i === 0]];
        }
        return $list;
    }

    public function test_one_or_two_targets_get_a_line_each() {
        $list = [['Turn', [0, 0, 0]], ['Attack', [0, 'left', 0]], ['Target', [1, 0, 1, 0]], ...$this->hits([1], [12])];
        $this->assertSame(['Jordie attacks Woodman-B.', 'Woodman-B takes 12 damage.'],
            CombatLog::lines($list, $this->fight(), 0));
    }

    public function test_many_targets_are_summed_up_per_side() {
        $list = [['Turn', [0, 0, 1]], ['Attack', [0, 'left', 0]], ['Target', [1, 0, 0, -1]],
            ...$this->hits([0, 1, 2, 3, 4, 5], [40, 50, 45, 45, 44, 46], true),
            ['Died', [1, 0, 1, 50, 0]], ['Died', [1, 0, 2, 45, 0]]];
        $lines = CombatLog::lines($list, $this->fight(), 0);
        $this->assertSame('6 enemies are damaged for 45 HP on average. One critical hit!', $lines[1]);
        $this->assertSame('2 enemies have fallen!', $lines[2]);
        $this->assertCount(3, $lines, 'no line per woodman');
    }

    public function test_the_same_round_seen_from_the_other_side() {
        $list = [['Turn', [0, 0, 1]], ['Attack', [0, 'left', 0]], ['Target', [1, 0, 0, -1]],
            ...$this->hits([0, 1, 2], [10, 20, 30])];
        $this->assertSame('3 allies are damaged for 20 HP on average.', CombatLog::lines($list, $this->fight(), 1)[1]);
    }

    public function test_healing_misses_and_several_fighters_in_one_round() {
        $list = [
            ['Turn', [1, 0, 0]], ['Attack', [0, 'left', 0]], ['Target', [0, 0, 0, 0]], ['Miss', [0, 0, 0]],
            ['Turn', [1, 0, 1]], ['Attack', [0, 'left', 0]], ['Target', [1, 0, 0, -1]],
            ['Restore', [1, 0, 0, 'HP', 10]], ['Restore', [1, 0, 1, 'HP', 20]], ['Restore', [1, 0, 2, 'HP', 30]], ['Miss', [0, 0, 1]],
        ];
        $lines = CombatLog::lines($list, $this->fight(), 0);
        $this->assertSame('Jordie evades!', $lines[1]);
        $this->assertContains('3 enemies recover 20 HP on average.', $lines);
        $this->assertContains('1 ally evades.', $lines, 'a side of one still reads right');
    }

    public function test_an_empty_round_says_nothing() {
        $this->assertSame([], CombatLog::lines([], $this->fight(), 0));
    }

    public function test_turns_line_up_with_the_pages_turns_empty_ones_included() {
        $list = [
            ['Turn', [0, 0, 0]], ['Attack', [0, 'left', 0]], ['Target', [1, 0, 1, 0]], ...$this->hits([1], [12]),
            ['Turn', [0, 0, 1]],
            ['Turn', [1, 0, 0]], ['Attack', [0, 'left', 0]], ['Target', [0, 0, 0, 0]], ['Miss', [0, 0, 0]],
        ];
        $turns = CombatLog::turns($list, $this->fight(), 0);
        $this->assertCount(3, $turns);
        $this->assertSame(['act' => ['Jordie attacks Woodman-B.'], 'results' => ['Woodman-B takes 12 damage.']], $turns[0]);
        $this->assertSame(['act' => [], 'results' => []], $turns[1], 'a turn that did nothing still has its place');
        $this->assertSame(['act' => ['Woodman-A attacks Jordie.'], 'results' => ['Jordie evades!']], $turns[2]);
        $this->assertSame(['Jordie attacks Woodman-B.', 'Woodman-B takes 12 damage.', 'Woodman-A attacks Jordie.', 'Jordie evades!'],
            CombatLog::lines($list, $this->fight(), 0));
    }

    public function test_a_summed_up_turn_keeps_its_results_apart_from_the_action() {
        $list = [['Turn', [0, 0, 1]], ['Attack', [0, 'left', 0]], ['Target', [1, 0, 0, -1]],
            ...$this->hits([0, 1, 2], [10, 20, 30])];
        $turns = CombatLog::turns($list, $this->fight(), 0);
        $this->assertSame(['3 enemies are damaged for 20 HP on average.'], $turns[0]['results']);
        $this->assertNotContains('3 enemies are damaged for 20 HP on average.', $turns[0]['act'], 'the outcome waits for the hit');
    }
}
