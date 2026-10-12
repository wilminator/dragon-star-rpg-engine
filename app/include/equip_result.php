<?php

/**
 * equip_result.php
 * The explicit outcome of CHARACTER::equip_item(), replacing its
 * previous four-type (bool|int|array) return contract -- which
 * actually encoded five distinct outcomes across those four PHP
 * types, with no way for a caller to know they'd handled every case.
 * A match() on EquipOutcome throws UnhandledMatchError if a case is
 * missed, catching that class of bug at the call site instead of
 * needing empirical testing to surface it.
 *
 * @version 0.2.0
 **/

enum EquipOutcome {
    case NOT_FOUND;          // was: true -- $index isn't a real inventory item
    case NOT_EQUIPABLE;      // was: false -- item has no equip_type at all
    case NEEDS_UNEQUIPPING;  // was: int -- either this same item is equipped
                              // elsewhere, or a different item occupies the
                              // target slot; either way, unequip occupying_index
                              // and retry
    case INCOMPATIBLE_AMMO;  // was: true -- ammo-only item, no compatible weapon equipped
    case SUCCESS;            // was: array -- the slots that got equipped
}

final class EquipResult {
    private function __construct(
        public readonly EquipOutcome $outcome,
        public readonly ?int $occupying_index = null,
        public readonly ?array $equipped_slots = null,
    ) {
    }

    public static function not_found(): self {
        return new self(EquipOutcome::NOT_FOUND);
    }

    public static function not_equipable(): self {
        return new self(EquipOutcome::NOT_EQUIPABLE);
    }

    public static function needs_unequipping(int $occupying_index): self {
        return new self(EquipOutcome::NEEDS_UNEQUIPPING, occupying_index: $occupying_index);
    }

    public static function incompatible_ammo(): self {
        return new self(EquipOutcome::INCOMPATIBLE_AMMO);
    }

    public static function success(array $equipped_slots): self {
        return new self(EquipOutcome::SUCCESS, equipped_slots: $equipped_slots);
    }
}
