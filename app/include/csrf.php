<?php
// A per-session token for forms that change something, so another site
// can't submit them on a logged-in player's (or admin's) behalf.
//
//   echo csrf_field();          inside the <form>
//   if (!csrf_valid()) { ... }  before acting on the POST
//
// The game's server calls (processor.php) send the same token in an
// X-CSRF-Token header instead: csrf_header_valid(). Pages that load the
// game's scripts print it as rpc_csrf_token (html_fight.php).

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_valid(): bool {
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

function csrf_header_valid(): bool {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}
