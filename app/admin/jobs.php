<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_JOB', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'effects.php';

$db = get_database();
$jobs = JOB::load_all_from_database($db, true);

//Get the job id number. 
if (array_key_exists('job', $_REQUEST)) {
  $jobindex = $_REQUEST['job'];
  if (array_key_exists($jobindex, $jobs)) {
    $job = $jobs[$jobindex];
    if ($job->state == ObjectState::INACTIVE) {
      $job->change_object_state($db, ObjectState::ACTIVE);
    }
  }
}

echo "
<center>
  <table cellpadding=\"4\" cellspacing=\"0\">
    <tr><td colspan=\"2\"><a href=\"edit_job.php?job=0\">Add new job</a></td><td colspan=\"3\"><a href=\"./\">Return to the admin menu</a></td><tr>
    ";
foreach ($jobs as $index => $job)
  if ($index != 0) {
    $name = htmlspecialchars($job->name);
    $job_stats = $job->describe_stats();
    $state_toggle = $job->state == ObjectState::ACTIVE
      ? "<a href=\"delete_job.php?job={$index}\">Deactivate</a>"
      : "<a href=\"jobs.php?job={$index}\">Activate</a>";
    echo <<<EOT
<tr>
<td><a href="edit_job.php?job={$index}">{$index}</td>
<td><a href="edit_job.php?job={$index}">{$name}</a></td>
<td>{$job_stats}</td>
<td>
{$state_toggle}
</td>
</tr>
EOT;
  }
echo "
  </table>
</center>";
