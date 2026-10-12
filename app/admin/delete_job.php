<?php
#Do LOGIN stuff :)
define ('INCLUDE_GAME','../include/');
require_once INCLUDE_GAME.'auth.php';
$userid=authenticate_permission_access('dse','EDIT_JOB',AUTH_LOGIN,'../');
redirect_on_hold($userid,'dse','on_hold.php','../../index.php');

require_once INCLUDE_GAME.'database.php';
require_once INCLUDE_GAME.'job.php';

// Ensure a job is specified.
if (!array_key_exists('job',$_REQUEST)) {
  header ('Location: jobs.php');
  exit;
}

// Get the job id number.
$jobindex=$_REQUEST['job'];

try{
  $db = get_database();
  $job = JOB::load($db, $jobindex);
  if ($job->state == ObjectState::INACTIVE) {
    header ('Location: jobs.php');
    exit;
  }
}
catch (Exception $e){
  # This jobid does not exist. Abort.
  header ('Location: jobs.php');
  exit;
}

if(isset($_GET['confirm']) && $_GET['confirm']=="YES")
    {
    //delete that job in the databse
    $job->change_object_state($db, ObjectState::INACTIVE);
    header ('Location: jobs.php');
    exit;
    }

//Snag the name
$name=$job->name;

?>
<table>
  <tr><th>
    <table>
      <caption><b>Are you sure you want to deactivate the <?= $name ?> job?</b></caption>
      <tr>
        <td>
          <?php
          echo "<a href=\"delete_job.php?job=$jobindex&confirm=YES\">Yes, deactivate this job.</a>";
          ?>
        </td>
        <td><a href="jobs.php">Do not deactivate this.</a></td>
      </tr>
    </table>
  </th></tr>
</table>

