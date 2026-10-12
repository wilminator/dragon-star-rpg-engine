<?php
require_once INCLUDE_GAME.'group.php';
function display_team(TEAM $team, string $images) {
  echo "<table><tr>\n";
  $pxp = $team->get_pxp();
  $team_name = htmlspecialchars($team->name);
  echo "<td><table>
<tr><th>Team</th><td>{$team_name}</td></tr>
<tr><th>Gold</th><td>{$team->gold}</td></tr>
<tr><th>PXP</th><td>$pxp</td></tr>
<tr><td></td></tr>
</table></td>\n";
  foreach (array_keys($team->characters) as $index) {
    echo "<td align=\"center\"><a href=\"app.php?teamid={$team->teamid}\">\n";
    display_hero($team->characters[$index], $images);
    echo "</a></td>\n";
  }
  echo "</tr></table>\n";
}

function display_hero(CHARACTER $character, string $images) {
  $job_name = JOB::get($character->jobid)->name;
  $personality = PERSONALITY::get($character->personalityid);
  $name = htmlspecialchars($character->name);
  $job_name = htmlspecialchars($job_name);
  $picture = htmlspecialchars($images . $personality->base->images[0]);
  // alt="": the picture sits in a link whose text (name, job, level)
  // already says who it is.
  echo <<<EOD
<table>
  <tr><td align="center"><img style="height:90px;width:90px;border:0px none" src="{$picture}" alt="" /></td></tr>
  <tr><td align="center"><b>{$name}</b></td></tr>
  <tr><td align="center"><b>{$job_name}</b></td></tr>
  <tr><td align="center"><b>Level {$character->level}</b></td></tr>
</table>

EOD;
}

