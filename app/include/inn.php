<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'team.php';

/**
 * The inn: every hero back to full HP and MP. Each team pays for its own
 * rooms from its own purse -- the square root of its PXP, rounded -- and
 * nobody rests unless every team in the party can pay. (Once part of
 * setup_fight.php, now retired to legacy/.)
 */
final class Inn {
    public static function price(TEAM $team): int {
        return (int) round(sqrt($team->get_pxp()));
    }

    // The teams that can't pay.
    public static function short(array $teams): array {
        return array_values(array_filter($teams, fn(TEAM $team) => $team->gold < self::price($team)));
    }

    // Rests every team in $teams. Null when they rested; otherwise why
    // not (nothing changes).
    public static function rest(Database $db, array $teams): ?string {
        $short = self::short($teams);
        if ($short !== []) {
            return count($teams) > 1
                ? 'Not every team has enough gold: ' . implode(', ', array_map(fn($t) => $t->name, $short)) . '.'
                : 'You do not have enough gold.';
        }
        foreach ($teams as $team) {
            $team->gold -= self::price($team);
            foreach ($team->characters as $character) {
                $character->current['HP'] = $character->base['HP'];
                $character->current['MP'] = $character->base['MP'];
            }
            $team->store_team($db);
        }
        return null;
    }
}
