<?php
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'field_functions.php';
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'paths.php';

/**
 * Items, spells and abilities between fights, for the hub (what
 * hero_use_thing.php does, less selling, which moves into the store):
 * what a hero has, and doing something with it -- the same field actions
 * the old page performs, on the player's party, saved. An item can be
 * used, transferred to another hero, or discarded.
 *
 *   options()  the hero's items, spells and abilities (skills), with
 *              whether each can be used now and what it's aimed at:
 *                'party'  everyone, no choice to make
 *                'group'  one group
 *                'hero'   one hero
 *   use()      does one of them, checked against options() first
 */
final class FieldUse {
    const ACTIONS = ['item' => 2, 'skill' => 4, 'spell' => 5, 'give' => 9, 'discard' => 0];

    // What a spec's targets mean between fights.
    public static function aim(int $targets): string {
        return match (true) {
            $targets >= EffectSpec::TARGET_INDIVIDUAL => 'hero',
            $targets === EffectSpec::TARGET_GROUP => 'group',
            default => 'party',
        };
    }

    // ['actor' => [...], 'items' => [...], 'spells' => [...],
    //  'abilities' => [...]]
    //   an item:   ['index', 'name', 'qty', 'desc', 'use' => null (it has
    //              no use) or ['usable', 'why', 'aim', 'living']]
    //   a spell or ability (skill): ['kind' => 'spell'|'skill', 'index',
    //              'name', 'mp', 'desc', 'usable', 'why', 'aim', 'living']
    public static function options(CHARACTER $character): array {
        $alive = $character->get_current('HP') > 0;
        $items = [];
        foreach ($character->inventory as $index => $slot) {
            $item = ITEM::get($slot['item']);
            $use = null;
            if ($item->use_effect_spec->effect !== Effect::NONE) {
                $field = $item->usable_in_field();
                $use = [
                    'usable' => $field && $alive,
                    'why' => !$field ? "Can't be used outside a fight." : (!$alive ? "{$character->name} is down." : ''),
                    'aim' => self::aim($item->use_effect_spec->targets),
                    'living' => $item->use_effect_spec->only_living_targets(),
                ];
            }
            $items[] = ['index' => $index, 'name' => $item->name, 'qty' => (int) $slot['qty'], 'desc' => $item->description,
                'pic' => ITEM_IMAGES_DIR . $item->menu_pic, 'use' => $use];
        }
        $spells = [];
        $abilities = [];
        foreach ($character->abilities as $index => $abilityid) {
            $ability = ABILITY::get($abilityid);
            $field = $ability->usable_in_field();
            $mp = $character->get_current('MP') >= $ability->mp_used;
            $skill = $ability->type === ABILITY::TYPE_SKILL;
            $entry = [
                'kind' => $skill ? 'skill' : 'spell',
                'index' => $index, 'name' => $ability->name, 'mp' => $ability->mp_used, 'desc' => $ability->description,
                'pic' => ABILITY_IMAGES_DIR . $ability->menu_pic,
                'usable' => $field && $mp && $alive,
                'why' => !$field ? "Can't be used outside a fight." : (!$alive ? "{$character->name} is down." : (!$mp ? 'Not enough MP.' : '')),
                'aim' => self::aim($ability->effect_spec->targets),
                'living' => $ability->effect_spec->only_living_targets(),
            ];
            if ($skill) {
                $abilities[] = $entry;
            } else {
                $spells[] = $entry;
            }
        }
        // What can be used first, then by name.
        $order = fn($a, $b) => [!$a['usable'], $a['name']] <=> [!$b['usable'], $b['name']];
        usort($spells, $order);
        usort($abilities, $order);
        return [
            'actor' => ['charid' => $character->charid, 'name' => $character->name,
                'hp' => [(int) $character->current['HP'], (int) $character->base['HP']],
                'mp' => [(int) $character->current['MP'], (int) $character->base['MP']]],
            'items' => $items,
            'spells' => $spells,
            'abilities' => $abilities,
        ];
    }

    // What use() would do with $kind/$index, as a choice (the same shape
    // as a spell's), or null if the hero has nothing like it.
    private static function choice(array $options, string $kind, int $index): ?array {
        if ($kind === 'spell' || $kind === 'skill') {
            foreach ([...$options['spells'], ...$options['abilities']] as $entry) {
                if ($entry['kind'] === $kind && $entry['index'] === $index) {
                    return $entry;
                }
            }
            return null;
        }
        foreach ($options['items'] as $item) {
            if ($item['index'] !== $index) {
                continue;
            }
            return match ($kind) {
                'item' => $item['use'] === null ? null : ['kind' => 'item'] + $item['use'] + $item,
                'give' => ['kind' => 'give', 'usable' => true, 'why' => '', 'aim' => 'hero', 'living' => false] + $item,
                'discard' => ['kind' => 'discard', 'usable' => true, 'why' => '', 'aim' => 'party', 'living' => false] + $item,
                default => null,
            };
        }
        return null;
    }

    // The hero in the player's party, if they're in it: [PARTY, position].
    private static function find(Database $db, int $playerid, int $charid): ?array {
        $party = PartyState::party($db, $playerid);
        $position = $party?->get_character_position_by_id($charid);
        return $position === null ? null : [$party, $position];
    }

    public static function options_for(Database $db, int $playerid, int $charid): ?array {
        $found = self::find($db, $playerid, $charid);
        return $found === null ? null : self::options($found[0]->get_character(make_index_key(...$found[1])));
    }

    // Does choice $kind/$index of hero $charid, aimed at $group/$char
    // (as its aim needs): 'item' (use it), 'give' (transfer the stack),
    // 'discard' ($qty of it), 'spell' or 'skill'. Returns [problem or
    // null, message].
    public static function use(Database $db, int $playerid, int $charid, string $kind, int $index, ?int $group, ?int $char, int $qty = 0): array {
        if ((new FightCommunicator($db, $playerid))->is_in_active_fight()) {
            return ['Finish your fight first.', ''];
        }
        $found = self::find($db, $playerid, $charid);
        if ($found === null || !isset(self::ACTIONS[$kind])) {
            return ["That hero isn't in your party.", ''];
        }
        [$party, $position] = $found;
        $actor_target = new ActionTarget(0, ...$position);
        $actor = $actor_target->get_character($party);
        $choice = self::choice(self::options($actor), $kind, $index);
        if ($choice === null) {
            return ["{$actor->name} can't do that.", ''];
        }
        if (!$choice['usable']) {
            return [$choice['why'], ''];
        }
        if ($kind === 'discard') {
            if ($qty < 1 || $qty > $choice['qty']) {
                return ['How many?', ''];
            }
            $gone = $actor->remove_item($index, $qty);
            $party->store_party($db);
            return [null, "{$actor->name} threw away {$gone} {$choice['name']}."];
        }
        $problem = self::target_problem($party, $actor, $choice, $group, $char);
        if ($problem !== null) {
            return [$problem, ''];
        }
        $recipient = match ($choice['aim']) {
            'hero' => new ActionTarget(0, $group, $char),
            'group' => new ActionTarget(0, $group, null),
            default => new ActionTarget(0, null, null),
        };
        $said = perform_field_action($party, self::ACTIONS[$kind], $index, $actor_target, $recipient);
        $party->store_party($db);
        return [null, trim(html_entity_decode(strip_tags(str_replace('</p>', "\n", $said))))];
    }

    private static function target_problem(PARTY $party, CHARACTER $actor, array $choice, ?int $group, ?int $char): ?string {
        if ($choice['aim'] === 'party') {
            return null;
        }
        if ($group === null || !isset($party->groups[$group])) {
            return 'Pick who to aim at.';
        }
        if ($choice['aim'] === 'group') {
            return $choice['living'] && $party->groups[$group]->is_dead() ? 'Everyone in that group is down.' : null;
        }
        if ($char === null || !isset($party->groups[$group]->characters[$char])) {
            return 'Pick who to aim at.';
        }
        $target = $party->groups[$group]->characters[$char];
        if ($choice['kind'] === 'give' && $target->charid === $actor->charid) {
            return "{$actor->name} already has it.";
        }
        if ($choice['living'] && $target->get_current('HP') <= 0) {
            return "{$target->name} is down.";
        }
        return null;
    }
}
