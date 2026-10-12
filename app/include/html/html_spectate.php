<?php
// Renders a fight list from FightSpectator::list_watchable(), shared by
// the public spectate.php and admin/active_fights.php. Each party a
// viewer may sit with is one link, labeled with the party's name; its
// player teams are listed beneath for reference. $viewer_url is the
// path to spectate_fight.php from the page doing the rendering.
// Every name is player-chosen text, so all output is escaped.

function spectate_escape($text): string {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

// $take_over: for admins who may take control of monsters -- ['url' =>
// the take_over.php path, 'csrf' => a csrf_field(), 'present' => [fightid
// => [party index, ...] with a player present]] -- or null.
function html_fight_list(string $title, array $fights, string $viewer_url, string $back_url, bool $show_admin_details = false, ?array $take_over = null, ?string $problem = null): void {
    echo "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n<title>" . spectate_escape($title) . "</title>\n";
    echo "<style>\n"
       . "body { font-family: sans-serif; background: #000; color: #fff; margin: 2em; }\n"
       . "a { color: #9cf; }\n"
       . ".fight { border: 2px solid #33f; margin: 1em 0; padding: 0.5em 1em; }\n"
       . ".fight h2 { font-size: 1em; margin: 0.3em 0; color: #ccc; }\n"
       . ".party { margin: 0.4em 0 0.4em 1em; }\n"
       . ".party ul { margin: 0.2em 0; }\n"
       . ".note { color: #aaa; font-size: 0.9em; }\n"
       . "</style>\n</head>\n<body>\n";
    echo "<h1>" . spectate_escape($title) . "</h1>\n";
    if ($problem !== null) {
        echo "<p style=\"color:#f66\">" . spectate_escape($problem) . "</p>\n";
    }

    if ($fights === []) {
        echo "<p>There are no fights to watch right now.</p>\n";
    }
    foreach ($fights as $number => $fight) {
        $minutes = intdiv(max(0, $fight['seconds_remaining']), 60);
        $seconds = str_pad((string) (max(0, $fight['seconds_remaining']) % 60), 2, '0', STR_PAD_LEFT);
        echo "<div class=\"fight\">\n";
        echo "<h2>Fight " . ($number + 1) . " &mdash; round " . spectate_escape($fight['round'] + 1)
           . ", next round in about $minutes:$seconds";
        if ($show_admin_details) {
            echo $fight['spectatable'] ? " &mdash; open to the public" : " &mdash; admins only";
        }
        echo "</h2>\n";
        foreach ($fight['parties'] as $party) {
            $url = $viewer_url . '?fight=' . urlencode($fight['guid']) . '&party=' . (int) $party['index'];
            echo "<div class=\"party\"><a href=\"" . spectate_escape($url) . "\">" . spectate_escape($party['name']) . "</a>";
            if ($party['monster_controlled']) {
                echo " <span class=\"note\">(a player controlling the monsters)</span>";
            }
            $present = in_array($party['index'], $take_over['present'][$fight['fightid']] ?? [], true);
            if ($take_over !== null && ($party['is_monsters'] ?? false) && $fight['fightid'] !== null && !$present) {
                echo " <form method=\"post\" action=\"" . spectate_escape($take_over['url']) . "\" style=\"display:inline\">"
                   . $take_over['csrf'] . "<input type=\"hidden\" name=\"fightid\" value=\"" . (int) $fight['fightid'] . "\">"
                   . "<button type=\"submit\" name=\"action\" value=\"take\">Take over the monsters</button></form>";
            }
            if ($party['teams'] !== []) {
                echo "\n<ul>\n";
                foreach ($party['teams'] as $team) {
                    echo "<li>" . spectate_escape($team) . "</li>\n";
                }
                echo "</ul>";
            }
            echo "</div>\n";
        }
        echo "</div>\n";
    }

    echo "<p><a href=\"" . spectate_escape($back_url) . "\">Back</a></p>\n</body>\n</html>\n";
}
