<?php
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'common.php';

require_once INCLUDE_GAME . 'effects.php';
require_once INCLUDE_GAME . 'array.php';
require_once INCLUDE_GAME . 'constants.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'functions.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'pointer_index.php';
require_once INCLUDE_GAME . 'effect_spec.php';
require_once INCLUDE_GAME . 'action_target.php';
require_once INCLUDE_GAME . 'hero.php';
require_once INCLUDE_GAME . 'equip_result.php';

class CHARACTER implements DehydrateInterface {
    use DehydrateTrait {
        hydrate as private hydrate_common;
    }

    // Team ID carried by every monster character, and by the fight
    // player state of a player controlling a monster party. Real teams
    // are always positive; null means "no team" (spectators). Giving
    // monsters a real value keeps ownership one uniform check --
    // character->teamid === viewer's teamid -- for heroes and monsters
    // alike, and a spectator's null can never match anything.
    const MONSTER_TEAMID = -1;

    //character data
    public ?int $charid;
    public ?int $teamid;
    public string $name;
    public ?int $jobid;
    public ?int $level;
    public ?int $exp;
    public ?int $need;
    public array $current;
    public array $base;
    public array $inventory;
    public array $equipment;
    public array $abilities;
    public int $pxp;
    public ?int $personalityid;

    //Fight data
    var $xp_debts;
    var $old_exp;

    //For monsters only
    var $gold;
    var $ai_action;
    var $ai_goal;
    var $ai_target;
    var $ai_experience;

    //gameplay data
    public int $command;
    public int $using;
    var $target;

    function __construct() {
        $this->base = create_array($GLOBALS['character_stats'], array(), 0);
        $this->current = create_array($GLOBALS['character_stats'], array(), 0);
        $this->equipment = create_array($GLOBALS['character_equipment'], array(), null);
        $this->abilities = array();
        $this->inventory = array();
        $this->command = 0;
        $this->using = 0;
        $this->target = array(1, 0, 0);
        $this->charid = null;
        $this->teamid = null;
        $this->jobid = null;
        $this->need = null;
        $this->calculate_pxp();
        $this->personalityid = null;
        $this->gold = 0;
        $this->exp = 0;
        $this->ai_action = 0; //Stupid
        $this->ai_goal = 0; //Random
        $this->ai_target = 0; //No filtering
        $this->ai_experience = 50; //50% stat skewing
        $this->xp_debts = array(); //We owe nobody xp yet.
        $this->old_exp = 0; //This gets set when the party joins a fight.
    }

    function make_leveled_hero(string $name, int $jobid, int $level, ?int $personalityid, int $charid = 0): void {
        $this->charid = $charid;
        $this->name = $name;
        $this->jobid = $jobid;
        $this->personalityid = $personalityid;
        $this->level = 0;

        if (array_key_exists($this->jobid, JOB::load_all_from_cache())) {
            $job = JOB::get($this->jobid);
            $job->don($this);
            while ($this->level < $level) {
                $this->exp = $this->need;
                $this->level();
            }
            $this->current = $this->base;
            $this->calculate_pxp();
        } else {
            log_error("Trying to make a character with unknown job {$this->jobid}.");
        }
    }

    public static function create_hero(string $name, int $jobid, int $level, ?int $personalityid, int $charid = 0): CHARACTER {
        $character = new CHARACTER();
        $character->make_leveled_hero($name, $jobid, $level, $personalityid, $charid);
        return $character;
    }

    function make_hero(
        int $charid,
        string $name,
        int $jobid,
        int $level,
        int $exp,
        int $need,
        array $base,
        array $current,
        array $abilities,
        array $inventory,
        array $equipment,
        int $personalityid,
        ?int $teamid
    ) {
        $this->charid = $charid;
        $this->name = $name;
        $this->level = $level;
        $this->jobid = $jobid;
        $this->exp = $exp;
        $this->need = $need;
        $this->personalityid = $personalityid;
        $this->teamid = $teamid;

        $this->base = array_merge(create_array($GLOBALS['character_stats'], [], 0), $base);
        $this->current = array_merge(create_array($GLOBALS['character_stats'], [], 0), $current);
        $this->equipment = array_merge(create_array($GLOBALS['character_equipment'], array(), null), $equipment);

        $this->inventory = $inventory;
        $this->abilities = $abilities;
        uasort($this->abilities, 'ability_sort');
        $this->calculate_pxp();
    }

    function make_monster(
        string $name,
        array $base,
        array $current,
        array $abilities,
        int $personalityid,
        int $gold,
        int $ai_action,
        int $ai_goal,
        int $ai_target,
        int $ai_experience
    ) {
        $this->name = $name;
        $this->teamid = self::MONSTER_TEAMID;
        $this->level = -1;
        $this->jobid = null;
        $this->personalityid = $personalityid;

        $this->base = $base;
        $this->current = $current;
        $this->equipment = create_array($GLOBALS['character_equipment'], array(), null);

        $this->inventory = array();
        $this->abilities = $abilities;
        $this->calculate_pxp();
        $this->gold = $gold;
        $this->ai_action = $ai_action;
        $this->ai_goal = $ai_goal;
        $this->ai_target = $ai_target;
        $this->ai_experience = $ai_experience;

        //Sort the list so abilities are alphabetic
        uasort($this->abilities, 'ability_sort');
    }

    function regenerate_stats(): array //WARNING- dangerous
    {
        //Recreate this character's stats and abilities.
        //Leave items and exp alone.
        //Level as needed.

        //Reset stats
        $this->base = create_array($GLOBALS['character_stats'], array(), 0);
        $this->current = create_array($GLOBALS['character_stats'], array(), 0);

        //Remove abilities.
        $this->abilities = array();

        //Keep old exp.
        $exp = $this->exp;

        //Reset to level 0.
        $this->level = 0;

        //Get this job (re)started.
        $job = JOB::get($this->jobid);
        $job->don($this);

        //Reset the old exp.
        $this->exp = $exp;

        //Level this character as needed.
        $retval = $this->level();

        //Max all stats.
        $this->reset_stats(true);

        //return the leveling results.
        return $retval;
    }

    function calculate_pxp(): int {
        //This function finds the current Potential eXperience Points
        //of this character.  Equipment but not status effects are
        //taken into acount.

        //First collect default values:
        //Hit Point Max
        $hp = $this->get_base('HP', true);
        //Magic Point Max
        $mp = $this->get_base('MP', true);
        //Accuracy
        $ac = $this->get_base('Accuracy', true);
        if ($ac < 1) $ac = 1;
        //Dodge
        $do = $this->get_base('Dodge', true);
        if ($do < 1) $do = 1;
        //Strength
        $st = $this->get_base('Strength', true);
        if ($st < 1) $st = 1;
        //Block
        $bl = $this->get_base('Block', true);
        if ($bl < 1) $bl = 1;
        //Speed
        $sp = $this->get_base('Speed', true);
        if ($sp < 1) $sp = 1;
        //Power
        $po = $this->get_base('Power', true);
        if ($po < 1) $po = 1;
        //Resistance
        $re = $this->get_base('Resistance', true);
        if ($re < 1) $re = 1;
        //Focus
        $fo = $this->get_base('Focus', true);
        if ($fo < 1) $fo = 1;

        //Part 1: Physical Defense
        //(Dodge*Block*HP)^(1/3)
        $pdef = round(pow($do, 1.0 / 3) * pow($bl, 1.0 / 3) * pow($hp, 1.0 / 3));

        //Part 2: Physical Offense
        //(Speed*Accuracy*Offense)^(1/3)
        //Offense=Strength*Targets*Times
        $weapons = array();
        #Precalc all the stats
        for ($index = 0; $index < 3; $index++) {
            # Get the weapon and ammo count
            if ($index < 2) {
                $weapon = $this->get_equipped_weapon($index);
                $ammo_count = $this->get_ammo_count($index);
            } else {
                $weapon = ITEM::get(0);
                $ammo_count = null;
            }
            # If the weapon is null, then skip.
            if (is_null($weapon))
                continue;
            $name = $weapon->name;
            # Get the number of attacks per turn.
            $times = $weapon->attack_count;
            # If the weapon has ammo, divide by the number of attacks per turn and round up.
            if (!is_null($ammo_count))
                $ammo_count = ceil($ammo_count / $times);
            #Weapon rating based on targets
            # (The bare-handed entry, 2, rates ITEM 0 itself: going by the
            # right side instead finds nothing when a two-handed weapon
            # fills both hands.)
            $weapon_effect_spec = $index < 2 ? $this->get_weapon_effect_spec($index)
                : $weapon->get_weapon_effect_spec($this->get_current('Strength', $index));
            $targets = $weapon_effect_spec->get_target_rating();
            #Get this weapon's strength
            $wst = $this->get_base('Strength', $index);
            if ($wst < 1) $wst = 1;
            #Get this weapon's speed
            $wsp = $this->get_base('Speed', $index);
            if ($wsp < 1) $wsp = 1;
            #Modify the speed based on the number of attacks per turn.
            $wsp *= speed_multiplier($times);
            #Get this weapon's accuracy
            $wac = $this->get_base('Accuracy', $index);
            if ($wac < 1) $wac = 1;
            #Store stats
            $weapons[$index] = array($times, $ammo_count, $times, $targets, $wsp, $wac, $wst, $name);
        }
        #Now score the offensive power
        $offense = array();
        for ($index = 0; $index < 2; $index++) {
            if (!isset($weapons[$index]))
                continue;
            list($times, $ammo_count, $times, $targets, $wsp, $wac, $wst, $name) = $weapons[$index];
            $turn = $ammo_count;
            # Calculate the offense multiplier (based on the number of turns that may use this attack.)
            $omultiplier = halflife(0, $turn);
            //Compute attack damage
            $total_damage = $targets * $wst;
            $o = pow($wsp, 1.0 / 3) * pow($wac, 1.0 / 3) * pow($total_damage, 1.0 / 3) * $omultiplier;
            //echo "<br>o1:$o<br>\n";
            # Check for no ammo condition
            if (is_null($turn)) {
                $offense[] = $o;
                continue;
            }
            # Use alternate weapon, if possible
            if (!isset($weapons[1 - $index]))
                continue;
            list($times, $ammo_count, $times, $targets, $wsp, $wac, $wst, $name) = $weapons[1 - $index];
            #If ammo_count is a number, then add turn to it.
            if (!is_null($ammo_count))
                $ammo_count += $turn;
            # Calculate the offense multiplier (based on the number of turns that may use this attack.)
            $omultiplier = halflife($turn, $ammo_count);
            $turn = $ammo_count;
            //Compute attack damage
            $total_damage = $targets * $wst;
            $o += pow($wsp, 1.0 / 3) * pow($wac, 1.0 / 3) * pow($total_damage, 1.0 / 3) * $omultiplier;
            //echo "<br>o2:$o<br>\n";
            # Check again for no ammo condition
            if (is_null($turn)) {
                $offense[] = $o;
                continue;
            }
            # Use final weapon if needed: Nothing
            list($times, $ammo_count, $times, $targets, $wsp, $wac, $wst, $name) = $weapons[2];
            # Calculate the offense multiplier (based on the number of turns that may use this attack.)
            $omultiplier = halflife($turn, $ammo_count);
            //Compute attack damage
            $total_damage = $targets * $wst;
            $o += pow($wsp, 1.0 / 3) * pow($wac, 1.0 / 3) * pow($total_damage, 1.0 / 3) * $omultiplier;
            //echo "<br>o3:$o<br>\n";
            $offense[] = $o;
        }
        //var_dump($weapons);
        //var_dump($offense);
        $poff = round(array_sum($offense) / max(1, count($offense)));

        //Part 3: Magical Defense
        //(Resistance*HP)^(1/2)
        $mdef = round(pow($re, .5) * pow($hp, .5));

        //Part 4: Magical Offense
        //(Magic Use*Speed*Focus*Power)^(1/4)
        //OR (Magic Use*Speed*Accuracy)^(1/3)
        //AVERAGED.
        //Magic Use=pow(sum(floor(MPM/MP Used)*spell rating),(1/3))
        $spells = 0;
        $skills = 0;
        foreach ($this->abilities as $index) {
            $ability = ABILITY::get($index);
            $times = floor($mp >= $ability->mp_used ? 1 : 0);
            //Spell rating based on targets
            $targets = $ability->effect_spec->get_target_rating();
            //Spell rating based on effect
            $rating = $ability->effect_spec->get_effect_rating();
            //Compute ability usefulness
            $muse = $times * sqrt($targets * $rating);
            //Calculate ability score
            if ($ability->type == ABILITY::TYPE_SPELL)
                $spells += $muse;
            else
                $skills += $muse;
        }
        $spells = round(pow($spells, .25) * pow($sp, .25) * pow($fo, .25) * pow($po, .25));
        $skills = round(pow($skills, 1.0 / 3) * pow($sp, 1.0 / 3) * pow($ac, 1.0 / 3));
        $moff = $spells + $skills;
        //$moff=round(array_sum($score)/max(1,count($score)));

        //PXP=pdef+poff+mdef+moff
        $this->pxp = $pdef + $poff + $mdef + $moff;

        return $this->pxp;
    }

    function level(): array {
        $retval = array('leveled' => false, 'data' => array());
        if ($this->level == 100)
            $this->exp = $this->need;
        while ($this->exp >= $this->need && $this->level < 100) {
            $job = JOB::get($this->jobid);
            $this->need += round($job->need / 2.0 * ((($this->level + 3) * ($this->level + 2) / 2) - 1));
            $result = $job->level($this);
            $retval['leveled'] = true;
            $retval['data'][] = "{$this->name} has reached level {$this->level}!";
            $retval['data'] = array_merge($retval['data'], $result);
        }
        $this->calculate_pxp();
        return $retval;
    }

    function add_item(int $itemid, int $qty): int {
        $temp = array();
        $item = ITEM::get($itemid);
        foreach ($this->inventory as $key => $inventory_item)
            $temp[$key] = $inventory_item['item'];
        $keys = array_keys($temp, $itemid);
        foreach ($keys as $index) {
            $inventory_item = &$this->inventory[$index];
            if (
                $inventory_item['qty'] < CHARACTER_MAX_ITEM_QTY
                && (count($item->equip_type) == 0
                    || count(array_intersect($item->equip_type, array('ammo', 'lammo', 'rammo'))) > 0)
            ) {
                if ($inventory_item['qty'] + $qty <= CHARACTER_MAX_ITEM_QTY) {
                    $inventory_item['qty'] += $qty;
                    return 0;
                } else {
                    $qty -= CHARACTER_MAX_ITEM_QTY - $inventory_item['qty'];
                    $inventory_item['qty'] = CHARACTER_MAX_ITEM_QTY;
                }
            }
        }
        if (count($this->inventory) + 1 <= CHARACTER_MAX_ITEMS) {
            array_push($this->inventory, array('item' => $itemid, 'qty' => $qty));
            return 0;
        }
        return $qty;
    }

    function remove_item(int $index, int $qty): int {
        if ($index < count($this->inventory)) {
            if ($this->inventory[$index]['qty'] > $qty)
                $this->inventory[$index]['qty'] -= $qty;
            else {
                //Remove item
                $qty = $this->inventory[$index]['qty'];
                unset($this->inventory[$index]);
                $this->scrub_inventory();
                $this->calculate_pxp();
            }
            return $qty;
        }
        return 0;
    }

    public function move_item(int $from_slot, int $to_slot): void {
        if ($from_slot === $to_slot) {
            return;
        }
        if (!isset($this->inventory[$from_slot])) {
            throw new Exception("No item in slot $from_slot");
        }

        $mapping = [];
        foreach (array_keys($this->inventory) as $slot) {
            if ($slot === $from_slot) {
                $mapping[$slot] = $to_slot;
            } elseif ($from_slot < $to_slot && $slot > $from_slot && $slot <= $to_slot) {
                $mapping[$slot] = $slot - 1;   // forward move: slide down
            } elseif ($from_slot > $to_slot && $slot >= $to_slot && $slot < $from_slot) {
                $mapping[$slot] = $slot + 1;   // backward move: slide up
            } else {
                $mapping[$slot] = $slot;       // untouched
            }
        }
        $this->apply_slot_mapping($mapping);
    }

    function add_ability(int $abilityid): bool {
        if (!in_array($abilityid, $this->abilities)) {
            array_push($this->abilities, $abilityid);
            uasort($this->abilities, 'ability_sort');
            return true;
        }
        return false;
    }

    function get_item(int $index): ITEM|bool {
        if (!isset($this->inventory[$index]))
            return true;
        return ITEM::get($this->inventory[$index]['item']);
    }

    function get_item_qty(int $index): int|bool {
        if (!isset($this->inventory[$index]))
            return true;
        return $this->inventory[$index]['qty'];
    }

    function equip_item(int $index, $side): EquipResult {
        if (($item = $this->get_item($index)) === true)
            return EquipResult::not_found();
        if (count($item->equip_type) > 0) {
            $retval = array();
            $equip_type = $item->equip_type;
            //Check to see if all slots that are needed are empty.
            foreach ($equip_type as $loc) {
                $fix_side = find_slot($loc, $side);
                if (is_null($this->equipment[$fix_side]) === false)
                    return EquipResult::needs_unequipping($this->equipment[$fix_side]);
            }
            //Check to see if this item is already equipped.
            foreach ($this->equipment as $loc => $value) {
                if ($value === $index && !in_array($loc, $equip_type))
                    return EquipResult::needs_unequipping($index);
            }
            //If this is ammo only, check to see if there is a
            //weapon that takes this ammo already equipped.
            if (count($equip_type) == 1 && $equip_type[0] == 'ammo') {
                $fix_side = find_slot('hand', $side);
                if (is_null($this->equipment[$fix_side])) {
                    return EquipResult::incompatible_ammo();
                }
                $eitem = $this->get_item($this->equipment[$fix_side]);
                if ($item->ammo_type != $eitem->ammo_type) {
                    return EquipResult::incompatible_ammo();
                }
                //A quick side correction for ammo in a two handed weapon
                if (count(array_intersect(array('lhand', 'rhand'), $eitem->equip_type)) == 2)
                    $side = 0;
            }
            //Equip this weapon.
            foreach ($item->equip_type as $loc) {
                $slot = find_slot($loc, $side);
                $this->equipment[$slot] = $index;
                $retval[] = $slot;
            }
            $this->scrub_inventory();
            $this->calculate_pxp();
            return EquipResult::success($retval);
        }
        return EquipResult::not_equipable();
    }

    function unequip_item(int $index) : EquipResult {
        if (($item = $this->get_item($index)) === true)
            return EquipResult::not_found();
        if (count($item->equip_type) > 0) {
            // The side whose ammo goes with this weapon: the hand it's in,
            // or the left for a two-handed one (equip_item() puts a
            // two-handed weapon's ammo in lammo).
            $side = '';
            $retval = array();
            foreach ($this->equipment as $loc => $value) {
                if ($value === $index) {
                    if ($loc === 'lhand' || $loc === 'rhand')
                        $side = $side === '' ? substr($loc, 0, 1) : 'l';
                    $this->equipment[$loc] = null;
                    $retval[] = $loc;
                }
            }
            if (
                $side != ''
                && $item->ammo_type != ''
                && count(array_intersect(array('ammo', 'lammo', 'rammo'), $item->equip_type)) == 0
            ) {
                $this->equipment[$side . 'ammo'] = null;
                $retval[] = $side . 'ammo';
            }
            $this->scrub_inventory();
            $this->calculate_pxp();
            return EquipResult::success($retval);
        }
        return EquipResult::not_equipable();
    }

    function get_equipped_item(string $slot, ?int $side): ITEM|bool|null {
        $fix_slot = find_slot($slot, $side);
        $index = $this->equipment[$fix_slot];
        if (is_null($index))
            return null;
        return $this->get_item($index);
    }

    // One weapon in both hands (a two-handed weapon: equip_item() puts
    // the same inventory slot in lhand and rhand). Then only the left
    // hand attacks. Compared strictly: slot 0 is a real slot, and an
    // empty hand (null) is not the same as slot 0 -- a loose == said it
    // was, so a hero with a weapon in slot 0 in one hand and nothing in
    // the other could never attack with the empty hand, and the AI,
    // thinking the same, offered them nothing else.
    function hands_share_weapon(): bool {
        $left = $this->equipment['lhand'] ?? null;
        $right = $this->equipment['rhand'] ?? null;
        return $left !== null && $right !== null && (int) $left === (int) $right;
    }

    function get_equipped_weapon(?int $side): ?ITEM {
        $weapon = $this->get_equipped_item('hand', $side);
        if (is_null($weapon))
            return ITEM::get(0);
        if ($side == 1 && $this->hands_share_weapon())
            return null;
        return $weapon;
    }

    function get_equipped_ammo(?int $side): ?ITEM {
        $ammo = $this->get_equipped_item('ammo', $side);
        if (!is_null($ammo) && $this->equipment[find_slot('hand', $side)] === $this->equipment[find_slot('ammo', $side)])
            return null;
        return $ammo;
    }

    function get_ammo_count(?int $side): ?int {
        $fix_slot = find_slot('ammo', $side);
        $index = $this->equipment[$fix_slot];
        if (is_null($index))
            return null;
        return $this->get_item_qty($index);
    }

    function get_equipment_bonus(string $stat, int $base, int|bool $all_equipment = false): int {
        $command = (is_numeric($all_equipment) ? $all_equipment : $this->command);
        $equipment = array();
        foreach ($this->equipment as $slot => $inventory_index)
            if (!is_null($inventory_index) && (
                $all_equipment === true
                || !in_array($stat, array('Strength', 'Accuracy', 'Speed'))
                || ($command == 0 && !in_array($slot, array('rammo', 'rhand')))
                || ($command == 1 && !in_array($slot, array('lammo', 'lhand')))
                || ($command > 1 && !in_array($slot, array('lammo', 'lhand', 'rammo', 'rhand')))))
                $equipment[$inventory_index] = $this->get_item($inventory_index);
        $percinc = 0;
        foreach ($equipment as $item)
            $percinc += $item->stat_percent_increase[$stat];
        $base *= ($percinc / 100.0 + 1.0);
        foreach ($equipment as $item)
            $base += $item->stat_increase[$stat];
        return (int)round($base);
    }

    function get_base(string $stat, bool|int $all_equipment = false): int {
        $base = $this->base[$stat];
        if ($all_equipment !== false || in_array($stat, array('HP', 'MP')))
            $base = $this->get_equipment_bonus($stat, $base, $all_equipment);
        return $base;
    }

    function get_current(string $stat, bool|int $all_equipment = false): int {
        $current = $this->current[$stat];
        if (!in_array($stat, array('HP', 'MP'))) {
            $current = $this->get_equipment_bonus($stat, $current, $all_equipment);
        }
        return $current;
    }

    function reset_stats(bool $all = false): void {
        foreach ($this->base as $stat => $value)
            if ($all === true || !in_array($stat, array('HP', 'MP')))
                $this->current[$stat] = $value;
    }

    function normalize_stats(bool $all = false): void {
        foreach ($this->current as $stat => $value)
            if ($all === true || !in_array($stat, array('HP', 'MP'))) {
                $diff = ($value - $this->base[$stat]) / 10;
                if ($diff > 0)
                    $this->current[$stat] -= (int)ceil($diff);
                else
                    $this->current[$stat] -= (int)floor($diff);
            }
    }

    function find_target_range(?int $weapon, ?int $ammo): int {
        if (is_null($weapon))
            return ITEM::get(0)->attack_targets;
        if (is_null($ammo))
            return ITEM::get($this->inventory[$weapon]['item'])->attack_targets;
        return ITEM::get($this->inventory[$ammo]['item'])->attack_targets;
    }

    function to_js(): string|false {
        return json_encode(get_object_vars($this));
    }

    const DEHYDRATE_VERSION = 1;

    // Every property dehydrate()/hydrate() round-trip -- everything
    // needed to fully reconstitute a character mid-fight, not just its
    // persistent identity/progression data. All direct projections;
    // none of these need computation in either direction, so
    // dehydrate_nested()/hydrate_nested() are left at DehydrateTrait's
    // default no-op -- CHARACTER has no nested-object properties.
    //
    // command/using/target excluded: a player's submitted-but-not-yet-
    // resolved action isn't a turn outcome (the action log has no
    // business carrying it), and isn't meant to round-trip through
    // dehydrate()/hydrate() as CHARACTER's own state either -- it's
    // tracked in fight_pending_actions now (see pending_action.php),
    // sourced per-player rather than baked into whichever fight
    // snapshot happens to get saved. See hydrate() below for how these
    // are still safely defaulted despite not being projected here.
    private const DEHYDRATE_PROPERTIES = [
        'charid', 'teamid', 'name', 'jobid', 'level', 'exp', 'need',
        'current', 'base', 'inventory', 'equipment', 'abilities',
        'pxp', 'personalityid',
        'xp_debts', 'old_exp',
        'gold', 'ai_action', 'ai_goal', 'ai_target', 'ai_experience',
    ];

    // hydrate_common() (the trait's own hydrate(), aliased in above)
    // bypasses the constructor entirely, so whatever it doesn't set
    // from DEHYDRATE_PROPERTIES is left genuinely uninitialized -- the
    // same fatal "must not be accessed before initialization" class of
    // bug $need hit, not a merely-unset value. Since command/using/
    // target no longer round-trip through DEHYDRATE_PROPERTIES at all,
    // they'd be exactly that: uninitialized, typed, non-nullable
    // properties, immediately after hydrate() returns -- this override
    // exists purely to prevent that, falling back to the same defaults
    // __construct() itself uses. FIGHT::apply_pending_actions() is
    // expected to overwrite these from fight_pending_actions right
    // after hydration -- this fallback only has to be safe to read,
    // not meaningful to act on.
    //
    // No longer version-branched: this briefly read old inline values
    // for data dehydrated before command/using/target were removed
    // (DEHYDRATE_VERSION 2 at the time), to prove DehydrateTrait's
    // version hook actually worked -- confirmed, and with nothing but
    // test data around to preserve, DEHYDRATE_VERSION reset to 1 and
    // the branch was no longer needed.
    static function hydrate(array $data): static {
        $object = static::hydrate_common($data);
        $object->command = 0;
        $object->using = 0;
        $object->target = array(1, 0, 0);
        return $object;
    }

    // Fields visible to a player for one of their own team's
    // characters: identity plus whatever gameplay actually needs.
    // Deliberately NOT full base/current stats, even for your own
    // roster -- HP/MP only, to preserve player intuition as part of
    // the strategy rather than exposing every number. abilities/
    // equipment/inventory are included in full, though: the UI needs
    // these to actually populate the action menu's real options (which
    // ability, which item, what's equipped), not just display state.
    // jobid: confirmed missing entirely from an earlier version of
    // this projection -- caused every hero's class to fall through to
    // whatever the client defaults to when the lookup key is absent
    // ("Monster", in practice).
    private const TO_CLIENT_OWN_TEAM_PROPERTIES = ['charid', 'name', 'personalityid', 'jobid', 'command', 'using', 'target', 'abilities', 'equipment', 'inventory'];

    /**
     * Client-sanitized view of this character, scoped to what the
     * requesting player actually has control over or can rightfully
     * see. $is_own_team distinguishes the two cases this currently
     * supports:
     *
     * - Own team: identity, HP/MP only (not full stats), the
     *   currently-queued command/using/target (command memory means
     *   this is real server state the UI needs to reflect back), and
     *   full abilities/equipment/inventory -- the UI needs these to
     *   populate the action menu's actual options, not just display
     *   current state.
     * - Non-allied: name only, plus a to-be-tiered HP representation.
     *   No identity (real charid/teamid must never reach a non-owning
     *   client -- callers are expected to replace this with
     *   ActionTarget-style positional addressing instead), and no
     *   command/using/target at all -- never tiered, always omitted,
     *   since foreknowledge of an enemy's queued action is a direct
     *   tactical advantage HP visibility isn't.
     *
     * personalityid is included for both cases -- confirmed by a real
     * client crash otherwise (personalities[hero.personalityid] was
     * undefined, needed to look up which sprite/animation set to
     * render). Unlike charid/command/using/target, it isn't tactical
     * or identifying information worth withholding: a player already
     * sees an enemy's sprite rendered on screen, so the lookup key for
     * which one to show doesn't reveal anything the HP-display
     * scrubbing above is actually trying to hide.
     *
     * determine_hp_display() is a stub: the real PvE (kill-count
     * unlock) / PvP (relative-PXP-gap) tiering logic isn't designed
     * yet. This always returns the full fraction for now, as a
     * placeholder -- not a claim that unscrubbed HP is the intended
     * final behavior for non-allied characters.
     *
     * STOPGAP as of this writing: current/base HP+MP are sent for
     * non-allied characters too, the same numeric shape as own team,
     * not the hp_display tiering above. The client's own hide/show
     * and numeric-vs-bar display logic for this exists but was never
     * actually integrated -- it always defaults to showing full
     * numeric HP/MP for every combatant regardless -- so withholding
     * real numbers here (as the tiering design above intends) broke
     * the client outright rather than degrading gracefully. This
     * restores what the client currently needs to function; it is
     * explicitly not the intended final behavior once the client side
     * is wired up to actually use hp_display/tiering, at which point
     * this block should come back out.
     *
     * Note: this is a plain CHARACTER method, not part of
     * DehydrateInterface/DehydrateTrait -- unlike dehydrate()/hydrate(),
     * its logic (and likely its signature) is genuinely bespoke per
     * class, not a generic projection worth sharing.
     */
    function to_client(bool $is_own_team, bool $is_own_party = false): array {
        if ($is_own_team) {
            $data = $this->project_properties(self::TO_CLIENT_OWN_TEAM_PROPERTIES);
            $data['current'] = ['HP' => $this->current['HP'], 'MP' => $this->current['MP']];
            $data['base'] = ['HP' => $this->base['HP'], 'MP' => $this->base['MP']];
            return $data;
        }
        return [
            'name' => $this->name,
            'personalityid' => $this->personalityid,
            // Party-mates on a different team within the same
            // multi-team party still see each other's class -- it's
            // not team-private the way command/using/target/abilities/
            // equipment/inventory are; seeing an ally's class doesn't
            // reveal their queued strategy. Withheld (omitted, not a
            // sentinel value) for anyone outside the party, same as
            // before.
            ...($is_own_party ? ['jobid' => $this->jobid] : []),
            'hp_display' => $this->determine_hp_display(),
            // Stopgap -- see this method's own docblock.
            'current' => ['HP' => $this->current['HP'], 'MP' => $this->current['MP']],
            'base' => ['HP' => $this->base['HP'], 'MP' => $this->base['MP']],
        ];
    }

    /**
     * Stub. Will eventually decide which HP tier (numeric / percentage
     * / fraction-only bar / hidden entirely) a non-allied character's
     * HP is shown as, based on PvE kill-count progression or PvP
     * relative-PXP gap -- neither of which is designed yet. Always
     * returns the full fraction for now.
     */
    private function determine_hp_display(): float {
        $base_hp = $this->base['HP'];
        if ($base_hp <= 0)
            return 0.0;
        return $this->current['HP'] / $base_hp;
    }

    // true: ammo not needed
    // false: out of ammo
    // int: ammo consumed from slot referenced.
    function expend_ammo(?int $side): bool|int {
        //Return true means do attack, false means need ammo.
        //0 means left, 1 means right.
        if ($side === 0)
            $side = 'l';
        else
            $side = 'r';
        $wpn = $this->equipment[$side . "hand"];
        if (is_null($wpn)) {
            //There is no weapon.
            return true;
        }
        $ammo = $this->equipment[$side . "ammo"];
        $witem = $this->get_item($wpn);
        if ($witem->ammo_type == '') {
            //Doesn't need ammo.  Check to see that the wpn is not the ammo.
            if (is_null($ammo)) {
                return true;
            }
        }
        //This wpn is expendable or uses ammo.  If ammo is not present, then fail.
        if (is_null($ammo)) {
            return false;
        }
        //If the ammo qty is >0, then decrement and return true
        if ($this->inventory[$ammo]['qty'] > 0) {
            # Don't remove ammo yet. Might be needed in damage calcaultions if it's the last bullet.
            //$this->remove_item($ammo, 1);
            return $ammo;
        }
        //Otherwise we are out of ammo.
        return false;
    }

    function validate_action(ActionScope $scope): bool {
        switch ($this->command) {
            case 0: //Left hand attack
            case 1: //Right hand attack
                if ($scope->get_scope_type() != ScopeType::COMBAT)
                    return false;
                //If there is something in the left hand and it's the
                //same as the right, then we can't attack with the right.
                if ($this->command == 1 && $this->hands_share_weapon())
                    return false;
                break;
            case 2: //Use item
                if (!isset($this->inventory[$this->using]['item']))
                    return false;
                break;
            case 9: //Give item
                if ($scope->get_scope_type() != ScopeType::FIELD)
                    return false;
                if (!isset($this->inventory[$this->using]['item']))
                    return false;
                break;
            case 4: //Use skill
            case 5: //Cast spell
                if (!isset($this->abilities[$this->using]))
                    return false;
                break;
            case 8: //Change equipment and ammo
                if (!isset($this->inventory[$this->target[0]]['item']))
                    return false;
            case 3: //Change equipment
                if ($this->using == -1) {
                    if (!isset($this->inventory[$this->target[0]]['item']))
                        return false;
                } elseif (!isset($this->inventory[$this->using]['item'])) {
                    return false;
                }
                break;
            case 6: //Defend
            case 7: //Run
                break; //Do not do anything.
            default: //Bad action
                return false;
        }
        //Confirm action
        return true;
    }

    function validate_target(ActionScope $scope, EffectSpec $effect_spec): bool {
        $range = $effect_spec->targets;
        $effect = $effect_spec->effect;
        $parties = $scope->get_parties();
        switch ($this->command) {
            case 3: //Change equipment
            case 8: //Change equipment
            case 7: //Run
            case 6: //Defend
                return false; //This action does not require a target, anything is good.
        }
        list($target_party_index, $target_group_index, $target_char_index) = $this->target;
        if (!$effect->only_living_targets())
            return false;
        switch ($range) {
            //If it is all parties, then we can act.
            case EffectSpec::TARGET_ALL_PARTIES:
                return false;
                //If it is party, verify our party is still alive.
            case EffectSpec::TARGET_PARTY:
                //NOTE: later we will detect party alignment and try
                //to recast on another party of the same alignment as the
                //intended target.  For now, don't cast if the
                //target party is dead.
                //Verify the party exists.
                if (array_key_exists($target_party_index, $parties) == false)
                    return true;
                return $parties[$target_party_index]->is_dead();
                //If it is group, verify that the group is still alive.
            case EffectSpec::TARGET_GROUP:
                //Verify the party exists.
                if (array_key_exists($target_party_index, $parties) == false)
                    return true;
                //Return false if the target is still alive.
                //Verify that the group exists and the group is alive.
                if (
                    array_key_exists($target_group_index, $parties[$target_party_index]->groups) &&
                    !$parties[$target_party_index]->groups[$target_group_index]->is_dead()
                )
                    return false;
                //OK, is the party dead?
                if ($parties[$target_party_index]->is_dead())
                    return true;
                //There is at least one group still alive.
                //Randomly pick one.
                $list = array();
                foreach (array_keys($parties[$target_party_index]->groups) as $index)
                    if (!$parties[$target_party_index]->groups[$index]->is_dead())
                        $list[] = $index;
                $target_group_index = $list[array_rand($list)];
                $this->target = array($target_party_index, $target_group_index, $target_char_index);
                return false;
                //It is a ranged attack.
            default:
                //Verify the party exists.
                if (array_key_exists($target_party_index, $parties) == false)
                    return true;
                //Verify the group exists.
                if (array_key_exists($target_group_index, $parties[$target_party_index]->groups) == false)
                    return true;
                //See if our range is dead.
                $dead = true;
                for ($index = -$range; $index <= $range; $index++)
                    if (isset($parties[$target_party_index]->groups[$target_group_index]->characters[$index + $target_char_index]))
                        $dead = ($dead && $parties[$target_party_index]->groups[$target_group_index]->characters[$index + $target_char_index]->is_dead());
                if ($dead == false)
                    return false;
                //OK, our range is dead.  Is there anyone else in the group?
                //If not, then we can't act.
                if ($parties[$target_party_index]->groups[$target_group_index]->is_dead())
                    return true;
                //Find a random target.
                $list = array();
                foreach (array_keys($parties[$target_party_index]->groups[$target_group_index]->characters) as $index)
                    if (!$parties[$target_party_index]->groups[$target_group_index]->characters[$index]->is_dead())
                        $list[] = $index;
                $target_char_index = $list[array_rand($list)];
                $this->target = array($target_party_index, $target_group_index, $target_char_index);
                return false;
        }
        return true;
    }

    function perform_action(ActionScope $scope, array &$action_list): void {
        $fighter_target = ActionTarget::for_character($scope, $this);
        $fighter_array = $fighter_target->to_array();
        $may_affect = true;
        $effect_spec = null;

        $action_list[] = ['Turn', $fighter_array];

        //Validate that the fighter can perform these actions
        if ($this->validate_action($scope) == false)
            return;

        switch ($this->command) {
            case 0: //Left hand attack
            case 1: //Right hand attack
                $side_word = $this->command == 0 ? 'left' : 'right';
                $hand = "{$side_word[0]}hand";
                $effect_spec = $this->determine_effect_spec();
                $range = $effect_spec->targets;
                if ($this->validate_target($scope, $effect_spec))
                    return;
                $itemid = !is_null($this->equipment[$hand]) ? $this->inventory[$this->equipment[$hand]]['item'] : 0;
                // true: ammo not needed
                // false: out of ammo
                // int: ammo consumed from slot referenced.
                $ammo_used = $this->expend_ammo($this->command);
                if ($ammo_used !== false) {
                    $may_affect = true;
                    $action_list[] = ['Attack', [$itemid, $side_word, $range]];
                } else {
                    $may_affect = false;
                    $action_list[] = ['NoAmmo', $fighter_array];
                }
                break;
            case 2: //Item use
                $itemid = $this->inventory[$this->using]['item'];
                $item = ITEM::get($itemid);
                $effect_spec = $this->determine_effect_spec();
                if ($this->validate_target($scope, $effect_spec))
                    return;
                $action_list[] = ['Item', $itemid];
                break;
            case 3: //Equip weapon
            case 8: //Equip weapon and ammo
                list($location, $ammo, $target_char_index) = $this->target;
                if ($this->using != -1) {
                    do {
                        $result = $this->equip_item($this->using, $location);
                        if ($result->outcome === EquipOutcome::SUCCESS) {
                            break;
                        }
                        if ($result->outcome === EquipOutcome::NEEDS_UNEQUIPPING) {
                            $unequipped = $this->unequip_item($result->occupying_index);
                            if ($unequipped->outcome === EquipOutcome::NOT_EQUIPABLE) {
                                log_error("There was a severe error: Could not unequip item in slot {$result->occupying_index}.");
                                return;
                            }
                            foreach ($unequipped->equipped_slots as $slot)
                                $action_list[] = ['UnequipSlot', [$slot]];
                            continue;
                        }
                        // NOT_FOUND, NOT_EQUIPABLE, or INCOMPATIBLE_AMMO --
                        // none of these are expected when equipping a
                        // weapon (validate_action() already confirmed the
                        // item exists); fail loudly rather than proceed.
                        log_error("There was a severe error: Could not equip item in slot {$this->using} to location $location ({$result->outcome->name}).");
                        return;
                    } while (true);
                    $equipped = $result->equipped_slots;
                    foreach ($equipped as $slot)
                        $action_list[] = ['EquipSlot', [$this->using, $slot]];
                    $eitem = $this->inventory[$this->using]['item'];
                    if ($this->command == 8) {
                        if (!in_array('hand', ITEM::get($eitem)->equip_type))
                            $location = 0;
                        $result = $this->equip_item($ammo, $location);
                        if ($result->outcome !== EquipOutcome::SUCCESS) {
                            log_error("There was a severe error: Could not equip item in slot $ammo to location $location ({$result->outcome->name}).");
                            return;
                        }
                        foreach ($result->equipped_slots as $slot)
                            $action_list[] = ['EquipSlot', [$ammo, $slot]];
                        $action_list[] = ['Equip', [$eitem, $ammo]];
                    } else {
                        $action_list[] = ['Equip', [$eitem]];
                    }
                } else {
                    $eitem = $this->inventory[$location]['item'];
                    $unequipped = $this->unequip_item($location);
                    if ($unequipped->outcome === EquipOutcome::NOT_FOUND) {
                        log_error("There was a severe error: Tried to unequip non-existent inventory item $location.");
                        return;
                    }
                    if ($unequipped->outcome === EquipOutcome::NOT_EQUIPABLE) {
                        log_error("There was a severe error: Tried to unequip non-equippable item $location.");
                        return;
                    }
                    $action_list[] = ['Unequip', [ITEM::get($eitem)->name]];
                    foreach ($unequipped->equipped_slots as $slot)
                        $action_list[] = ['UnequipSlot', [$slot]];
                }
                return;
            case 4: //Skill use
            case 5: //Spell use
                $abilityid = $this->abilities[$this->using];
                $ability = ABILITY::get($abilityid);
                $effect_spec = $this->determine_effect_spec();
                if ($this->validate_target($scope, $effect_spec))
                    return;
                $action_list[] = [$this->command == 4 ? 'Skill' : 'Spell', $abilityid];
                break;

            case 6: //Defend
                $action_list[] = ['Defend', []];
                return;

            case 7: //Run
                $action_list[] = ['Run', []];
                return;

            case 9: //Give item
                $inventory_slot = $this->inventory[$this->using];
                $itemid = $inventory_slot['item'];
                $qty = $inventory_slot['qty'];
                $recipient_target = new ActionTarget(...$this->target);
                $recipient = $recipient_target->get_character($scope);
                $given = $qty - $recipient->add_item($itemid, $qty);
                if ($qty != $given)
                    $this->add_item($itemid, $qty - $given);
                $this->remove_item($this->using, $given);
                $action_list[] = ['Give', [$itemid]];
                $action_list[] = ['Receive', [$given, ...$this->target]];
                $action_list[] = ['GiveItem', [$itemid, $given]];
                $action_list[] = ['Target', [...$this->target, EffectSpec::TARGET_INDIVIDUAL]];
                return;
        }

        if (!$may_affect)
            return;

        $recipient_target = new ActionTarget(...$this->target);
        $action_list[] = ['Target', [...$this->target, $effect_spec->targets]];

        if ($this->command == 4 || $this->command == 5) {
            if ($this->get_current('MP') < $ability->mp_used) {
                $may_affect = false;
                $action_list[] = ['NoMP', $fighter_array];
            } else {
                $action_list[] = ['AlterStat', [...$fighter_array, 'MP', -$ability->mp_used]];
                $this->current['MP'] -= $ability->mp_used;
            }
        }

        if ($may_affect) {
            $recipient_target->apply($scope, $this, $effect_spec, $action_list);
            # Deplete one-use item if needed
            if ($this->command == 2 && $item->one_use) {
                $this->remove_item($this->using, 1);
                $action_list[] = ['UseItem', [$this->using]];
            }
            if (in_array($this->command, [0, 1]) && $ammo_used !== true && $ammo_used !== false) {
                // Ammo was used. Now remove it.
                $this->remove_item($ammo_used, 1);
                $action_list[] = ['ExpendAmmo', [$ammo_used]];
            }
        }
    }

    /**
     * Rolls to determine whether an attack, skill, or spell hits,
     * given the attacker's accuracy and the defender's dodge. Both
     * are floored to a minimum of 1 -- this is what fixed the
     * DivisionByZeroError that used to occur in the melee damage
     * formula whenever accuracy was exactly 1, and applying the same
     * floor here keeps hit-chance consistent across every attack type
     * that shares this roll, not just melee.
     *
     * Returns null for a miss, or the roll value (1..accuracy) for a
     * hit -- callers that need to distinguish a critical (roll===1)
     * or scale damage by roll quality use the returned value
     * directly, e.g. in calculate_hit_damage() below.
     */
    public static function roll_to_hit(int $accuracy, int $dodge): ?int {
        $acc = max($accuracy, 1);
        $dod = max($dodge, 1);
        $roll = mt_rand(1, $acc + $dod);
        return $roll > $acc ? null : $roll;
    }

    /**
     * Pure damage calculation for a melee hit that's already been
     * confirmed (not a miss) -- given the attacker's strength, the
     * defender's block, the accuracy the hit was rolled against, the
     * roll itself, whether the defender is currently defending, and
     * the intensity scaling percentage for this target. No
     * randomness and no state mutation, so it's directly testable
     * with fixed inputs rather than needing to sample many runs.
     *
     * Strength doubles on a critical (roll===1). The ratio between
     * strength and block scales damage between 0 and 1 based on
     * their relative size; roll quality separately scales the result
     * between 50% (barely hit) and 100% (dead-on). Damage is halved
     * if the defender is defending, then floored to a minimum of 1.
     * 
     * TODO: Factor attributes in when those get implemented. For this,
     * damage can drop to 0.
     */
    public static function calculate_hit_damage(
        int $strength,
        int $block,
        int $accuracy,
        int $roll,
        bool $is_defending,
        float $impact_percentage
    ): int {
        $acc = max($accuracy, 1);
        $str = max($strength, 1);
        if ($roll === 1) {
            $str *= 2;
        }
        $blo = max($block, 1);
        $ratio = atan2($str, $blo) * 2.0 / M_PI;
        $dmg = (int)floor($str * $ratio * $impact_percentage * (0.5 + (($acc + 1 - $roll) / $acc) * 0.5));
        if ($is_defending) {
            $dmg = (int)floor($dmg / 2);
        }
        if ($dmg < 1) {
            $dmg = 1;
        }
        return $dmg;
    }

    /**
     * Rolls a saving throw against a detrimental spell, given the
     * caster's Power and the target's Resistance. Returns the
     * resulting immunity/effect-scaling factor directly: 0.0 for a
     * full save (the spell has no effect at all), 0.5 for a half
     * save, or 1.0 for no save (full effect). Callers check for 0.0
     * to decide whether to skip the effect entirely -- this function
     * only rolls and scores the throw, it doesn't decide what a save
     * means for the caller's control flow.
     */
    public static function roll_saving_throw(int $power, int $resistance): float {
        $roll = mt_rand(1, $power + $resistance);
        if ($roll > $power + $resistance / 2) {
            return 0.0;
        } elseif ($roll > $power) {
            return 0.5;
        } else {
            return 1.0;
        }
    }

    function affected_by(CHARACTER $fighter, ActionTarget $fighter_target, ActionTarget $target_target, int $intensity, int $divisor, array &$action_list): void {
        $impact_percentage = ($divisor + 1 - $intensity) / ($divisor + 1.0);
        $item = -1;
        switch ($fighter->command) {
            case 0: //Left hand attack
            case 1: //Right hand attack
                if ($this->get_current('HP') == 0)
                    break;
                //See if we are hit.
                # Do not randomize- the roll is the randomizer
                $acc = $fighter->get_current('Accuracy', $fighter->command);
                $dod = $this->get_current('Dodge');
                $roll = self::roll_to_hit($acc, $dod);
                if ($roll === null) {
                    $action_list[] = array('Miss', $target_target->to_array());
                    break;
                }

                //See how much we are hit for.
                $str = $fighter->get_current('Strength', $fighter->command);
                $blo = $this->get_current('Block');
                $dmg = self::calculate_hit_damage($str, $blo, $acc, $roll, $this->command == 6, $impact_percentage);

                //Inflict damage
                $this->inflict_damage($fighter_target, $target_target, $dmg, ($roll == 1 ? true : false), $action_list);
                break;
            case 2: //Use item
                $item = ITEM::get($fighter->inventory[$fighter->using]['item']);
                $item_effect_spec = $item->use_effect_spec;
                do_effect(
                    $item_effect_spec,
                    $fighter_target,
                    $this,
                    $target_target,
                    $impact_percentage,
                    1,
                    $action_list
                );
                break;
            case 4: //Use skill
                //Skills can be evaded.
                $ability = ABILITY::get($fighter->abilities[$fighter->using]);
                if ($ability->is_detrimental()) {
                    if ($this->get_current('HP') == 0)
                        break;
                    //See if we are hit.
                    $acc = $fighter->get_current('Accuracy');
                    $dod = $this->get_current('Dodge');
                    $roll = self::roll_to_hit($acc, $dod);
                    if ($roll === null) {
                        $action_list[] = array('Miss', $target_target->to_array());
                        break;
                    }
                }

                //We are hit or effect is good, do effect.
                do_effect(
                    $ability->effect_spec,
                    $fighter_target,
                    $this,
                    $target_target,
                    $impact_percentage,
                    1,
                    $action_list
                );
                break;
            case 5: //Cast spell
                //Effects are adjusted by resistance.
                $ability = ABILITY::get($fighter->abilities[$fighter->using]);
                $immunity = 1;
                if ($ability->is_detrimental()) {
                    if ($this->get_current('HP') == 0)
                        break;
                    //See if we make the saving throw.
                    $pow = $fighter->get_current('Power');
                    $res = $this->get_current('Resistance');
                    $immunity = self::roll_saving_throw($pow, $res);
                    if ($immunity == 0) {
                        $action_list[] = array('NoEffect', $target_target->to_array());
                        break;
                    }
                }

                do_effect(
                    $ability->effect_spec,
                    $fighter_target,
                    $this,
                    $target_target,
                    $impact_percentage,
                    $immunity,
                    $action_list
                );
                break;
            case 3: //Change equipment
            case 6: //Defend
            case 7: //Run
                break; //Do not do anything.
        }
    }

    function inflict_damage(ActionTarget $fighter_target, ActionTarget $target_target, int $dmg, $critical_hit, &$action_list) {
        //Capture before for xp calculation
        $before = $this->current['HP'];

        //If this dmg will make HP go over max, then scale down dmg.
        if ($this->get_current('HP') - $dmg > $this->get_base('HP'))
            $dmg = $this->get_current('HP') - $this->get_base('HP');

        //Display damage.
        if ($dmg >= 0) {
            $action_list[] = array('Damage', array(...$target_target->to_array(), $dmg, $critical_hit));
        } else {
            $action_list[] = array('Restore', array(...$target_target->to_array(), 'HP', -$dmg));
        }

        //Inflict damage on this target.
        $this->current['HP'] -= $dmg;
        //If the target died, then display death.
        if ($this->current['HP'] <= 0) {
            // The last field is the overkill: damage past what it took.
            $action_list[] = array('Died', array(...$target_target->to_array(), $dmg, -$this->current['HP']));
            //Adjust dmg if it was excessive.
            $dmg += $this->current['HP'];
            //Set hp to 0.
            $this->current['HP'] = 0;
        }
        //Add to the xp tree
        $this->add_debt_to_xp_tree($fighter_target, $target_target, $before, $this->current['HP'], $this->get_base('HP'), $action_list);
    }

    function restore_HP(ActionTarget $fighter_target, ActionTarget $target_target, $amt, &$action_list) {
        //Capture before for xp calculation
        $before = $this->current['HP'];

        //If this dmg will make HP go over max, then scale down dmg.
        if ($this->get_current('HP') + $amt > $this->get_base('HP'))
            $amt = $this->get_base('HP') - $this->get_current('HP');

        //Inflict damage on this target.
        $this->current['HP'] += $amt;

        //Display damage.
        if ($amt < 0) {
            $action_list[] = array('Damage', array(...$target_target->to_array(), -$amt, false));
            //If the target died, then display death.
            if ($this->current['HP'] <= 0) {
                $overkill = -$this->current['HP'];
                $this->current['HP'] = 0;
                //Denote death of character (the last field is the overkill).
                $action_list[] = array('Died', array(...$target_target->to_array(), -$amt, $overkill));
            }
        } else {
            //If the target revived, then display revive.
            if ($this->current['HP'] > 0 && $before == 0) {
                //Denote revival of character.
                $action_list[] = array('Revived', array(...$target_target->to_array()));
            }
            $action_list[] = array('Restore', array(...$target_target->to_array(), 'HP', $amt));
        }


        //Add to the xp tree
        $this->add_debt_to_xp_tree($fighter_target, $target_target, $before, $this->current['HP'], $this->get_base('HP'), $action_list);
    }

    function restore_MP(ActionTarget $fighter_target, ActionTarget $target_target, $amt, &$action_list) {
        //Capture before for xp calculation
        $before = $this->current['MP'];

        //If this amt will make MP go over max, then scale down amt.
        if ($this->get_current('MP') + $amt > $this->get_base('MP'))
            $amt = $this->get_base('MP') - $this->get_current('MP');

        //restore_MP() should only ever restore. A negative $amt here
        //means either the caller passed one directly, or current MP
        //was already above base MP before this call (making the cap
        //above produce a negative value even from a positive input) --
        //either way, that's a bug upstream of this method, not
        //something to silently apply as damage.
        if ($amt < 0) {
            log_error("restore_MP called with a negative effective amount ($amt) for {$this->name}.");
            throw new Exception("restore_MP called with a negative effective amount ($amt) for {$this->name}.");
        }

        //Display damage.
        $action_list[] = array('Restore', array(...$target_target->to_array(), 'MP', $amt));

        //Inflict damage on this target.
        $this->current['MP'] += $amt;

        //Add to the xp tree
        $this->add_debt_to_xp_tree($fighter_target, $target_target, $before, $this->current['MP'], $this->get_base('MP'), $action_list);
    }

    function is_dead(): bool {
        $retval = ($this->current['HP'] < 1);
        return $retval;
    }

    function get_perceived_stat(&$target, $statname) {
        $stat = $target->get_current($statname);
        $variance = $this->ai_experience;
        return $stat * 100 / mt_rand(10000 - $variance * 100, 10000 + $variance * 100);
    }

    // Records the debt in-memory (for this round's own XP
    // distribution, if this fight ends this round) and appends an
    // XpDebt message to $action_list, the same way Damage/Restore
    // already do -- this is how xp_debts survives a FIGHT
    // reconstruction: process_fight_action()'s own XpDebt case
    // replays this exact entry onto $target_target during replay,
    // the same way its Damage case replays HP loss. $target_target is
    // this character's own position (whose xp_debts this entry
    // belongs to) -- the caller already has it on hand as the target
    // of the effect being applied.
    function add_debt_to_xp_tree(
        ActionTarget $fighter_target,
        ActionTarget $target_target,
        int $before,
        int $after,
        int $gauge,
        array &$action_list
    ): void {
        $debt = array($fighter_target->to_array(), ($before - $after) / $gauge, $this->pxp);
        $this->xp_debts[] = $debt;
        $action_list[] = array('XpDebt', array(...$target_target->to_array(), ...$debt));
    }

    function get_weapon_effect_spec(int $command): EffectSpec {
        $side = $command == 0 ? 0 : 1;
        $weapon = $this->get_equipped_weapon($side);
        $ammo = $this->get_equipped_ammo($side);
        $str = $this->get_current('Strength', $command);
        return (!is_null($ammo) ? $ammo : $weapon)->get_weapon_effect_spec($str);
    }

    /**
     * The EffectSpec that would govern this character's current
     * command's target, if any -- for use by validate_target() and
     * anywhere else that needs to know an action's target rules
     * before actually performing it. Returns null for commands that
     * don't go through EffectSpec-based targeting at all (change
     * equipment, defend, run, give item), matching validate_target()
     * and perform_action()'s own existing per-command behavior
     * exactly -- not a new, stricter rule.
     */
    function determine_effect_spec(): ?EffectSpec {
        switch ($this->command) {
            case 0: //Left hand attack
            case 1: //Right hand attack
                return $this->get_weapon_effect_spec($this->command);
            case 2: //Use item
                return ITEM::get($this->inventory[$this->using]['item'])->use_effect_spec;
            case 4: //Use skill
            case 5: //Cast spell
                return ABILITY::get($this->abilities[$this->using])->effect_spec;
            default:
                return null;
        }
    }

    public function scrub_inactive_objects(): bool {
        $changed = false;

        $active_abilities = ABILITY::filter_active($this->abilities);
        if (count($active_abilities) !== count($this->abilities)) {
            $this->abilities = array_values($active_abilities);
            $changed = true;
        }
        return $changed;
    }
    private function apply_slot_mapping(array $old_to_new): void {
        $new_inventory = [];
        foreach ($this->inventory as $old_slot => $inventory_item) {
            $new_inventory[$old_to_new[$old_slot]] = $inventory_item;
        }
        $this->inventory = $new_inventory;

        foreach ($this->equipment as $slot_name => $item_slot) {
            if ($item_slot !== null) {
                $this->equipment[$slot_name] = $old_to_new[$item_slot] ?? null;
            }
        }
    }

    private function scrub_inventory(): void {
        $mapping = [];
        $new_slot = 0;
        foreach (array_keys($this->inventory) as $old_slot) {
            $mapping[$old_slot] = $new_slot;
            $new_slot++;
        }
        $this->apply_slot_mapping($mapping);
    }

    public function save(Database $db): void {
        HERO::save_character($db, $this);
    }
}