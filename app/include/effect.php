<?php
enum Effect: int {
    case NONE = 0;
    case HEAL = 1;
    case HURT = 2;
    case REVIVE = 3;
    case SLAY = 4;
    case INCREASE_STATS = 5;
    case DECREASE_STATS = 6;
    case STEAL_STATS = 7;
    case CAUSE_GOOD_STATUS = 8;
    case REMOVE_GOOD_STATUS = 9;
    case CAUSE_BAD_STATUS = 10;
    case REMOVE_BAD_STATUS = 11;
    case RESTORE_MP = 12;


    public function usable_in_field(): bool {
        return in_array($this, [
            self::HEAL,
            self::REVIVE,
            self::REMOVE_BAD_STATUS,
            self::RESTORE_MP
        ]);
    }

    public function usable_in_combat(): bool {
        return in_array($this, [
            self::HEAL,
            self::HURT,
            self::REVIVE,
            self::SLAY,
            self::INCREASE_STATS,
            self::DECREASE_STATS,
            self::STEAL_STATS,
            self::CAUSE_GOOD_STATUS,
            self::REMOVE_GOOD_STATUS,
            self::CAUSE_BAD_STATUS,
            self::REMOVE_BAD_STATUS,
            self::RESTORE_MP
        ]);
    }

    function is_detrimental() {
        return in_array($this, [
            self::HURT,  //Hurt
            self::SLAY,  //Slay
            self::DECREASE_STATS,  //Decrease Stats
            self::STEAL_STATS,  //Steal Stats
            self::REMOVE_GOOD_STATUS,  //Remove Good Status
            self::CAUSE_BAD_STATUS  //Cause Bad Status
        ]);
    }

    function only_living_targets() {
        return in_array($this, [
            self::HEAL,
            self::HURT,
            self::SLAY,
            self::INCREASE_STATS,
            self::DECREASE_STATS,
            self::STEAL_STATS,
            self::CAUSE_GOOD_STATUS,
            self::REMOVE_GOOD_STATUS,
            self::CAUSE_BAD_STATUS,
            self::REMOVE_BAD_STATUS,
            self::RESTORE_MP
        ]);
    }
}
