<?php
// Moved into the hub (app.php); the old page is in legacy/ for reference.
// Old links and bookmarks land in the hub, keeping a picked team.
$teamid = isset($_GET['teamid']) ? '?teamid=' . (int) $_GET['teamid'] : '';
header('Location: app.php' . $teamid);
exit;
