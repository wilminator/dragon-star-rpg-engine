<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'RESET_DB', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

//Check for admin permissions
$reset_db = check_permission($userid, 'dse', 'RESET_DB');
if ($reset_db === false) {
    header("Location: ../");
    exit;
}

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'fight_simulator.php';

$db = get_database();
ensure_all_databases_exist($db);

// SYSTEM_TEAMID: the fixed teamid every simulated fight is tagged
// under -- never a real team's id (real teamids start from 1), so
// bulk-deleting everything tagged with it (see
// delete_all_fights_owned_by() below) can never touch a real team's
// own fights. Matches the original spec ("track these in the
// fight_saves table under playerid 0"), since renamed from playerid
// to teamid -- see FightSave's own docblock for why.
const SYSTEM_TEAMID = 0;

// Generating hundreds or thousands of full fight simulations in one
// request is exactly the kind of long-running, admin-only,
// intentionally-triggered operation PHP's normal request time limit
// isn't meant for -- unlimited here, not for any other page.
set_time_limit(0);

$result = null;
if (isset($_POST['generate'])) {
    $requested_count = max(1, (int) $_POST['count']);
    // A generated matchup can stalemate -- confirmed directly earlier
    // this session, some random stat combinations never produce
    // enough damage to finish a fight within a reasonable number of
    // rounds. A stalemated fight makes a poor "watch the replay"
    // example, so it's discarded and retried with a fresh random
    // matchup rather than counted toward $requested_count -- capped at
    // a generous multiple of the request so a batch that's somehow
    // unluckier than usual still terminates rather than looping
    // indefinitely.
    $max_attempts = $requested_count * 5;

    $deleted_count = FightCommunicator::delete_all_fights_owned_by($db, SYSTEM_TEAMID);

    $generated_count = 0;
    $stalemate_count = 0;
    $attempts = 0;
    while ($generated_count < $requested_count && $attempts < $max_attempts) {
        $attempts++;
        $generated = FightSimulator::random_fight();
        $outcome = FightSimulator::simulate_to_completion($db, $generated['fight'], $generated['players'], max_rounds: 50);

        if (!$outcome['resolved'] || $outcome['winning_party_index'] === null) {
            // Stalemate (hit the round cap) or a same-round mutual
            // wipe (no single winning party to compute a ratio
            // against) -- neither makes a good saved example. Delete
            // it outright rather than leave an orphaned, untagged
            // fight behind.
            FightCommunicator::delete_fight($db, $outcome['fightid']);
            $stalemate_count++;
            continue;
        }

        $power_ratio = FightSave::compute_power_ratio($outcome['fight'], $outcome['winning_party_index']);
        (new FightSave($outcome['fightid'], SYSTEM_TEAMID, 'user', $power_ratio))->save($db);
        $generated_count++;
    }

    $result = [
        'deleted' => $deleted_count,
        'generated' => $generated_count,
        'stalemates' => $stalemate_count,
        'requested' => $requested_count,
        'hit_attempt_cap' => $attempts >= $max_attempts && $generated_count < $requested_count,
    ];
}
?>
<html>
<head>
    <title>Regenerate Simulated Fights</title>
</head>
<body>
    <h1>Regenerate Simulated Fights</h1>
    <p>Deletes every fight currently tagged as system-generated (teamid <?php echo SYSTEM_TEAMID; ?>, any tag), then generates a fresh batch of randomly simulated fights in their place -- random parties (names, jobs, levels), a mix of PvP and PvPvE, each played to completion and tagged 'user'.</p>

    <?php if ($result !== null): ?>
        <p style="color:<?php echo $result['hit_attempt_cap'] ? 'red' : 'green'; ?>">
            Deleted <?php echo $result['deleted']; ?> prior fight(s).
            Generated <?php echo $result['generated']; ?> of <?php echo $result['requested']; ?> requested
            (<?php echo $result['stalemates']; ?> discarded as stalemates or mutual wipes).
            <?php if ($result['hit_attempt_cap']): ?>
                Stopped early: too many stalemates in a row to reach the requested count.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post">
        <label for="count">Number of fights to generate:</label>
        <input type="number" id="count" name="count" value="1000" min="1" max="100000">
        <input type="submit" name="generate" value="Delete existing and regenerate">
    </form>

    <p><a href="./">Return to the admin menu.</a></p>
</body>
</html>
