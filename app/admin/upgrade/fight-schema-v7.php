<?php
/**
 * Fixes the two real, blocking gaps that surfaced the first time
 * FIGHT::save() actually ran against a live, previously-existing
 * fights table (confirmed by direct reproduction against a real
 * database dump, not guessed at):
 *
 *   1. fights.id has never actually had AUTO_INCREMENT. FIGHT_STORE
 *      never needed it -- FIGHT_STORE::init_fight() always computed
 *      the next id itself (a locked "find the first gap" query) and
 *      supplied it explicitly. FIGHT::save() (via DbStoreTrait) does
 *      the opposite: for a new row, it leaves the primary key column
 *      out of the INSERT entirely, the same way every other
 *      DbStoreTrait entity in this codebase already does, trusting
 *      the database to assign one. Without AUTO_INCREMENT, that INSERT
 *      fails outright ("Field 'id' doesn't have a default value").
 *
 *   2. fight_data/sequence/timeout are still NOT NULL with no default
 *      -- correct for FIGHT_STORE, which always supplied all three
 *      explicitly, but FIGHT's own save_columns is just ['id'] now
 *      (see fight.php's own comment on why), so its INSERT never
 *      touches these columns at all. The same INSERT that fights.id's
 *      gap alone doesn't explain still fails on whichever of these
 *      three it reaches next, one at a time, until all three are
 *      relaxed together.
 *
 * Both together, not one at a time: fixing only #1 still leaves fight
 * creation broken on #2 (confirmed directly -- fixing AUTO_INCREMENT
 * alone still throws on fight_data next).
 *
 * Nothing here affects FIGHT_STORE's own behavior: it always supplied
 * explicit values for every column it uses, whether or not that
 * column allows NULL or has a default, so relaxing these constraints
 * changes nothing about what FIGHT_STORE itself writes.
 *
 * Safe to re-run: each change is guarded by its own check.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v7.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';

$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$dbname = getenv('DB_DATABASE');

$db = Database::mysql($host, $user, $pass, $dbname);

function column_info(Database $db, string $table, string $column): ?array {
    $stmt = $db->prepare(
        "SELECT extra AS col_extra, is_nullable AS col_is_nullable FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

$id_info = column_info($db, 'fights', 'id');
if ($id_info !== null && str_contains(strtolower($id_info['col_extra']), 'auto_increment')) {
    echo "fights.id already AUTO_INCREMENT, skipping.\n";
} else {
    echo "Adding AUTO_INCREMENT to fights.id...\n";
    $db->prepare("ALTER TABLE fights MODIFY id int unsigned NOT NULL AUTO_INCREMENT")->execute();
}

$fight_data_info = column_info($db, 'fights', 'fight_data');
if ($fight_data_info !== null && $fight_data_info['col_is_nullable'] === 'YES') {
    echo "fights.fight_data already nullable, skipping.\n";
} else {
    echo "Making fights.fight_data nullable...\n";
    $db->prepare("ALTER TABLE fights MODIFY fight_data text NULL")->execute();
}

$sequence_info = column_info($db, 'fights', 'sequence');
if ($sequence_info !== null && $sequence_info['col_is_nullable'] === 'YES') {
    echo "fights.sequence already nullable, skipping.\n";
} else {
    echo "Making fights.sequence nullable with a default of 0...\n";
    $db->prepare("ALTER TABLE fights MODIFY sequence mediumint unsigned NULL DEFAULT 0")->execute();
}

$timeout_info = column_info($db, 'fights', 'timeout');
if ($timeout_info !== null && $timeout_info['col_is_nullable'] === 'YES') {
    echo "fights.timeout already nullable, skipping.\n";
} else {
    echo "Making fights.timeout nullable...\n";
    $db->prepare("ALTER TABLE fights MODIFY timeout datetime NULL")->execute();
}

echo "Done.\n";
