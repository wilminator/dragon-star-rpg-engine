<?php
/**
 * Fourth migration, adding two independent things together since
 * they're landing in the same pass:
 *
 *   - fight_pending_actions (new table) -- a player's most recently
 *     submitted command/using/target per character. See
 *     pending_action.php for the full rationale; briefly, this used to
 *     ride along inside fights.fight_data as a side effect of saving
 *     the whole fight blob, and needs its own home as fight_data is
 *     phased out.
 *
 *   - fights.xpdebt_json (nullable) -- xp_debts is accumulated
 *     per-effect during combat (add_debt_to_xp_tree(), called from
 *     inflict_damage()/restore_HP()/restore_MP()) and consumed only at
 *     fight resolution (FIGHT::distribute_party_xp()); it's
 *     server-only bookkeeping, never meant for the client, so it's
 *     deliberately excluded from CHARACTER's own dehydrate() output
 *     (and therefore from the action log / reintegration) -- storing
 *     it there would mean either filtering it back out for clients or,
 *     if kept only in-memory, re-copying an ever-growing list into
 *     every recorded final_state. A dedicated column sidesteps both:
 *     written wherever a turn resolves, alongside final_state, read
 *     back only server-side. Same non-history relationship to FIGHT
 *     that fights.fight_data itself already has -- overwritten each
 *     time, not appended.
 *
 * Purely additive. Nothing existing is renamed, dropped, or has its
 * meaning changed.
 *
 * Safe to re-run: both additions are guarded by their own existence
 * checks.
 *
 * Run from the CLI inside the container:
 *   docker compose exec web php admin/upgrade/fight-schema-v4.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'pending_action.php';

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

if (!column_exists($db, 'fights', 'xpdebt_json')) {
    echo "Adding fights.xpdebt_json...\n";
    $db->prepare("ALTER TABLE fights ADD COLUMN xpdebt_json LONGTEXT NULL")->execute();
} else {
    echo "fights.xpdebt_json already exists, skipping.\n";
}

echo "Ensuring fight_pending_actions exists...\n";
PendingAction::ensure_table_exists($db);

echo "Done.\n";
