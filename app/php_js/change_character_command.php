<?php
require_once INCLUDE_GAME.'paths.php';
require_once INCLUDE_GAME.'fight_communicator.php';

function change_character_command($party,$group,$character,$action,$current_sequence)
    {
    // $current_sequence is no longer used: the legacy path's
    // FIGHT_STORE::update_fight_action() needed it to guard against
    // overwriting a newer round's fight_data with a stale snapshot,
    // but PendingAction doesn't carry a sequence at all (by design --
    // it's always just "this character's current intended command,"
    // never tied to a specific round) and there's no whole-fight
    // snapshot being written here anymore for a stale one to corrupt.
    // Kept as a parameter since the caller still supplies it.
    $db = get_database();
    $fight_communicator = new FightCommunicator($db, $GLOBALS['userid']);
    // Logs the "not part of an active fight" case itself; nothing
    // further needed here either way, matching this function's
    // existing behavior of never queuing a response of its own.
    $fight_communicator->submit_character_command($party,$group,$character,$action);
    }
?>
