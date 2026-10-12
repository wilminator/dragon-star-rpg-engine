<?php
require_once INCLUDE_GAME . 'database.php';

/**
 * A player's game settings, saved with the account so they follow them to
 * any device: audio (music and sound effects, each with a volume and a
 * mute) and what the fight screen shows (blood, action animations, the
 * flying HP numbers). The settings popup (javascript/settings.js) edits
 * them; every page that plays fights hands them to the page as
 * player_settings.
 *
 *   load()   every setting, defaults for any never saved
 *   save()   merges the given settings in, keeping only known keys and
 *            valid values; returns the full set as saved
 */
final class PlayerSettings {
    private static bool $table_verified = false;

    const DEFAULTS = [
        'music_volume' => 100,    // 0-100
        'music_muted' => false,
        'sound_volume' => 100,    // 0-100
        'sound_muted' => false,
        'hide_gore' => false,     // blood
        'hide_actions' => false,  // attack and spell animations (their effects still show)
        'hide_hp_popups' => false, // the numbers that fly off whoever's hit or healed
        'time_multiplier' => 1.0, // round time: 1, 1.5 or 2 (see TIME_MULTIPLIERS)
        'text_log' => false,      // the combat log beside the fight
        'key_map' => [],          // action => key, where the player changed it (controls.js)
        'pad_map' => [],          // action => controller button number, likewise
    ];

    // What the maps may hold (javascript/controls.js has the defaults).
    const KEY_ACTIONS = ['up', 'down', 'left', 'right', 'confirm', 'back', 'commit'];
    const PAD_ACTIONS = ['up', 'down', 'left', 'right', 'confirm', 'back', 'commit', 'settings', 'prev_control', 'next_control'];

    // Longer rounds, and what they cost a hero player: the monsters they
    // face get this much more max HP (so they're worth more PXP too).
    const TIME_MULTIPLIERS = ['1' => 0.0, '1.5' => 0.05, '2' => 0.10];

    // The extra monster HP for $multiplier, as a fraction (0.05 = 5%).
    public static function hp_bonus(float $multiplier): float {
        return self::TIME_MULTIPLIERS[self::multiplier_key($multiplier)] ?? 0.0;
    }

    private static function multiplier_key(float $multiplier): string {
        return rtrim(rtrim(number_format($multiplier, 1, '.', ''), '0'), '.');
    }

    public static function ensure_table_exists(Database $db, bool $reset = false): void {
        if (static::$table_verified && !$reset) {
            return;
        }
        $db->ensure_table_exists(
            "DROP TABLE IF EXISTS player_settings",
            <<<QUERY
CREATE TABLE IF NOT EXISTS player_settings (
  userid int NOT NULL PRIMARY KEY,
  settings text NOT NULL,
  updated_at int NOT NULL
);
QUERY,
            $reset
        );
        static::$table_verified = true;
    }

    public static function load(Database $db, int $userid): array {
        static::ensure_table_exists($db);
        $stmt = $db->prepare("SELECT settings FROM player_settings WHERE userid = ?");
        $stmt->execute([$userid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $saved = $row === false ? [] : json_decode((string) $row['settings'], true);
        return self::clean(is_array($saved) ? $saved : [], self::DEFAULTS);
    }

    public static function save(Database $db, int $userid, array $changes): array {
        $settings = self::clean($changes, self::load($db, $userid));
        $db->prepare($db->upsert_query('player_settings', ['userid', 'settings', 'updated_at'], 'userid'))
            ->execute([$userid, json_encode($settings), time()]);
        return $settings;
    }

    // $base with whatever in $changes is a known setting of the right
    // kind: volumes as whole numbers clamped to 0-100, the rest as
    // booleans. Anything else is ignored.
    private static function clean(array $changes, array $base): array {
        $settings = $base;
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $value = $changes[$key];
            if (is_object($value)) {
                $value = get_object_vars($value); // the RPC layer's decoding
            }
            if ($key === 'key_map' || $key === 'pad_map') {
                if (is_array($value)) {
                    $settings[$key] = self::clean_map($key, $value);
                }
            } elseif ($key === 'time_multiplier') {
                if (is_numeric($value) && array_key_exists(self::multiplier_key((float) $value), self::TIME_MULTIPLIERS)) {
                    $settings[$key] = (float) $value;
                }
            } elseif (is_int($default)) {
                if (is_numeric($value)) {
                    $settings[$key] = max(0, min(100, (int) round((float) $value)));
                }
            } elseif (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
                $settings[$key] = (bool) $value;
            }
        }
        return $settings;
    }

    // A controls map: known actions only; keys as short key names (as the
    // browser names them: 'w', 'ArrowUp', ' '), buttons as numbers 0-31.
    private static function clean_map(string $which, array $map): array {
        $clean = [];
        foreach ($map as $action => $value) {
            if ($which === 'key_map' && in_array($action, self::KEY_ACTIONS, true)
                && is_string($value) && $value !== '' && strlen($value) <= 20 && !preg_match('/[\x00-\x1f<>"]/', $value)) {
                $clean[$action] = $value;
            } elseif ($which === 'pad_map' && in_array($action, self::PAD_ACTIONS, true)
                && is_numeric($value) && (int) $value >= 0 && (int) $value <= 31) {
                $clean[$action] = (int) $value;
            }
        }
        return $clean;
    }

    // For a page: the settings as a JS object literal (defaults for a
    // visitor who isn't logged in).
    public static function to_js(Database $db, ?int $userid): string {
        return json_encode($userid === null ? self::DEFAULTS : self::load($db, $userid));
    }
}
