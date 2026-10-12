<?php
// The site log (GameLog), for admins holding REVIEW_LOGS: open entries
// counted by log and kind; a search by log, level, kind, player, fight,
// time, status and text; each entry's details; marking entries resolved
// (or open again); and each log's settings -- on or off, and retention
// (age, rows, cull). Page: templates/admin/logs.html.twig.

define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'errorlog.php';
require_once INCLUDE_GAME . 'auth.php';

$userid = authenticate_membership_access('dse', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');
if (!check_permission($userid, 'dse', 'REVIEW_LOGS')) {
    header('Location: index.php');
    exit;
}

require_once INCLUDE_GAME . 'game_log.php';
require_once INCLUDE_GAME . 'view.php';

$db = get_database();
$messages = [];
$problems = [];
$action = (string) ($_POST['action'] ?? '');
$back = (string) ($_POST['return'] ?? '');
$back = $back !== '' && $back[0] === '?' ? $back : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $problems[] = 'The form expired. Please try again.';
    } elseif ($action === 'resolve' || $action === 'reopen') {
        GameLog::set_resolved($db, array_filter((array) ($_POST['ids'] ?? []), 'is_numeric'), $action === 'resolve', (int) $userid);
        header('Location: logs.php' . $back);
        exit;
    } elseif (array_key_exists($category = (string) ($_POST['category'] ?? ''), GameLog::CATEGORIES)) {
        $label = GameLog::CATEGORIES[$category];
        if ($action === 'settings') {
            $number = fn(string $name) => trim((string) ($_POST[$name] ?? '')) === '' ? null : (int) $_POST[$name];
            $found = GameLog::set_retention($db, $category, $number('days'), $number('rows'), (int) ($_POST['cull_percent'] ?? 0));
            if ($found === []) {
                if ($category === 'debug') {
                    // The debug log is switched on channel by channel.
                    $on = array_map('strval', (array) ($_POST['channels'] ?? []));
                    foreach (array_keys(GameLog::DEBUG_CHANNELS) as $channel) {
                        GameLog::set_debug_enabled($db, $channel, in_array($channel, $on, true));
                    }
                } else {
                    GameLog::set_enabled($db, $category, ($_POST['enabled'] ?? '') === '1');
                }
                $messages[] = "Settings saved for \"$label\".";
            }
            $problems = array_merge($problems, $found);
        } elseif ($action === 'purge') {
            $messages[] = 'Purged ' . GameLog::purge($db, $category) . " entries from \"$label\".";
        }
    }
}

// --- Search ---
$input = [
    'category' => array_key_exists($_GET['category'] ?? '', GameLog::CATEGORIES) ? $_GET['category'] : '',
    'level' => in_array($_GET['level'] ?? '', GameLog::LEVELS, true) ? $_GET['level'] : '',
    'kind' => trim((string) ($_GET['kind'] ?? '')),
    'player' => trim((string) ($_GET['player'] ?? '')),
    'fight' => trim((string) ($_GET['fight'] ?? '')),
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to' => trim((string) ($_GET['to'] ?? '')),
    'status' => in_array($_GET['status'] ?? 'open', ['open', 'resolved', 'all'], true) ? ($_GET['status'] ?? 'open') : 'open',
    'text' => trim((string) ($_GET['text'] ?? '')),
];
$filters = ['category' => $input['category'], 'level' => $input['level'], 'kind' => $input['kind'], 'text' => $input['text'],
    'status' => $input['status'] === 'all' ? '' : $input['status']];
$search_problems = [];
if ($input['player'] !== '') {
    $player = ctype_digit($input['player']) ? UserAccount::load($db, (int) $input['player'])
        : UserAccount::load_by_username($db, $input['player']);
    if ($player === null) {
        $search_problems[] = 'No player "' . $input['player'] . '".';
    } else {
        $filters['userid'] = $player->userid;
    }
}
if ($input['fight'] !== '') {
    if (ctype_digit($input['fight'])) {
        $filters['fightid'] = (int) $input['fight'];
    } else {
        $search_problems[] = 'The fight is a number.';
    }
}
foreach (['from', 'to'] as $end) {
    if ($input[$end] === '') {
        continue;
    }
    $time = strtotime($input[$end]);
    if ($time === false) {
        $search_problems[] = "Couldn't read the " . ucfirst($end) . ' time.';
        continue;
    }
    if ($end === 'to' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[$end])) {
        $time = strtotime('+1 day', $time) - 1; // a date alone covers the whole day
    }
    $filters[$end] = $time;
}

$entries = $search_problems === [] ? GameLog::search($db, $filters) : [];
$names = [];
foreach ($entries as $entry) {
    foreach ([$entry['userid'], $entry['resolved_by']] as $id) {
        if ($id !== null && !array_key_exists($id, $names)) {
            $names[$id] = UserAccount::load($db, $id)?->username;
        }
    }
}

$logs = [];
foreach (GameLog::CATEGORIES as $category => $label) {
    $channels = [];
    if ($category === 'debug') {
        foreach (GameLog::DEBUG_CHANNELS as $channel => $channel_label) {
            $channels[] = ['name' => $channel, 'label' => $channel_label, 'enabled' => GameLog::debug_enabled($channel, $db)];
        }
    }
    $logs[] = ['category' => $category, 'label' => $label, 'enabled' => GameLog::enabled($db, $category), 'channels' => $channels,
        'count' => GameLog::count($db, $category)] + GameLog::retention($db, $category);
}

render('admin/logs.html.twig', [
    'input' => $input,
    'levels' => GameLog::LEVELS,
    'categories' => GameLog::CATEGORIES,
    'kinds' => GameLog::kinds($db, $input['category']),
    'open_counts' => GameLog::open_counts($db),
    'logs' => $logs,
    'messages' => $messages,
    'problems' => array_merge($problems, $search_problems),
    'searched' => $search_problems === [],
    'return' => '?' . http_build_query(array_filter($input, fn($v) => $v !== '')),
    'limit' => 200,
    'entries' => array_map(fn($e) => [
        'username' => $e['userid'] === null ? null : ($names[$e['userid']] ?? null),
        'resolved_by_name' => $e['resolved_by'] === null ? null : ($names[$e['resolved_by']] ?? (string) $e['resolved_by']),
        'details_text' => $e['details'] === null ? null : json_encode($e['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ] + $e, $entries),
]);
