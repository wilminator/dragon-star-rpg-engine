<?php
/**
 * Brings the live teams/team_relations schema in line with the new TEAM
 * class (see include/team.php). This was never actually run when TEAM
 * itself was designed/built -- ensure_table_exists()'s validation query
 * (`select 1 from teams limit 1`) only checks that the table exists, not
 * that its columns match, so the old schema silently persisted until a
 * real load surfaced the mismatch as a TypeError.
 *
 *   - teams gains three new columns: quests (JSON), state (TINYINT,
 *     matching ObjectState's backing), deactivated_at (DATETIME NULL).
 *     Every existing team gets state=0 (ACTIVE) and deactivated_at=NULL --
 *     the old schema had no retirement concept at all, so every team is
 *     presumed active.
 *   - team_quests (teamid, quest, prestige, mark rows) collapses into
 *     teams.quests as a JSON array of {quest, prestige, mark} objects,
 *     then the table itself is dropped.
 *   - team_relations.state converts from the old, typo'd MySQL enum
 *     ('allied','neutral','ememies') to a TINYINT matching RelationState's
 *     int backing (ALLY=0, NEUTRAL=1, ENEMY=2). Deliberately NOT relying on
 *     MySQL's implicit enum-to-int conversion, which is 1-indexed
 *     ('allied'=1, 'neutral'=2, 'ememies'=3) and would silently produce the
 *     wrong values -- each row's string value is read and mapped explicitly.
 *   - team_characters is dropped entirely -- already fully obsolete,
 *     replaced by heroes.teamid.
 *
 * Safe to re-run: every step is gated by a column/table-existence check.
 *
 * Run from the CLI inside the container, not through the browser:
 *   docker compose exec web php admin/upgrade/team-schema-v1.php
 */

define('INCLUDE_GAME', __DIR__ . '/../../include/');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';

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
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int) $row['cnt']) > 0;
}

function table_exists(Database $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int) $row['cnt']) > 0;
}

// --- Step 1: add the three new columns to teams, if not already present ---

$new_columns = [
    'quests' => "JSON NULL",
    'state' => "TINYINT(4) UNSIGNED NOT NULL DEFAULT '0'",
    'deactivated_at' => "DATETIME NULL",
];
foreach ($new_columns as $col => $def) {
    if (!column_exists($db, 'teams', $col)) {
        echo "Adding $col column to teams...\n";
        $db->prepare("ALTER TABLE teams ADD COLUMN `$col` $def")->execute();
    } else {
        echo "$col column already exists on teams, skipping.\n";
    }
}

// --- Step 2: migrate team_quests -> teams.quests JSON, per team ---

if (table_exists($db, 'team_quests')) {
    echo "Migrating team_quests into teams.quests...\n";

    $select = $db->prepare("SELECT teamid FROM teams");
    $select->execute();
    $teamids = array_map(fn($r) => (int) $r['teamid'], $select->get_result()->fetch_all(MYSQLI_ASSOC));

    $quest_select = $db->prepare("SELECT quest, prestige, mark FROM team_quests WHERE teamid = ?");
    $update = $db->prepare("UPDATE teams SET quests = ? WHERE teamid = ?");

    $db->begin_transaction();
    try {
        foreach ($teamids as $teamid) {
            $quest_select->bind_param("i", $teamid);
            $quest_select->execute();
            $quests = array_map(
                fn($row) => [
                    'quest' => $row['quest'],
                    'prestige' => (int) $row['prestige'],
                    'mark' => $row['mark'],
                ],
                $quest_select->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $quests_json = json_encode($quests);
            $update->bind_param("si", $quests_json, $teamid);
            $update->execute();
        }
        $db->commit();
        echo "Migrated quests for " . count($teamids) . " team(s).\n";
    } catch (\Throwable $e) {
        $db->rollback();
        echo "Quest migration failed, rolled back: " . $e->getMessage() . "\n";
        exit(1);
    }

    echo "Dropping team_quests table...\n";
    $db->prepare("DROP TABLE IF EXISTS team_quests")->execute();
} else {
    echo "team_quests already gone, skipping quest migration.\n";
}

// --- Step 3: fill in state/quests defaults for any row the above steps left untouched ---

$db->prepare("UPDATE teams SET state = 0 WHERE state IS NULL")->execute();
$db->prepare("UPDATE teams SET quests = '[]' WHERE quests IS NULL")->execute();

// --- Step 4: tighten teams' new columns to NOT NULL now that every row has real data ---

echo "Tightening teams column constraints...\n";
$db->prepare(
    "ALTER TABLE teams
        MODIFY quests JSON NOT NULL default ('[]'),
        MODIFY state TINYINT(4) UNSIGNED NOT NULL default '0'"
)->execute();

// --- Step 5: convert team_relations.state from the old MySQL enum to a TINYINT ---

if (table_exists($db, 'team_relations') && !column_exists($db, 'team_relations', 'state_new')) {
    // Confirm the column is still the old string enum before touching it --
    // if it's already a TINYINT (a prior partial run), this whole step is
    // a no-op below.
    $check = $db->prepare(
        "SELECT DATA_TYPE AS data_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'team_relations' AND column_name = 'state'"
    );
    $check->execute();
    $type_row = $check->get_result()->fetch_assoc();

    if ($type_row !== null && strtolower($type_row['data_type']) === 'enum') {
        echo "Converting team_relations.state from enum to TINYINT...\n";

        $db->prepare("ALTER TABLE team_relations ADD COLUMN state_new TINYINT(4) UNSIGNED NOT NULL DEFAULT 1")->execute();

        $relation_map = ['allied' => 0, 'neutral' => 1, 'ememies' => 2];
        $select = $db->prepare("SELECT teamid, otherteamid, state FROM team_relations");
        $select->execute();
        $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

        $update = $db->prepare(
            "UPDATE team_relations SET state_new = ? WHERE teamid = ? AND otherteamid = ?"
        );

        $db->begin_transaction();
        try {
            foreach ($rows as $row) {
                $teamid = (int) $row['teamid'];
                $otherteamid = (int) $row['otherteamid'];
                $new_state = $relation_map[$row['state']] ?? 1; // unknown/unmapped values default to NEUTRAL
                $update->bind_param("iii", $new_state, $teamid, $otherteamid);
                $update->execute();
            }
            $db->commit();
            echo "Converted " . count($rows) . " relation row(s).\n";
        } catch (\Throwable $e) {
            $db->rollback();
            echo "Relation conversion failed, rolled back: " . $e->getMessage() . "\n";
            exit(1);
        }

        $db->prepare("ALTER TABLE team_relations DROP COLUMN state")->execute();
        $db->prepare("ALTER TABLE team_relations CHANGE COLUMN state_new state TINYINT(4) UNSIGNED NOT NULL DEFAULT 1")->execute();
    } else {
        echo "team_relations.state is already a TINYINT, skipping conversion.\n";
    }
} else {
    echo "team_relations already converted or doesn't exist, skipping.\n";
}

// --- Step 6: drop team_characters, fully obsolete now, replaced by heroes.teamid ---

if (table_exists($db, 'team_characters')) {
    echo "Dropping team_characters table...\n";
    $db->prepare("DROP TABLE IF EXISTS team_characters")->execute();
} else {
    echo "team_characters already gone, skipping.\n";
}

echo "Done.\n";
