<?php
/**
 * Creates an account from the command line -- how the first admin is
 * made on a new install (nothing is created automatically any more; the
 * old built-in admin/change-me-admin account was a standing risk).
 *
 *   utils/create_account.sh --username=NAME --email=ADDRESS [--admin | --rights=RIGHT,RIGHT] [--password-stdin]
 *
 * or inside the container:
 *
 *   docker compose exec web php admin/create_account.php --username=... --email=...
 *
 * --admin gives every right; --rights gives just those listed (see
 * PermissionGrant::ALL); neither gives none (a plain player). The
 * password is asked for twice without echoing, or read from standard
 * input with --password-stdin (its first line). The account can log in at once: its
 * email counts as confirmed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('INCLUDE_GAME', __DIR__ . '/../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'account_admin.php';

function fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function read_hidden(string $prompt): string {
    fwrite(STDOUT, $prompt);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$options = getopt('', ['username:', 'email:', 'admin', 'rights:', 'password-stdin', 'help']);
if (isset($options['help']) || !isset($options['username'], $options['email'])) {
    fail("Usage: php admin/create_account.php --username=NAME --email=ADDRESS [--admin | --rights=RIGHT,RIGHT] [--password-stdin]\n"
        . "Rights: " . implode(', ', array_keys(PermissionGrant::ALL)));
}

$rights = [];
if (isset($options['admin'])) {
    $rights = array_keys(PermissionGrant::ALL);
} elseif (isset($options['rights'])) {
    $rights = array_values(array_filter(array_map('trim', explode(',', (string) $options['rights']))));
}

if (isset($options['password-stdin'])) {
    // The first line only: a second line (the same password again, as the
    // prompts would ask) would otherwise become part of the password.
    $line = fgets(STDIN);
    $password = $line === false ? '' : rtrim($line, "\r\n");
} else {
    $password = read_hidden('Password: ');
    if ($password !== read_hidden('Password again: ')) {
        fail("The passwords don't match.");
    }
}

try {
    $account = AccountAdmin::create(get_database(), (string) $options['username'], $password, (string) $options['email'], $rights);
} catch (InvalidArgumentException $e) {
    fail($e->getMessage());
}

echo "Created account {$account->username} (id {$account->userid})"
    . ($rights === [] ? '' : ' with ' . implode(', ', $rights)) . ".\n";
