<?php
// A player's public profile, by their guid: ?p=<guid>. Open to anyone,
// logged in or not; shows nothing unless the player has made it public.
// See include/player_profile.php. Page: templates/profile.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'player_profile.php';
require_once INCLUDE_GAME . 'view.php';

$guid = (string) ($_GET['p'] ?? '');
$profile = preg_match('/^[0-9a-f]{32}$/', $guid) ? PlayerProfile::public_view(get_database(), $guid) : null;
if ($profile === null) {
    http_response_code(404);
}
render('profile.html.twig', ['profile' => $profile]);
