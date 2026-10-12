<?php
// The hub: the game's one page between fights. The party in formation,
// with the menu always visible beside it; everything else opens as a
// dialog over it (javascript/hub.js), or -- until it moves into the app
// -- its old page. The party itself lives in the database (PartyState).
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'csrf.php';
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'player_settings.php';
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'item.php';
require_once INCLUDE_GAME . 'html/html_equip_menu.php';
require_once INCLUDE_GAME . 'html/html_list_menu.php';

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_membership_access('dse', AUTH_LOGIN, 'index.php');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

$db = get_database();
# In a fight: the hub opens it over itself (see hub_fight() in hub.js).
$resume_fight = (new FightCommunicator($db, $userid))->is_in_active_fight();
if (isset($_GET['teamid'])) {
    PartyState::choose_team($db, $userid, (int) $_GET['teamid']);
}
$teamid = PartyState::lead_teamid($db, $userid);
if ($teamid === null && !$resume_fight) {
    header('Location: pick_team.php');
    exit;
}

$scripts = ['object_api.js', 'image_cache.js', 'rpc.js', 'dialogs.js', 'dialog_text.js', 'status_dialog.js', 'equip_dialog.js',
    'quantity_dialog.js', 'conversation_dialog.js', 'menu_dialog.js', 'list_window.js', 'controls.js', 'settings.js', 'gamepad.js', 'hub.js'];
$version = fn($file) => @filemtime($file) ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>DragonStar</title>
<?php foreach (['fonts.css', 'fight.css', 'list_menu.css', 'equip_menu.css', 'dialogs.css', 'hub.css'] as $css): ?>
<link rel="stylesheet" href="<?= CSS_DIR . $css ?>?v=<?= $version(CSS_DIR . $css) ?>">
<?php endforeach; ?>
<script>
var rpc_csrf_token = <?= json_encode(csrf_token()) ?>;
var items = <?= ITEM::all_to_js() ?>;
var equip_layout = <?= json_encode(get_equip_points()) ?>;
var player_settings = <?= PlayerSettings::to_js($db, (int) $userid) ?>;
</script>
<?php foreach ($scripts as $script): ?>
<script src="<?= JAVASCRIPT_DIR . $script ?>?v=<?= $version(JAVASCRIPT_DIR . $script) ?>"></script>
<?php endforeach; ?>
</head>
<body>
<div class="screen hubscreen" id="stage" role="main">
    <div class="hubparty" id="hubparty" aria-label="Your party"></div>
    <nav class="hubmenu" id="hubmenu"></nav>
    <div class="hubstatus" id="hubstatus" aria-live="polite"></div>
<?php html_equip_menu(); html_list_menu(); ?>
</div>
<script>
hub_start({
    stage: document.getElementById('stage'),
    party: document.getElementById('hubparty'),
    menu: document.getElementById('hubmenu'),
    status: document.getElementById('hubstatus'),
    fighter_images: <?= json_encode(FIGHTER_IMAGES_DIR) ?>,
    item_images: <?= json_encode(ITEM_IMAGES_DIR) ?>,
    resume_fight: <?= json_encode($resume_fight) ?>,
});
</script>
</body>
</html>
