<?php
// Shared set-up for the public account pages (register.php,
// confirm_email.php, resend_confirmation.php, forgot_password.php,
// reset_password.php): nobody needs to be logged in, and a logged-in
// player is sent to the game instead.

require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';
require_once INCLUDE_GAME . 'account_flow.php';
require_once INCLUDE_GAME . 'human_check.php';

function account_page_start(): void {
    if (is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function account_flow(): AccountFlow {
    return new AccountFlow(get_database(), mailer());
}

// The visitor's address, for rate limits. REMOTE_ADDR is the connecting
// machine: behind a reverse proxy that's the proxy, so every visitor
// would share one limit -- the proxy's forwarded-for header would then
// need trusting here instead.
function client_ip(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

// What templates/account/_bot_check*.html.twig need.
function bot_check_values(HumanCheck $check): array {
    return [
        'honeypot_field' => HONEYPOT_FIELD,
        'turnstile_site_key' => $check->enabled() ? $check->site_key : '',
    ];
}

// Honeypot, then Turnstile. Null when the form may go ahead; otherwise
// 'bot' (honeypot filled: answer as if it worked, but do nothing) or a
// message for the person.
function bot_check_problem(HumanCheck $check, array $post): ?string {
    if (honeypot_tripped($post)) {
        return 'bot';
    }
    if (!$check->passed($post, client_ip())) {
        return "We couldn't confirm you're a person. Please try again.";
    }
    return null;
}
