<?php
// Finding a player by their handle, among the profiles players have made
// public and listed in search. Open to anyone. See
// include/player_profile.php. Page: templates/players.html.twig.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'rate_limit.php';
require_once INCLUDE_GAME . 'player_profile.php';
require_once INCLUDE_GAME . 'view.php';

$db = get_database();
$text = trim((string) ($_GET['q'] ?? ''));
$results = [];
$problem = null;
if ($text !== '') {
    // Searches are cheap, but not free: a steady scrape gets slowed down.
    if (!RateLimit::hit($db, 'player-search:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60)) {
        $problem = 'Too many searches. Please wait a minute.';
    } elseif (strlen($text) < 2) {
        $problem = 'Type at least two characters.';
    } else {
        $results = PlayerProfile::search($db, $text);
    }
}
render('players.html.twig', ['text' => $text, 'results' => $results, 'problem' => $problem, 'limit' => PlayerProfile::SEARCH_LIMIT]);
