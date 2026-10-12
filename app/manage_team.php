<?php
#Prep the game objects.
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'errorlog.php';
#Make personalities
require_once INCLUDE_GAME . 'team.php';
require_once INCLUDE_GAME . 'paths.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'group.php';

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

#Do LOGIN stuff :)
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_membership_access('dse', AUTH_LOGIN, 'index.php');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../index.php');

$jobs = JOB::load_all_from_cache();
$active_jobs = array_filter($jobs, fn($job) => $job->state === ObjectState::ACTIVE);

#Job list
$job_list = [];
foreach ($active_jobs as $index => $job)
    $job_list[$index] = $job->name;
asort($job_list);

#Personality List (active only)
$personalities = array_filter(PERSONALITY::load_all_from_cache(), fn($x) => $x->state === ObjectState::ACTIVE);
$personality_list = [];
$personality_pic = [];
foreach ($personalities as $index => $personality) {
    $personality_list[$index] = $personality->name;
    $personality_pic[$index] = FIGHTER_IMAGES_DIR . $personality->base->images[0];
}
asort($personality_list);

$team_size_options = array();
for ($index = 1; $index <= TEAM::MAX_COUNT; $index++)
    $team_size_options[$index] = $index;

// Making a new team. Managing an existing team's heroes (a new job or
// look) is the hub's Team dialog (app.php).
$db = get_database();

$team_name = $_POST['team_name'] ?? '';
$team_size = (int) ($_POST['team_size'] ?? 4);
$qty_attr = ['id' => 'team_size', 'onchange' => 'show_hero_qty()'];

$message = '';

#Check for responses
if (isset($_POST['GO_BACK'])) {
    header("Location: pick_team.php");
    exit;
}

if (isset($_POST['MAKE_TEAM'])) {
    # sanitize input
    if (isset($_POST['team_size']) && is_numeric($_POST['team_size']) && $_POST['team_size'] >= 1 && $_POST['team_size'] <= TEAM::MAX_COUNT) {
        $dirty = false;
        $team_size = (int)$_POST['team_size'];
        for ($count = 0; $count < $team_size; $count++) {
            $jobid = $_POST["job$count"] ?? '';
            $personalityid = $_POST["personality$count"] ?? '';
            if (!is_numeric($jobid) || !array_key_exists($jobid, $active_jobs)) {
                $dirty = true;
                $message .= "Bad jobid $count " . htmlspecialchars((string) $jobid) . "<br>";
            }
            if (!is_numeric($personalityid) || !array_key_exists($personalityid, $personalities)) {
                $dirty = true;
                $message .= "Bad personalityid $count " . htmlspecialchars((string) $personalityid) . "<br>";
            }
        }
        if (!$dirty) {
            $character_specs = [];
            for ($count = 0; $count < $team_size; $count++) {
                $character_specs[] = [
                    'name' => $_POST["name$count"],
                    'jobid' => $_POST["job$count"],
                    'personalityid' => $_POST["personality$count"],
                ];
            }
            TEAM::create($db, $userid, $team_name, $character_specs);
            header("Location: pick_team.php");
            exit;
        } else {
            $message .= 'Data is dirty. Cannot make team.';
        }
    }
}

$title = "Choose Your Heroes";
$header = "Choose your new team members";

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title><?= $title ?></title>

    <script>
        personalities = <?= json_encode($personality_pic) ?>;

        function show_personality(object, id) {
            var ability = object.value;
            var pic = document.getElementById(id);
            pic.src = personalities[ability];
        }

        function show_hero_qty() {
            var node = document.getElementById('team_size');
            var qty = node.value;
            for (index = 0; index < <?= TEAM::MAX_COUNT ?>; index++) {
                var hero = document.getElementById('hero' + index);
                if (hero) {
                    hero.style.display = index < qty ? 'table-row' : 'none';
                }
            }
        }

        function onload() {
            show_hero_qty();
            document.form_data.team_name.focus();
        }

        window.addEventListener("load", onload);
    </script>
</head>

<body>
    <h1><?= $header ?></h1>
    <?= $message ? "<div style=\"color: red;\">{$message}</div>" : '' ?>
    <form name="form_data" method="post">
        <table>
            <tr>
                <th><label for="team_name">Team Name</label></th>
                <td><?php make_input("team_name", $team_name, ['id' => 'team_name']); ?></td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
            <tr>
                <th><label for="team_size">Team Size</label></th>
                <td><?php make_select('team_size', $team_size, $team_size_options, $qty_attr); ?></td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
            <tr>
                <th>&nbsp;</th>
                <th>Hero Name</th>
                <th>Hero Job</th>
                <th>Hero Looks Like</th>
                <td>&nbsp;</td>
            </tr>
            <?php
            $first_jobid = array_key_first($job_list);
            $first_personalityid = array_key_first($personality_list);
            for ($count = 0; $count < TEAM::MAX_COUNT; $count++) {
                $ordinal = $count + 1;
                $personality_attr = ["onkeyup" => "show_personality(this,'pic$count');", "onchange" => "show_personality(this,'pic$count');"];
            ?>
                <tr id="hero<?= $count ?>">
                    <th>Hero <?= $ordinal ?></th>
                    <td><?php make_input("name$count", '', ['aria-label' => "Hero $ordinal name"]); ?></td>
                    <td><?php make_select("job$count", $first_jobid, $job_list, ['aria-label' => "Hero $ordinal job"]); ?></td>
                    <td><?php make_select("personality$count", $first_personalityid, $personality_list, $personality_attr + ['aria-label' => "Hero $ordinal looks like"]); ?></td>
                    <td>
                        <?php /* alt="": a preview of the look the list beside it names. */ ?>
                        <img id="pic<?= $count ?>" src="<?= $personality_pic[$first_personalityid] ?>" alt="">
                    </td>
                </tr>
            <?php
            }
            ?>
            <tr>
                <td colspan="2" align="center"><input type="submit" name="MAKE_TEAM" value="Make them into a team!"></td>
            </tr>
            <tr>
                <td colspan="2" align="center"><input type="submit" name="GO_BACK" value="Return to the previous screen"></td>
            </tr>
        </table>
</body>

</html>