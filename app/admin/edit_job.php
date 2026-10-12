<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'EDIT_JOB', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'constants.php';
require_once INCLUDE_GAME . 'functions.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'effects.php';
require_once INCLUDE_GAME . 'array.php';

$db = get_database();
$jobs = JOB::load_all_from_database($db);
$abilities = ABILITY::load_all_from_database($db);

//Get the job id number. 0 is a new job.
$jobindex = $_REQUEST['job'];
try {
  if ($jobindex != 0) {
    $job = JOB::load($db, $jobindex);
    # Screen abilities that have been retired.
    $job->abilities = array_values(array_filter($job->abilities, fn($ability_pair) => array_key_exists($ability_pair['ability'], $abilities)));
  } else {
    $job = new JOB(
      'New Job',
      100,
      array('HP' => 12.00, 'MP' => 6.00, 'Speed' => 8.00, 'Accuracy' => 8.00, 'Strength' => 8.00, 'Dodge' => 7.50, 'Block' => 8.00, 'Power' => 8.00, 'Resistance' => 8.00, 'Focus' => 8.00),
      array()
    );
  }
} catch (Exception $e) {
  # This jobid does not exist. Abort.
  header('Location: jobs.php');
  exit;
}

if (isset($_POST['OP']) && $_POST['OP'] == "Update Database") {
  //Get the ability count
  $ability_count = $_POST['ability_count'];
  $job_abilities = array();

  for ($count = 0; $count <= $ability_count; $count++)
    if (
      !isset($_POST["del_ability_$count"])
      && $_POST["ability_level_$count"] > 0
      && array_key_exists($_POST["ability_$count"], $abilities)
    )
      $job_abilities[] = array(
        'ability' => $_POST["ability_$count"],
        'level' => $_POST["ability_level_$count"]
      );

  //Make a new job
  $this_job = new JOB(
    $_POST['name'],
    $_POST['need'],
    array(
      'HP' => $_POST['HP'],
      'MP' => $_POST['MP'],
      'Speed' => $_POST['Speed'],
      'Accuracy' => $_POST['Accuracy'],
      'Strength' => $_POST['Strength'],
      'Dodge' => $_POST['Dodge'],
      'Block' => $_POST['Block'],
      'Power' => $_POST['Power'],
      'Resistance' => $_POST['Resistance'],
      'Focus' => $_POST['Focus']
    ),
    $job_abilities
  );

  // Update the job id.
  $this_job->jobid = $jobindex;
  // Set the object state.
  if ($jobindex == 0) {
    $this_job->state = ObjectState::ACTIVE;
  } else {
    $this_job->state = $job->state;
  }

  //update that job in the databse
  $this_job->save($db);
  $jobindex = $this_job->jobid;
  $job = $this_job;
}

//Setup HTML display variables.
$ability_list = [0 => ''];
$ability_desc = [];
foreach ($abilities as $ability) {
  $ability_list[$ability->abilityid] = "{$ability->name} ({$ability->mp_used})";
  $ability_desc[$ability->abilityid] = $ability->description;
}

//Default input box size
$def_size = array('size' => 7);
//Default input box size
$def_textarea = array('rows' => 3, 'cols' => 50);
?>
<script>
  abilities = <?php echo json_encode($ability_desc); ?>;

  function show_ability_description(object) {
    var ability = object.value;
    var desc_td = object.parentNode.parentNode.getElementsByTagName('td')[3];
    if (desc_td.childNodes.length > 0)
      desc_td.firstChild.nodeValue = abilities[ability];
    else
      desc_td.appendChild(document.createTextNode(abilities[ability]));
  }
</script>
<form method="post" action="edit_job.php">
  <table>
    <tr>
      <th>
        <table>
          <tr>
            <?php
            $tindex = $jobindex;            
            do {
              $tindex--;
            } while ($tindex > 0 && !array_key_exists($tindex, $jobs));
            if ($tindex > 0)
              echo "<td><a href=\"edit_job.php?job=$tindex\">Previous job</a></td>"
            ?>
            <td><a href="jobs.php">Return to job list</a></td>
            <?php
            echo "<td><a href=\"delete_job.php?job=$jobindex\">Delete this job</a></td>"
            ?>
            <td><a href="edit_job.php?job=0">New job</a></td>
            <?php
            $tindex = $jobindex;
            $last = max(array_keys($jobs));
            do {
              $tindex++;
            } while ($tindex <= $last && !array_key_exists($tindex, $jobs));
            if ($tindex <= $last)
              echo "<td><a href=\"edit_job.php?job=$tindex\">Next job</a></td>"
            ?>
          </tr>
        </table>
      </th>
    </tr>
    <tr>
      <th>
        <table>
          <tr>
            <th>Job Title</th>
            <td>
              <?php make_input('name', $job->name); ?>
              <?php make_input('job', $jobindex, array('type' => 'hidden')); ?>
            </td>
            <th>XP Need</th>
            <td>
              <?php make_input('need', $job->need, $def_size); ?>
            </td>
          </tr>
        </table>
      </th>
    </tr>
    <tr>
      <th>
        <table>
          <tr>
            <th>
              <table>
                <?php
                echo "<tr>\n";
                foreach ($GLOBALS['character_stats'] as $key)
                  echo "<th>$key</th>\n";
                echo "</tr>\n<tr>\n";
                foreach ($GLOBALS['character_stats'] as $key) {
                  $value = $job->upgrade[$key];
                  echo "<th>";
                  make_input($key, $job->upgrade[$key], $def_size);
                  echo "</th>\n";
                }
                echo "</tr>\n";
                ?>
              </table>
            </th>
          </tr>
          <tr>
            <th>
              <table>
                <tr>
                  <th>Ability</th>
                  <th>Level Earned</th>
                  <th>Delete?</th>
                  <th>Desc</th>
                </tr>
                <?php
                for ($count = 0; $count < count($job->abilities); $count++) {
                  echo "      <tr>\n        <td>";
                  make_select("ability_$count", $job->abilities[$count]['ability'], $ability_list, array('onchange' => 'show_ability_description(this);'));
                  echo "</td>\n        <td>";
                  make_input("ability_level_$count", $job->abilities[$count]['level'], $def_size);
                  echo "</td>\n        <td>";
                  make_checkbox("del_ability_$count", false);
                  echo "</td>\n        <td>";
                  echo $ability_desc[$job->abilities[$count]['ability']];
                  echo "</td>\n      </tr>\n";
                }
                ?>
                <tr>
                  <td><?php make_select("ability_$count", '', $ability_list, array('onchange' => 'show_ability_description(this);')); ?></td>
                  <td><?php make_input("ability_level_$count", 0, $def_size); ?></td>
                  <td><?php make_input("ability_count", count($job->abilities), array('type' => 'hidden')); ?></td>
                  <td></td>
                </tr>
              </table>
            </th>
          </tr>
          <tr>
            <th>
              <table>
                <tr>
                  <td><input type="submit" name="OP" value="Update Database" <?php echo $job->state == ObjectState::INACTIVE ? ' disabled' : ''; ?>></td>
                </tr>
              </table>
            </th>
          </tr>
          <tr>
            <th>
              <table>
                <tr>
                  <?php
                  $tindex = $jobindex;
                  do {
                    $tindex--;
                  } while ($tindex > 0 && !array_key_exists($tindex, $jobs));
                  if ($tindex > 0)
                    echo "<td><a href=\"edit_job.php?job=$tindex\">Previous job</a></td>";
                  ?>
                  <td><a href="jobs.php">Return to job list</a></td>
                  <td>
                    <?php
                    if ($job->state == ObjectState::ACTIVE) {
                      echo "<a href=\"delete_job.php?job=$jobindex\">Deactivate this job</a>";
                    }
                    ?>
                  </td>
                  <td><a href="edit_job.php?job=0">New job</a></td>
                  <?php
                  $tindex = $jobindex;
                  $keys = array_keys($jobs);
                  sort($keys);
                  end($keys);
                  $last = end($keys);
                  do {
                    $tindex++;
                  } while ($tindex <= $last && !array_key_exists($tindex, $jobs));
                  if ($tindex <= $last)
                    echo "<td><a href=\"edit_job.php?job=$tindex\">Next job</a></td>"
                  ?>
                </tr>
              </table>
            </th>
          </tr>
        </table>
</form>