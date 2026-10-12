<?php
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'item.php';

/**
 * The shop, as dialog text: what the hub's shopkeeper says and sells is
 * written here as a conversation (dialog_text.js), with the vend tag for
 * buying and the hawk tag for selling -- the same tags an NPC's store will
 * use in the overworld. The server decides the stock and every price; the
 * text is only how it's shown, and buy() and sell() check against stock()
 * and MARGIN, never against what the client sends.
 *
 *   stock()        what the hub's shop sells: every active item priced up
 *                  to the most gold any team in the party has, or the
 *                  priciest thing a hero carries (as store.php did)
 *   hub_dialog()   the conversation: Buy, Sell or Leave, round again
 *   buy(), sell()  a purchase or sale; each team pays from, and is paid
 *                  into, its own purse -- the hero's team
 */
final class Store {
    // Selling pays this percent of an item's price.
    const MARGIN = 75;

    // [itemid => price], cheapest first, then by name.
    public static function stock(PARTY $party, array $teams): array {
        $most = 0;
        foreach ($teams as $team) {
            $most = max($most, $team->gold);
        }
        foreach ($party->groups as $group) {
            foreach ($group->characters as $character) {
                foreach ($character->inventory as $slot) {
                    $most = max($most, ITEM::get($slot['item'])->price);
                }
            }
        }
        $items = array_filter(ITEM::load_all_from_cache(),
            fn(ITEM $item) => $item->itemid > 0 && $item->state === ObjectState::ACTIVE && $item->price > 0 && $item->price <= $most);
        uasort($items, fn(ITEM $a, ITEM $b) => [$a->price, $a->name] <=> [$b->price, $b->name]);
        return array_map(fn(ITEM $item) => $item->price, $items);
    }

    // The shopkeeper's conversation. A blank price in the vend tag means
    // the item's own price; the hub's shop has no special prices.
    public static function hub_dialog(array $stock): string {
        $wares = [];
        foreach (array_keys($stock) as $itemid) {
            $wares[] = $itemid;
            $wares[] = '';
        }
        $vend = $wares === []
            ? "`label,buy`I've nothing you can afford today.\n`jump,start`"
            : '`label,buy``vend,' . implode(',', $wares) . ",start`Take a look.\n";
        return "Welcome to the shop!\n"
            . "`label,start``switch,Buy,buy,Sell,sell,Leave,bye`What can I do for you?\n"
            . "`jump,bye`"
            . $vend
            . '`label,sell``hawk,' . self::MARGIN . ",start`What have you got?\n"
            . "`label,bye`Come back soon!`pause,2000`"; // time to read it before the window goes
    }

    // The player's party, its teams by id, and the hero with its team, or
    // why not.
    private static function context(Database $db, int $playerid, int $charid): array {
        if ((new FightCommunicator($db, $playerid))->is_in_active_fight()) {
            return ['Finish your fight first.'];
        }
        $party = PartyState::party($db, $playerid);
        $position = $party?->get_character_position_by_id($charid);
        if ($position === null) {
            return ["That hero isn't in your party."];
        }
        $teams = [];
        foreach (array_keys($party->teams) as $teamid) {
            $teams[$teamid] = TEAM::load($db, (int) $teamid);
        }
        $hero = $party->groups[$position[0]]->characters[$position[1]];
        return [null, $party, $teams, $hero, $teams[$hero->teamid] ?? null];
    }

    public static function dialog_for(Database $db, int $playerid): ?string {
        $party = PartyState::party($db, $playerid);
        if ($party === null) {
            return null;
        }
        $teams = array_map(fn($teamid) => TEAM::load($db, (int) $teamid), array_keys($party->teams));
        return self::hub_dialog(self::stock($party, $teams));
    }

    // What the party could sell, and for how much each:
    // [['charid', 'hero', 'teamid', 'index', 'itemid', 'name', 'qty',
    //   'each'], ...], hero by hero.
    public static function goods(Database $db, int $playerid): array {
        $party = PartyState::party($db, $playerid);
        $goods = [];
        foreach ($party?->groups ?? [] as $group) {
            foreach ($group->characters as $hero) {
                foreach ($hero->inventory as $index => $slot) {
                    $item = ITEM::get($slot['item']);
                    $goods[] = ['charid' => $hero->charid, 'hero' => $hero->name, 'teamid' => $hero->teamid, 'index' => $index,
                        'itemid' => $item->itemid, 'name' => $item->name, 'qty' => (int) $slot['qty'],
                        'each' => self::sale_price($item->price)];
                }
            }
        }
        return $goods;
    }

    // Buys $qty of $itemid for hero $charid. [problem or null, message]
    public static function buy(Database $db, int $playerid, int $itemid, int $charid, int $qty): array {
        $context = self::context($db, $playerid, $charid);
        if ($context[0] !== null) {
            return [$context[0], ''];
        }
        [, $party, $teams, $hero, $team] = $context;
        $stock = self::stock($party, $teams);
        if (!isset($stock[$itemid]) || $team === null) {
            return ["That isn't for sale.", ''];
        }
        $item = ITEM::get($itemid);
        $price = $stock[$itemid];
        if ($qty < 1 || ($qty > 1 && !self::stacks($item))) {
            return ['How many?', ''];
        }
        if ($price * $qty > $team->gold) {
            return ["{$team->name} can't afford that.", ''];
        }
        $left = $hero->add_item($itemid, $qty);
        $bought = $qty - $left;
        if ($bought === 0) {
            return ["{$hero->name} can't carry any more.", ''];
        }
        $team->gold -= $price * $bought;
        HERO::save_character($db, $hero);
        $team->save($db);
        return [null, "{$hero->name} bought {$bought} {$item->name} for " . ($price * $bought) . ' gold.'
            . ($left > 0 ? " (No room for {$left} more.)" : '')];
    }

    // Sells $qty of hero $charid's inventory item $index. [problem or
    // null, message]
    public static function sell(Database $db, int $playerid, int $charid, int $index, int $qty): array {
        $context = self::context($db, $playerid, $charid);
        if ($context[0] !== null) {
            return [$context[0], ''];
        }
        [, , , $hero, $team] = $context;
        if (!isset($hero->inventory[$index]) || $team === null) {
            return ["{$hero->name} doesn't have that.", ''];
        }
        if ($qty < 1 || $qty > $hero->inventory[$index]['qty']) {
            return ['How many?', ''];
        }
        $item = ITEM::get($hero->inventory[$index]['item']);
        $sold = $hero->remove_item($index, $qty);
        $worth = (int) round($sold * self::sale_price($item->price));
        $team->gold += $worth;
        HERO::save_character($db, $hero);
        $team->save($db);
        return [null, "{$hero->name} sold {$sold} {$item->name} for {$worth} gold."];
    }

    public static function sale_price(int $price): float {
        return $price * self::MARGIN / 100;
    }

    // Whether several of $item fit in one inventory slot (not equipment,
    // except ammunition) -- more than one of anything else is bought one
    // at a time.
    public static function stacks(ITEM $item): bool {
        return count((array) $item->equip_type) === 0
            || count(array_intersect((array) $item->equip_type, ['ammo', 'lammo', 'rammo'])) > 0;
    }
}
