<?php
/**
 * Third migration on fight_actions, adding:
 *
 *   - fight_actions.action_json (nullable, JSON as text) -- temporary
 *     column. FightAction::save() now writes the same action list to
 *     both this column (json_encode) and the existing action_data
 *     column (serialize, matching what fight_store.php's own insert
 *     already writes there) -- two formats of the same data, so the
 *     legacy PHP-serialize() column fight_store.php's own code still
 *     relies on is never overwritten with JSON. FightAction reads
 *     action_json back in preference to action_data, falling back to
 *     unserialize(action_data) for rows written before this column
 *     existed. Temporary: expected to collapse back into one column
 *     once reintegration's real data-format needs are known.
 *
 * Purely additive. Nothing existing is renamed, dropped, or has its
 * meaning changed.
 *
 * Safe to re-run: the column addition is guarded by its own existence
 * check.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/fight-schema-v3.php
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

if (!column_exists($db, 'fight_actions', 'action_json')) {
    echo "Adding fight_actions.action_json...\n";
    $db->prepare("ALTER TABLE fight_actions ADD COLUMN action_json LONGTEXT NULL")->execute();
} else {
    echo "fight_actions.action_json already exists, skipping.\n";
}

echo "Done.\n";
