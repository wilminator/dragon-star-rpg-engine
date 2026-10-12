<?php
/**
 * The encryption key slots, from the command line (see
 * include/secret_box.php):
 *
 *   php admin/encryption_keys.php status     which keys are where, and what's encrypted with what
 *   php admin/encryption_keys.php free-slot  the slot a new key should go in (used by utils/new_encryption_key.sh)
 *   php admin/encryption_keys.php rotate     move everything onto a new key now, rather than at the next login
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('INCLUDE_GAME', __DIR__ . '/../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'secret_box.php';

$db = get_database();
$command = $argv[1] ?? 'status';

switch ($command) {
    case 'status':
        $state = KeyRotation::describe($db);
        echo "Key folder: " . EncryptionKeys::site()->directory . "\n";
        foreach (EncryptionKeys::SLOTS as $slot) {
            $held = array_keys(array_filter($state['slots'], fn($k) => $k['slot'] === $slot));
            $fingerprint = $held[0] ?? null;
            $role = $fingerprint === null ? 'empty'
                : ($fingerprint === $state['current'] ? 'current' : ($fingerprint === $state['new'] ? 'NEW (moves in at the next login)'
                : (in_array($fingerprint, $state['retired'], true) ? 'retired (safe to replace)' : 'in use, not yet recorded (settles at the next login)')));
            echo "Slot $slot: " . ($fingerprint ?? '-') . "  $role\n";
        }
        echo "Current key: " . ($state['current'] ?? 'none yet') . "\n";
        if ($state['current'] !== null && !array_key_exists($state['current'], $state['slots'])) {
            echo "WARNING: the current key is in neither slot; values encrypted with it can't be read.\n";
        }
        echo "Encrypted values by key: " . ($state['values_by_key'] === [] ? 'none'
            : implode(', ', array_map(fn($k, $n) => "$k: $n", array_keys($state['values_by_key']), $state['values_by_key']))) . "\n";
        echo $state['locked'] ? "A key change is running now; logins are waiting.\n" : '';
        break;

    case 'free-slot':
        // The slot not holding the current key (the one to replace).
        $current = KeyRotation::current_key($db);
        $held = EncryptionKeys::site()->available();
        $current_slot = $current === null ? null : ($held[$current]['slot'] ?? null);
        foreach (EncryptionKeys::SLOTS as $slot) {
            if ($slot !== $current_slot) {
                echo $slot . "\n";
                exit(0);
            }
        }
        exit(1);

    case 'rotate':
        $result = KeyRotation::run_if_needed($db);
        echo match ($result) {
            'none' => "Nothing to do: no new key.\n",
            'busy' => "A key change is already running.\n",
            'done' => "Done: everything is on key " . KeyRotation::current_key($db) . ".\n",
        };
        break;

    default:
        fwrite(STDERR, "Usage: php admin/encryption_keys.php status|free-slot|rotate\n");
        exit(1);
}
