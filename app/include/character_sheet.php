<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'hero.php';
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'functions.php';

/**
 * What the status and equipment-benefits dialogs show, worked out on the
 * server so the stat formulas live in one place (CHARACTER).
 *
 *   sheet()   a hero's status: name, job, level, experience, every stat
 *             with equipment counted, and what's worn where.
 *   preview() the same stats before and after an equipment change -- a
 *             copy of the hero is changed, never the hero itself -- with
 *             the slots it would fill and the ones it would empty.
 *
 * for_player() loads a hero only if it's on one of the player's own
 * teams; the calls in php_js/ go through it.
 */
final class CharacterSheet {
    const STATS = ['HP', 'MP', 'Speed', 'Accuracy', 'Strength', 'Dodge', 'Block', 'Power', 'Resistance', 'Focus'];

    // The hero, if it belongs to one of $playerid's teams; else null.
    public static function for_player(Database $db, int $playerid, int $charid): ?CHARACTER {
        try {
            $character = HERO::load_character($db, $charid);
        } catch (\Exception $e) {
            return null;
        }
        if ($character->teamid === null || $character->teamid <= 0) {
            return null;
        }
        try {
            $team = TEAM::load($db, $character->teamid);
        } catch (\Exception $e) {
            return null;
        }
        return $team->playerid === $playerid ? $character : null;
    }

    public static function sheet(CHARACTER $character): array {
        $equipment = [];
        foreach ($character->equipment as $slot => $index) {
            $item = $index === null ? null : $character->get_item($index);
            $equipment[$slot] = ($item === null || $item === true) ? null : $item->name;
        }
        // The equip dialog's model takes the same shape combat's heroes
        // have: worn = slot => inventory index, inventory = [{item, qty}].
        $inventory = [];
        foreach ($character->inventory as $index => $entry) {
            $inventory[$index] = ['item' => (int) $entry['item'], 'qty' => (int) $entry['qty']];
        }
        return [
            'charid' => $character->charid,
            'name' => $character->name,
            'job' => self::job_name($character->jobid),
            'level' => $character->level,
            'exp' => $character->exp,
            'need' => $character->need,
            'stats' => self::stats($character),
            'equipment' => $equipment,
            'worn' => $character->equipment,
            'inventory' => $inventory,
        ];
    }

    private static function job_name(?int $jobid): string {
        try {
            return JOB::get((int) $jobid)->name;
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    // Every stat with all equipment counted: HP and MP as [current, most],
    // the rest as a number.
    public static function stats(CHARACTER $character): array {
        $stats = [];
        foreach (self::STATS as $stat) {
            $stats[$stat] = in_array($stat, ['HP', 'MP'], true)
                ? [$character->current[$stat], $character->get_base($stat, true)]
                : $character->get_current($stat, true);
        }
        return $stats;
    }

    // What equipping inventory item $index to $slot (or, with $index -1,
    // taking off whatever is in $slot) would do. Null if it can't be done
    // there. ['before' => stats, 'after' => stats, 'fills' => [slots],
    // 'empties' => [slots]]
    public static function preview(CHARACTER $character, int $index, string $slot): ?array {
        if (!array_key_exists($slot, $character->equipment)) {
            return null;
        }
        $copy = clone $character;
        $before = self::stats($character);
        $empties = [];
        if ($index === -1) {
            $worn = $copy->equipment[$slot];
            if ($worn === null) {
                return null;
            }
            $result = $copy->unequip_item($worn);
            if ($result->outcome !== EquipOutcome::SUCCESS) {
                return null;
            }
            return ['before' => $before, 'after' => self::stats($copy), 'fills' => [], 'empties' => $result->equipped_slots];
        }
        // The item has to be one that goes there (equip_item() only
        // takes a side, and would put a sword in a hand when asked for
        // the head). Same test as the equip dialog's EquipModel.
        $item = $character->get_item($index);
        $base = preg_replace('/^[lr](hand|arm|ammo)$/', '$1', $slot);
        if ($item === true || $item === null || !is_array($item->equip_type)
            || !array_filter($item->equip_type, fn($type) => $type === $slot || $type === $base)) {
            return null;
        }
        $side = self::side_of($slot);
        for ($tries = 0; $tries < 10; $tries++) {
            $result = $copy->equip_item($index, $side);
            if ($result->outcome === EquipOutcome::SUCCESS) {
                $fills = $result->equipped_slots;
                // empties: every slot something comes off of, even when the
                // new item goes back into it, so the dialog can say what
                // would be taken off.
                return ['before' => $before, 'after' => self::stats($copy), 'fills' => $fills,
                    'empties' => array_values(array_unique($empties))];
            }
            if ($result->outcome !== EquipOutcome::NEEDS_UNEQUIPPING) {
                return null;
            }
            $removed = $copy->unequip_item($result->occupying_index);
            if ($removed->outcome !== EquipOutcome::SUCCESS) {
                return null;
            }
            array_push($empties, ...$removed->equipped_slots);
        }
        return null;
    }

    // The side find_slot() means by a slot's name: 0 for the left ("l..."),
    // 1 for the right; 0 for slots that have no side.
    public static function side_of(string $slot): int {
        return in_array($slot, ['rhand', 'rarm', 'rammo'], true) ? 1 : 0;
    }
}
