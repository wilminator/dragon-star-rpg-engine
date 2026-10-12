<?php
require_once INCLUDE_GAME . 'effect_spec.php';
require_once INCLUDE_GAME . 'errorlog.php';

enum ScopeType {
    case COMBAT;
    case FIELD;
}

interface ActionScope {
    /** @return PARTY[] */
    public function get_parties(): array;
    public function get_scope_type(): ScopeType;
}

class ActionTarget {
    public function __construct(
        public readonly int $party_index,
        public readonly ?int $group_index = null,
        public readonly ?int $char_index = null,
    ) {
    }

    public static function for_character(ActionScope $scope, CHARACTER $character): self {
        foreach ($scope->get_parties() as $party_index => $party)
            foreach ($party->groups as $group_index => $group)
                foreach ($group->characters as $char_index => $candidate)
                    if ($candidate === $character)
                        return new self($party_index, $group_index, $char_index);
        return new self(-1, -1, -1);
    }

    /**
     * Yields [ActionTarget $recipient_target, CHARACTER $recipient, int $intensity, int $divisor]
     * for every character this effect actually reaches, given $effect_spec->targets.
     * $intensity/$divisor match affected_by()'s existing falloff-ratio convention:
     * (0, 0) for an unscaled hit, or (offset, range) for a ranged attack, where offset
     * is how far this recipient sits from the original center.
     */
    public function expand(ActionScope $scope, EffectSpec $effect_spec): iterable {
        $parties = $scope->get_parties();
        if (!array_key_exists($this->party_index, $parties) && $effect_spec->targets !== EffectSpec::TARGET_ALL_PARTIES)
            return;

        switch ($effect_spec->targets) {
            case EffectSpec::TARGET_ALL_PARTIES:
                foreach ($parties as $party_index => $party)
                    yield from (new self($party_index))->expand_within_party($party, $effect_spec);
                return;

            case EffectSpec::TARGET_PARTY:
                yield from $this->expand_within_party($parties[$this->party_index], $effect_spec);
                return;

            case EffectSpec::TARGET_GROUP:
                $party = $parties[$this->party_index];
                if (!array_key_exists($this->group_index, $party->groups)) return;
                yield from (new self($this->party_index, $this->group_index))
                    ->expand_within_group($party->groups[$this->group_index], $effect_spec);
                return;

            default:
                // A genuine positive range, or TARGET_INDIVIDUAL (0) -- the
                // range loop below naturally yields just the center when the
                // range is 0, so no separate case is needed for TARGET_INDIVIDUAL.
                $party = $parties[$this->party_index];
                if (!array_key_exists($this->group_index, $party->groups)) return;
                $group = $party->groups[$this->group_index];
                if (!array_key_exists($this->char_index, $group->characters)) return;
                yield from $this->expand_ranged($group, max(0, $effect_spec->targets), $effect_spec);
                return;
        }
    }

    private function expand_within_party(PARTY $party, EffectSpec $effect_spec): iterable {
        foreach ($party->groups as $group_index => $group)
            yield from (new self($this->party_index, $group_index))->expand_within_group($group, $effect_spec);
    }

    private function expand_within_group(GROUP $group, EffectSpec $effect_spec): iterable {
        foreach (array_keys($group->characters) as $char_index) {
            $character = $group->characters[$char_index];
            if ($this->should_skip($character, $effect_spec)) continue;
            yield [new self($this->party_index, $this->group_index, $char_index), $character, 0, 0];
        }
    }

    private function expand_ranged(GROUP $group, int $range, EffectSpec $effect_spec): iterable {
        for ($offset = 0; $offset <= $range; $offset++) {
            if ($offset > 0 && $this->char_index - $offset >= 0) {
                $char_index = $this->char_index - $offset;
                $character = $group->characters[$char_index] ?? null;
                if ($character !== null && !$this->should_skip($character, $effect_spec))
                    yield [new self($this->party_index, $this->group_index, $char_index), $character, $offset, $range];
            }
            if ($this->char_index + $offset < count($group->characters)) {
                $char_index = $this->char_index + $offset;
                $character = $group->characters[$char_index];
                if (!$this->should_skip($character, $effect_spec))
                    yield [new self($this->party_index, $this->group_index, $char_index), $character, $offset, $range];
            }
        }
    }

    private function should_skip(CHARACTER $character, EffectSpec $effect_spec): bool {
        return $effect_spec->effect->only_living_targets() && $character->is_dead();
    }

    /**
     * Replaces the old callback indirection (fight_do_affect/field_do_affect ->
     * perform_action -> $target->affected_by(...)) -- perform_action() was a pure
     * pass-through with no logic of its own, so this calls affected_by() directly.
     */
    public function apply(ActionScope $scope, CHARACTER $fighter, EffectSpec $effect_spec, array &$action_list): void {
        $fighter_target = self::for_character($scope, $fighter);
        foreach ($this->expand($scope, $effect_spec) as [$recipient_target, $recipient, $intensity, $divisor]) {
            $recipient->affected_by($fighter, $fighter_target, $recipient_target, $intensity, $divisor, $action_list);
        }
    }

    public function to_key(): string {
        $parts = array_filter(
            [$this->party_index, $this->group_index, $this->char_index],
            fn($x) => $x !== null
        );
        return make_index_key(...$parts);
    }

    public function to_array(): array {
        return [$this->party_index, $this->group_index, $this->char_index];
    }

    public static function from_key(string $key): self {
        [$party_index, $group_index, $char_index] = array_pad(split_index_key($key), 3, null);
        return new self($party_index, $group_index, $char_index);
    }

    public function get_character(ActionScope $scope): CHARACTER {
        $group = $this->get_group($scope);
        if (array_key_exists($this->char_index, $group->characters)) {
            return $group->characters[$this->char_index];
        }
        throw new Exception("There was no character with index {$this->char_index} in party {$this->party_index}, group {$this->group_index}.");
    }

    public function get_group(ActionScope $scope): GROUP {
        $party = $this->get_party($scope);
        if (array_key_exists($this->group_index, $party->groups)) {
            return $party->groups[$this->group_index];
        }
        throw new Exception("There was no group with index {$this->group_index} in party {$this->party_index}.");
    }

    public function get_party(ActionScope $scope): PARTY {
        $parties = $scope->get_parties();
        if (array_key_exists($this->party_index, $parties)) {
            return $parties[$this->party_index];
        }
        throw new Exception("There was no party with index {$this->party_index}.");
    }
}