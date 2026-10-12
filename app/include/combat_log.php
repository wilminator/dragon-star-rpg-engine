<?php
require_once INCLUDE_GAME . 'functions.php';

/**
 * The combat log: a round's actions as plain sentences, for the text
 * panel beside the fight (settings: text_log) and for screen readers.
 * Built from the same action list the page plays back, with the text of
 * hero_does_thing's field actions (convert_action_list_to_text()).
 *
 * One fighter's action that reaches CONSOLIDATE_AT or more fighters is
 * summed up, per side and per kind of result, instead of a line per
 * fighter: "6 enemies are damaged for 45 HP on average." Sides are
 * named from the viewer's party: theirs are allies, anyone else's
 * enemies.
 */
final class CombatLog {
    const CONSOLIDATE_AT = 3;

    const EFFECT_TYPES = ['Miss', 'Damage', 'Restore', 'Died', 'Revived', 'NoEffect'];

    // $action_list: the round's actions as sent to the page. $scope: the
    // fight as it stood when the round began (names and places).
    public static function lines(array $action_list, ActionScope $scope, int $viewer_party): array {
        return array_merge([], ...array_map(fn($turn) => array_merge($turn['act'], $turn['results']),
            self::turns($action_list, $scope, $viewer_party)));
    }

    // The same, per fighter's turn, in the order the page plays them:
    // ['act' => what they do, 'results' => what came of it]. The page
    // adds a turn's act lines as its animation starts (combat_log_turn())
    // and its results once the hit lands (combat_log_landed()), so the
    // log doesn't give away the outcome early. The list is cut exactly
    // as the page's preprocess_action_list() cuts it, so turn N here is
    // turn N there, empty ones included.
    public static function turns(array $action_list, ActionScope $scope, int $viewer_party): array {
        $turns = [];
        foreach (self::by_fighter($action_list) as $chunk) {
            $effects = array_values(array_filter($chunk, fn($a) => in_array($a[0], self::EFFECT_TYPES, true)));
            $rest = array_values(array_filter($chunk, fn($a) => !in_array($a[0], self::EFFECT_TYPES, true)));
            $act = self::text($rest, $scope);
            $reached = [];
            foreach ($effects as $effect) {
                $reached[implode(';', array_slice($effect[1], 0, 3))] = true;
            }
            $results = count($reached) < self::CONSOLIDATE_AT
                ? self::without(self::text($chunk, $scope), $act)
                : self::summary($effects, $viewer_party);
            $turns[] = ['act' => $act, 'results' => $results];
        }
        return $turns;
    }

    // $lines less $taken (each taken line once), in order.
    private static function without(array $lines, array $taken): array {
        $left = [];
        foreach ($lines as $line) {
            $at = array_search($line, $taken, true);
            if ($at !== false) {
                unset($taken[$at]);
                continue;
            }
            $left[] = $line;
        }
        return $left;
    }

    // The list cut into each fighter's turn.
    private static function by_fighter(array $action_list): array {
        $chunks = [];
        foreach ($action_list as $action) {
            if ($action[0] === 'Turn' || $chunks === []) {
                $chunks[] = [];
            }
            $chunks[count($chunks) - 1][] = $action;
        }
        return $chunks;
    }

    private static function text(array $chunk, ActionScope $scope): array {
        try {
            $playlist = convert_action_list_to_text($chunk, $scope);
        } catch (\Throwable $e) {
            log_error($e, null, 'server_error');
            return [];
        }
        return array_values(array_map(fn($entry) => preg_replace('/\s+/', ' ', trim($entry[1])),
            array_filter($playlist, fn($entry) => $entry[0] === 'TextOutput')));
    }

    // One line per side and kind of result, in the order they first
    // happened.
    private static function summary(array $effects, int $viewer_party): array {
        $groups = [];
        foreach ($effects as [$type, $data]) {
            $side = (int) $data[0] === $viewer_party ? 'ally' : 'enemy';
            $key = $side . ':' . $type . ($type === 'Restore' ? ':' . $data[3] : '');
            $groups[$key] ??= ['side' => $side, 'type' => $type, 'stat' => $type === 'Restore' ? $data[3] : null,
                'count' => 0, 'total' => 0, 'critical' => 0];
            $groups[$key]['count']++;
            if ($type === 'Damage') {
                $groups[$key]['total'] += (int) $data[3];
                $groups[$key]['critical'] += !empty($data[4]) ? 1 : 0;
            } elseif ($type === 'Restore') {
                $groups[$key]['total'] += (int) $data[4];
            }
        }
        $lines = [];
        foreach ($groups as $g) {
            $n = $g['count'];
            $who = $n . ' ' . ($g['side'] === 'ally' ? ($n === 1 ? 'ally' : 'allies') : ($n === 1 ? 'enemy' : 'enemies'));
            $is = $n === 1 ? 'is' : 'are';
            $average = (int) round($g['total'] / $n);
            $on_average = $n === 1 ? '' : ' on average';
            $line = match ($g['type']) {
                'Damage' => "$who $is damaged for $average HP$on_average."
                    . ($g['critical'] > 0 ? ' ' . ($g['critical'] === 1 ? 'One critical hit!' : "{$g['critical']} critical hits!") : ''),
                'Restore' => $average >= 0
                    ? "$who " . ($n === 1 ? 'recovers' : 'recover') . " $average {$g['stat']}$on_average."
                    : "$who " . ($n === 1 ? 'loses' : 'lose') . ' ' . (-$average) . " {$g['stat']}$on_average.",
                'Miss' => "$who " . ($n === 1 ? 'evades' : 'evade') . '.',
                'Died' => "$who " . ($n === 1 ? 'has' : 'have') . ' fallen!',
                'Revived' => "$who " . ($n === 1 ? 'has' : 'have') . ' revived!',
                'NoEffect' => "$who $is unaffected.",
                default => null,
            };
            if ($line !== null) {
                $lines[] = ucfirst($line);
            }
        }
        return $lines;
    }
}
