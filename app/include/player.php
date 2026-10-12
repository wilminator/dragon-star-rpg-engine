<?php
require_once INCLUDE_GAME . 'database.php';

/**
 * PLAYER wraps the game's own, minimal record for a playerid --
 * currently just a guid. The login (playerid) itself represents a
 * player; this isn't a duplicate of that external identity, only the
 * one thing the game needs to track that the login system doesn't
 * hold: an unguessable public identifier, laid down now for a future
 * feature (public player pages) that doesn't exist yet.
 *
 * No registration flow of its own -- a playerid becomes known to this
 * table lazily, the first time guid_for() is asked for one, not at
 * account creation (which this game doesn't own or control).
 */
class PLAYER {
    private static bool $table_verified = false;

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS players",
            <<<QUERY
CREATE TABLE IF NOT EXISTS players (
  playerid int NOT NULL,
  guid varchar(32) NOT NULL,
  PRIMARY KEY (playerid)
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    // Returns this player's guid, creating one (and the row) the first
    // time it's asked for -- there's nowhere else a playerid's guid
    // would already exist before this, since this table is the only
    // place it's ever stored.
    public static function guid_for(Database $db, int $playerid): string {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT guid FROM players WHERE playerid = ?");
        $stmt->execute([$playerid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row['guid'];
        }
        $guid = bin2hex(random_bytes(16));
        $query = $db->upsert_query('players', ['playerid', 'guid'], ['playerid']);
        $stmt = $db->prepare($query);
        $stmt->execute([$playerid, $guid]);
        return $guid;
    }

    public static function find_playerid_by_guid(Database $db, string $guid): ?int {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT playerid FROM players WHERE guid = ?");
        $stmt->execute([$guid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : (int) $row['playerid'];
    }
}
