<?php
/**
 * Second migration on the fights/fight_actions tables, adding the
 * columns the new FightAction/initial-state validation work needs:
 *
 *   - fights.initial_state (nullable, JSON as text) -- FIGHT's own
 *     dehydrated base state, written once at creation, never touched
 *     again. Exclusively FIGHT's concern, not FightAction's -- see
 *     FightAction's own file-level comment.
 *
 *   - fight_actions.final_state (nullable, JSON as text) -- the
 *     dehydrated FIGHT state immediately after that action, for
 *     validation only: confirms hydrate(initial_state) + reintegrating
 *     every FightAction in sequence reproduces this exactly, before
 *     anything old is ever dropped. Exclusively FightAction's concern.
 *
 *   - fight_actions.prefight_data relaxed to nullable. FightAction's
 *     own save() never sets this column at all (it's the legacy,
 *     to-be-retired snapshot approach FightAction is deliberately not
 *     reproducing) -- but the row already exists with prefight_data
 *     NOT NULL by the time FightAction's save() runs (fight_store.php's
 *     own write happens first), so this only matters for the edge case
 *     of FightAction's upsert inserting a genuinely new row. Existing
 *     data and fight_store.php's own behavior are both unaffected --
 *     it already always provides a real value for this column.
 *
 * Purely additive. Nothing existing is renamed, dropped, or has its
 * meaning changed.
 *
 * Safe to re-run: each column addition is individually guarded by its
 * own existence check.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v2.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight.php';

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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ((int) $row['cnt']) > 0;
}

if (!column_exists($db, 'fights', 'initial_state')) {
    echo "Adding fights.initial_state...\n";
    $db->prepare("ALTER TABLE fights ADD COLUMN initial_state LONGTEXT NULL")->execute();
} else {
    echo "fights.initial_state already exists, skipping.\n";
}

if (!column_exists($db, 'fight_actions', 'final_state')) {
    echo "Adding fight_actions.final_state...\n";
    $db->prepare("ALTER TABLE fight_actions ADD COLUMN final_state LONGTEXT NULL")->execute();
} else {
    echo "fight_actions.final_state already exists, skipping.\n";
}

echo "Relaxing fight_actions.prefight_data to nullable (idempotent; safe to run even if already nullable)...\n";
$db->prepare("ALTER TABLE fight_actions MODIFY COLUMN prefight_data TEXT NULL")->execute();

echo "Done.\n";
