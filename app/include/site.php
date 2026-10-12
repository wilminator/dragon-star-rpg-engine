<?php
// The site's own public address, from APP_URL (for example
// https://dragonstar.example.com, or http://localhost:8080 in
// development). Needed for any link that leaves the site -- in an email,
// or as an OAuth return address. It's never guessed from the request's
// Host header: anyone can send a request with a false one, and a
// password-reset link pointing at their site would hand them the reset.
function app_url(string $path = ''): string {
    $base = rtrim((string) getenv('APP_URL'), '/');
    if ($base === '') {
        throw new RuntimeException('APP_URL is not set. Set it to the address players use to reach the site.');
    }
    return $base . '/' . ltrim($path, '/');
}
