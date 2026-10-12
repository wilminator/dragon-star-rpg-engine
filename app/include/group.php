<?php

/**
 * group.php
 * defines groups and creates group functionality
 * @version 0.1.0
 * @copyright 2003 Mike Wilmes
 **/

require_once INCLUDE_GAME . 'constants.php';
require_once INCLUDE_GAME . 'character.php';

class GROUP implements Countable, Iterator, ArrayAccess, DehydrateInterface {
    use DehydrateTrait;

    const int MAX_COUNT = 5;

    var $characters = array();
    var $name = '';

    function add_hero(CHARACTER $character, ?int $position = null): bool {
        if ($this->count() < GROUP::MAX_COUNT) {
            if (is_null($position))
                array_push($this->characters, $character);
            else
                array_splice($this->characters, $position, 0, array($character));
            #Check to see if a whole team is here lead by a member(?)
            #If so, rename the group to the team name.  add "& Co." if
            #more than just team in group.
            $this->name = $this->characters[0]->name . "'s group";;
            return false;
        }
        return true;
    }

    function add_monster(CHARACTER $character): bool {
        array_push($this->characters, $character);
        return false;
    }

    function remove_fighter(int $index): bool {
        if ($index < $this->count()) {
            array_splice($this->characters, $index, 1);
            $this->characters = array_merge($this->characters);
            return false;
        }
        return true;
    }

    function count(): int {
        return count($this->characters);
    }

    function rewind() : void {
        reset($this->characters);
    }

    function valid() : bool {
        return key($this->characters) !== null;
    }

    function next() : void {
        next($this->characters);
    }

    function current() : mixed {
        return current($this->characters);
    }

    function key() : mixed {
        return key($this->characters);
    }

    function to_js(): string|false {
        return json_encode(get_object_vars($this));
    }

    const DEHYDRATE_VERSION = 1;
    private const DEHYDRATE_PROPERTIES = ['name'];

    protected function dehydrate_nested(): array {
        return ['characters' => array_map(fn($character) => $character->dehydrate(), $this->characters)];
    }

    protected static function hydrate_nested(self $object, array $data): void {
        $object->characters = array_map(fn($d) => CHARACTER::hydrate($d), $data['characters']);
    }

    function get_pxp(): int {
        $pxp = 0;
        foreach (array_keys($this->characters) as $character)
            $pxp += $this->characters[$character]->pxp;
        return $pxp;
    }

    function is_dead(): bool {
        $sum = true;
        foreach ($this->characters as $index => $character)
            $sum = ($sum && $character->is_dead());
        return $sum;
    }

    function count_living(): int {
        $sum = 0;
        foreach ($this->characters as $index => $character)
            if ($character->current['HP'] > 0)
                $sum++;
        return $sum;
    }

    function get_character_list(?bool $living): array {
        $retval = array();
        foreach (array_keys($this->characters) as $index) {
            $result = !$this->characters[$index]->is_dead();
            if ($living === $result || is_null($living)) {
                $retval[] = "$index";
            }
        }
        return $retval;
    }

    function get_character_key(CHARACTER $character) : ?string {
        foreach (array_keys($this->characters) as $index)
            if ($this->characters[$index] === $character)
                return make_index_key($index);
        return null;
    }

    function get_character_position(CHARACTER $character) : ?array {
        foreach (array_values($this->characters) as $index=>$char)
            if ($char === $character)
                return [$index];
        return null;
    }

    function get_character_position_by_id(int $charid) : ?array {
        foreach (array_values($this->characters) as $index=>$char)
            if ($char->charid === $charid){
                return [$index];
            }
        return null;
    }

    function get_character(int $key): CHARACTER {
        if (!isset($this->characters[$key])) {
            log_error("Group->character: Invalid index");
            throw new Exception("Group->character: Invalid index");
        }
        return $this->characters[$key];
    }

    function find_character(int $charid): int|bool {
        foreach (array_keys($this->characters) as $index)
            if ($this->characters[$index]->charid == $charid)
                return $index;
        return true;
    }

    public function offsetExists(mixed $offset): bool {
        return array_key_exists($offset, $this->characters);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->get_character($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        throw new Exception("Group->offsetSet: Invalid operation. Use Group->add_*() methods to add a character.");
    }

    public function offsetUnset(mixed $offset): void {
        $this->remove_fighter($offset);
    }
}