<?php
/**
 * Three additions, all groundwork for the fight-replay/share-link
 * feature:
 *
 *   1. fights.guid -- an unguessable public identifier for a fight,
 *      distinct from its sequential id (which would let a share link
 *      be walked to enumerate every other fight in the system just by
 *      changing a number in the URL). Backfilled for every existing
 *      fight that doesn't have one yet.
 *
 *   2. teams.guid -- the same reasoning, for TEAM::get_team_from_guid()
 *      (a team's own share-link identity). Backfilled the same way.
 *
 *   3. The players table -- PLAYER::guid_for()'s own storage. Created
 *      empty; a playerid's guid is generated lazily the first time
 *      it's actually asked for, not backfilled here for every player
 *      that's ever logged in.
 *
 * Also retrofits fights.created_at (added by fight-schema-v8.php,
 * defaulted to 0 for every fight that predates it) using
 * fight_semaphore.timeout, for any fight that still has a semaphore
 * row: timeout = time() + (party_count*2+30)*60 at the moment it was
 * last set (see FightCommunicator::process_round()'s own
 * $timeout_minutes formula), so subtracting that back out gives an
 * ESTIMATE of when the fight's most recent round was processed -- not
 * its true creation time for any fight that ran more than one round,
 * since fight_semaphore.timeout is overwritten every round, not set
 * once at creation. Good enough to sort/display by; not a claim of
 * exact accuracy. A fight with no semaphore row at all (backfilled by
 * backfill-fight-semaphores.php, itself a fabricated Resolved state)
 * has no timeout to work from either and is left at 0.
 *
 * Safe to re-run: each column addition and each row's backfill is
 * independently guarded.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v9.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');

$db = Database::mysql($host, $user, $pass, $dbname);

function column_exists(Database $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
}

function table_exists(Database $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
}

// --- 1. fights.guid ---
if (column_exists($db, 'fights', 'guid')) {
    echo "fights.guid already exists, skipping add.\n";
} else {
    echo "Adding fights.guid...\n";
    $db->prepare("ALTER TABLE fights ADD COLUMN guid varchar(32) NOT NULL DEFAULT ''")->execute();
}

$stmt = $db->prepare("SELECT id FROM fights WHERE guid = '' OR guid IS NULL");
$stmt->execute();
$fightids_needing_guid = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
foreach ($fightids_needing_guid as $fightid) {
    $guid = bin2hex(random_bytes(16));
    $update = $db->prepare("UPDATE fights SET guid = ? WHERE id = ?");
    $update->execute([$guid, $fightid]);
}
echo "Backfilled guid for " . count($fightids_needing_guid) . " fight(s).\n";

// --- 2. teams.guid ---
if (column_exists($db, 'teams', 'guid')) {
    echo "teams.guid already exists, skipping add.\n";
} else {
    echo "Adding teams.guid...\n";
    $db->prepare("ALTER TABLE teams ADD COLUMN guid varchar(32) NOT NULL DEFAULT ''")->execute();
}

$stmt = $db->prepare("SELECT teamid FROM teams WHERE guid = '' OR guid IS NULL");
$stmt->execute();
$teamids_needing_guid = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'teamid');
foreach ($teamids_needing_guid as $teamid) {
    $guid = bin2hex(random_bytes(16));
    $update = $db->prepare("UPDATE teams SET guid = ? WHERE teamid = ?");
    $update->execute([$guid, $teamid]);
}
echo "Backfilled guid for " . count($teamids_needing_guid) . " team(s).\n";

// --- 3. players table ---
if (table_exists($db, 'players')) {
    echo "players table already exists, skipping.\n";
} else {
    echo "Creating players table...\n";
    $db->prepare(
        "CREATE TABLE IF NOT EXISTS players (
            playerid int NOT NULL,
            guid varchar(32) NOT NULL,
            PRIMARY KEY (playerid)
        )"
    )->execute();
}

// --- 4. fights.created_at retrofit via fight_semaphore.timeout ---
$stmt = $db->prepare(
    "SELECT f.id, f.initial_state, fs.timeout
     FROM fights f
     JOIN fight_semaphore fs ON fs.fightid = f.id
     WHERE f.created_at = 0 AND fs.timeout IS NOT NULL"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$retrofitted = 0;
foreach ($rows as $row) {
    $initial_state = json_decode($row['initial_state'], true);
    if ($initial_state === null || !isset($initial_state['parties'])) {
        continue;
    }
    $party_count = count($initial_state['parties']);
    $timeout_minutes = $party_count * 2 + 30;
    $estimated_created_at = (int) $row['timeout'] - $timeout_minutes * 60;
    if ($estimated_created_at <= 0) {
        continue;
    }
    $update = $db->prepare("UPDATE fights SET created_at = ? WHERE id = ?");
    $update->execute([$estimated_created_at, $row['id']]);
    $retrofitted++;
}
echo "Retrofitted created_at (estimated) for $retrofitted fight(s).\n";

echo "Done.\n";
