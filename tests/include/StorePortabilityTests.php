<?php

/**
 * The shop (Store): its stock and conversation, and buying and selling
 * -- every price the server's own, each team paying from and paid into
 * its own purse.
 */
trait StorePortabilityTests {

    private function shoppers(int $gold = 25): array {
        $this->reset_tables(PartyState::class, TEAM::class, HERO::class, MonsterToken::class, Alliance::class,
            FIGHT::class, FightPlayerState::class);
        $this->reset_hero_cache();
        $team = TEAM::create($this->db, 7, 'Buyers', [['name' => 'Ann', 'jobid' => 2, 'personalityid' => 1]]);
        $team->gold = $gold;
        $team->save($this->db);
        PartyState::choose_team($this->db, 7, $team->teamid);
        return [$team, array_values($team->characters)[0]];
    }

    private function purse(TEAM $team): int {
        return TEAM::load($this->db, $team->teamid)->gold;
    }

    private function bag(CHARACTER $hero): array {
        $this->reset_hero_cache();
        return HERO::load_character($this->db, $hero->charid)->inventory;
    }

    public function test_the_shop_sells_what_the_party_could_afford() {
        [$team] = $this->shoppers(25);
        $party = PartyState::party($this->db, 7);
        $stock = Store::stock($party, [$team]);
        $this->assertSame([3, 4, 2, 1], array_slice(array_keys($stock), 0, 4), 'cheapest first, then by name: the fixtures all cost 10');
        $this->assertSame(10, $stock[1]);
        $team->gold = 5;
        $this->assertSame([], Store::stock($party, [$team]));
    }

    public function test_the_conversation_offers_buy_sell_and_leave_with_the_stock() {
        $text = Store::hub_dialog([1 => 10, 2 => 10]);
        $this->assertStringContainsString('`switch,Buy,buy,Sell,sell,Leave,bye`', $text);
        $this->assertStringContainsString('`vend,1,,2,,start`', $text);
        $this->assertStringContainsString('`hawk,75,start`', $text);
        $this->assertStringEndsWith("Come back soon!`pause,2000`", $text, 'a moment to read the goodbye');
        $this->assertStringContainsString("I've nothing you can afford today.", Store::hub_dialog([]));
    }

    public function test_buying_takes_the_gold_and_keeps_the_item() {
        [$team, $ann] = $this->shoppers(25);
        [$problem, $message] = Store::buy($this->db, 7, 1, $ann->charid, 2);
        $this->assertNull($problem);
        $this->assertSame('Ann bought 2 Stackable Potion for 20 gold.', $message);
        $this->assertSame(5, $this->purse($team));
        $this->assertEquals([['item' => 1, 'qty' => 2]], $this->bag($ann));
    }

    public function test_nothing_is_bought_that_cannot_be() {
        [$team, $ann] = $this->shoppers(25);
        $this->assertSame("Buyers can't afford that.", Store::buy($this->db, 7, 1, $ann->charid, 3)[0]);
        $this->assertSame('How many?', Store::buy($this->db, 7, 2, $ann->charid, 2)[0], 'swords one at a time');
        $this->assertSame("That isn't for sale.", Store::buy($this->db, 7, 999, $ann->charid, 1)[0]);
        $this->assertSame("That hero isn't in your party.", Store::buy($this->db, 8, 1, $ann->charid, 1)[0]);
        $this->assertSame(25, $this->purse($team));
        $this->assertSame([], $this->bag($ann));
    }

    public function test_selling_pays_three_quarters_of_the_price() {
        [$team, $ann] = $this->shoppers(25);
        Store::buy($this->db, 7, 1, $ann->charid, 2);
        [$problem, $message] = Store::sell($this->db, 7, $ann->charid, 0, 1);
        $this->assertNull($problem);
        $this->assertSame('Ann sold 1 Stackable Potion for 8 gold.', $message);
        $this->assertSame(13, $this->purse($team));
        $this->assertEquals([['item' => 1, 'qty' => 1]], $this->bag($ann));
        $this->assertSame('How many?', Store::sell($this->db, 7, $ann->charid, 0, 2)[0]);
        $this->assertSame("Ann doesn't have that.", Store::sell($this->db, 7, $ann->charid, 3, 1)[0]);
    }

    public function test_the_goods_to_sell_list_every_heros_items_at_three_quarters() {
        [, $ann] = $this->shoppers(25);
        Store::buy($this->db, 7, 1, $ann->charid, 2);
        $goods = Store::goods($this->db, 7);
        $this->assertCount(1, $goods);
        $this->assertSame(['Ann', 0, 1, 'Stackable Potion', 2], [$goods[0]['hero'], $goods[0]['index'], $goods[0]['itemid'], $goods[0]['name'], $goods[0]['qty']]);
        $this->assertEquals(7.5, $goods[0]['each']);
        $this->assertSame([], Store::goods($this->db, 8), 'no party');
    }
}
