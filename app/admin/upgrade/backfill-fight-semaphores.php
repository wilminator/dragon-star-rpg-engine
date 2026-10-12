<?php
/**
 * Fabricates a FightSemaphore row for every fight that predates
 * FightSemaphore entirely (created before this session's work, via
 * the old FIGHT_STORE::init_fight() path, which never touched
 * fight_semaphore at all), then retroactively builds each
 * participating team's 'user' history for the ones that turn out to
 * be Resolved.
 *
 * Without this, such a fight is genuinely unreachable through the new
 * code: FightCommunicator::get_fight_view()/get_fight_status() both
 * require a FightSemaphore row to find a fightid at all, and
 * FightSave::save() (as of this session) refuses to tag any fight
 * that isn't Resolved -- which a fight with no semaphore row at all
 * can never be, since "Resolved" is a semaphore concept.
 *
 * The fabricated row isn't a guess: fights.sequence and fights.state
 * (the legacy columns FIGHT_STORE itself wrote and still relies on)
 * are read directly and carried over as-is, so a fight legitimately
 * still WaitingForInput or Processing under the old system is
 * fabricated in that same state, not silently marked Resolved just
 * because it's old. Only fights that come out Resolved -- either
 * already were, or already are by the time this runs -- get the
 * retroactive 'user' tagging: FightSave::save() would refuse the
 * others anyway, and tagging an in-progress fight's history before
 * it's actually finished doesn't mean anything.
 *
 * 'user' only, not 'retain'/'underdog'/'overkill': 'retain' is
 * player-initiated (nothing to backfill), and correctly attempting
 * 'underdog'/'overkill' retroactively would mean re-deriving which
 * party was "player_party 0" and whether an NPC party was even
 * present under semantics this migration has no reliable way to
 * reconstruct from legacy data alone -- left for a human to apply
 * later if particular fights genuinely qualify.
 *
 * Safe to re-run: only ever touches a fightid that still has no
 * FightSemaphore row, so a fight already backfilled (or a fight that
 * already had a real semaphore row from actual live play) is left
 * alone on the next run.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/backfill-fight-semaphores.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_semaphore.php';
require_once INCLUDE_GAME . 'fight_player_state.php';
require_once INCLUDE_GAME . 'fight_save.php';

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');

$db = Database::mysql($host, $user, $pass, $dbname);
FightSemaphore::ensure_table_exists($db);
FightPlayerState::ensure_table_exists($db);
FightSave::ensure_table_exists($db);

$stmt = $db->prepare("SELECT id, sequence, state FROM fights");
$stmt->execute();
$fights = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fabricated = 0;
$already_had_one = 0;
$tags_written = 0;
$skipped_invalid_state = 0;

foreach ($fights as $row) {
    $fightid = (int) $row['id'];

    if (FightSemaphore::load($db, $fightid) !== null) {
        $already_had_one++;
        continue;
    }

    try {
        $state = FightStatus::from((int) $row['state']);
    } catch (\ValueError $e) {
        echo "Skipping fightid $fightid: legacy state value {$row['state']} doesn't map to a known FightStatus.\n";
        $skipped_invalid_state++;
        continue;
    }

    // timeout is irrelevant for a fabricated row -- it's never
    // consulted again once a fight is done, and for one that isn't,
    // there is no historical timeout value to recover anyway. now()
    // is a safe placeholder either way.
    (new FightSemaphore($fightid, $state, (int) $row['sequence'], time()))->save($db);
    $fabricated++;
    echo "Fabricated semaphore for fightid $fightid: state={$state->name}, sequence={$row['sequence']}.\n";

    if ($state !== FightStatus::Resolved) {
        continue;
    }

    $teamids = array_unique(array_map(
        fn($player_state) => $player_state->teamid,
        FightPlayerState::load_all_by_fightid($db, $fightid)
    ));
    foreach ($teamids as $teamid) {
        FightSave::record_user_history($db, $fightid, $teamid);
        $tags_written++;
    }
}

echo "\nDone. {$fabricated} semaphore(s) fabricated, {$already_had_one} fight(s) already had one, {$skipped_invalid_state} skipped (invalid legacy state), {$tags_written} 'user' tag(s) retroactively written.\n";
