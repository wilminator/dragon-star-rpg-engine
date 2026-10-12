<?php
// Public list of fights anyone may watch: live fights created as
// spectatable. Only hero parties are linked -- a party a player is
// controlling as monsters can only be watched from by admins (see
// admin/active_fights.php), and spectate_fight.php enforces that too.
// No login required.

define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight_spectator.php';
require_once INCLUDE_GAME . 'html/html_spectate.php';

$fights = FightSpectator::list_watchable(get_database(), is_admin: false);
html_fight_list('Watch a fight', $fights, 'spectate_fight.php', 'index.php');
