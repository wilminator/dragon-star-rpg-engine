<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'RESET_DB', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

//Check for admin permissions
$reset_db = check_permission($userid, 'dse', 'RESET_DB');
if ($reset_db === false) {
    header("Location: ../");
    exit;
}


if (count($_FILES) > 0) {
    $filename = $_FILES['datafile']['tmp_name'];
    if (!is_uploaded_file($filename)) {
        echo 'Problem with file.';
        exit;
    }
    $data = null;
    $filedata = file_get_contents($filename);
    //var_dump($filedata);

    require_once INCLUDE_GAME . 'item.php';
    require_once INCLUDE_GAME . 'ability.php';
    require_once INCLUDE_GAME . 'personality.php';
    require_once INCLUDE_GAME . 'monster.php';
    require_once INCLUDE_GAME . 'job.php';
    require_once INCLUDE_GAME . 'character.php';
    require_once INCLUDE_GAME . 'group.php';
    require_once INCLUDE_GAME . 'party.php';
    require_once INCLUDE_GAME . 'fight.php';

    require_once INCLUDE_GAME . 'database.php';
    $db = get_database();

    $data = @unserialize($filedata);
    //var_dump($data);
    //exit;
    if (array_key_exists('items', $data)) {
        require_once INCLUDE_GAME . 'item.php';
        ITEM::ensure_table_exists($db, true);
        foreach ($data['items'] as $index => $item)
            if ($index != 0) {
                $item->save($db);
            }
        echo "Uploaded " . count($data['items']) . " items.<br>";
    }

    if (array_key_exists('abilities', $data)) {
        require_once INCLUDE_GAME . 'ability.php';
        ABILITY::ensure_table_exists($db, true);
        foreach ($data['abilities'] as $index => $ability) {
            $ability->save($db);
        }
        echo "Uploaded " . count($data['abilities']) . " abilities.<br>";
    }

    if (array_key_exists('jobs', $data)) {
        require_once INCLUDE_GAME . 'job.php';
        JOB::ensure_table_exists($db, true);
        foreach ($data['jobs'] as $index => $job) {
            $job->save($db);
        }
        echo "Uploaded " . count($data['jobs']) . " jobs.<br>";
    }
    if (array_key_exists('personalities', $data)) {
        require_once INCLUDE_GAME . 'personality.php';
        PERSONALITY::ensure_table_exists($db, true);
        foreach ($data['personalities'] as $index => $personality) {
            $personality->save($db);
        }
        echo "Uploaded " . count($data['personalities']) . " personalities.<br>";
    }

    if (array_key_exists('monsters', $data)) {
        require_once INCLUDE_GAME . 'monster.php';
        MONSTER::ensure_table_exists($db, true);
        foreach ($data['monsters'] as $index => $monster) {
            $monster->save($db);
        }
        echo "Uploaded " . count($data['monsters']) . " monsters.<br>";
    }

    if (array_key_exists('characters', $data)) {
        require_once INCLUDE_GAME . 'hero.php';
        HERO::ensure_table_exists($db, true);

        foreach ($data['characters'] as $index => $character) {
            HERO::save_character($db, $character);
        }
        echo "Uploaded " . count($data['characters']) . " characters.<br>";
    }

    if (array_key_exists('teams', $data)) {
        require_once INCLUDE_GAME . 'team.php';
        TEAM::ensure_table_exists($db, true);
        foreach ($data['teams'] as $index => $team) {
            $team->save($db);
        }
        echo "Uploaded " . count($data['teams']) . " teams.<br>";
    }

    if (array_key_exists('fights', $data)) {
        require_once INCLUDE_GAME.'fight.php';
        require_once INCLUDE_GAME.'fight_action.php';
        require_once INCLUDE_GAME.'fight_save.php';
        require_once INCLUDE_GAME.'fight_player_state.php';
        require_once INCLUDE_GAME.'fight_semaphore.php';
        FIGHT::ensure_table_exists($db, true);
        FightAction::ensure_table_exists($db, true);
        FightSave::ensure_table_exists($db, true);
        FightPlayerState::ensure_table_exists($db, true);
        FightSemaphore::ensure_table_exists($db, true);
        foreach ($data['fights'] as $index => $fight) {
            $fight->save($db);
        }
        echo "Uploaded " . count($data['fights']) . " fights.<br>";
        foreach ($data['fight_semaphores'] as $index => $fight_semaphore) {
            $fight_semaphore->save($db);
        }
        echo "Uploaded " . count($data['fight_semaphores']) . " fight semaphores.<br>";
        foreach ($data['fight_actions'] as $index => $fight_action) {
            $fight_action->save($db);
        }
        echo "Uploaded " . count($data['fight_actions']) . " fight actions.<br>";
        foreach ($data['fight_saves'] as $index => $fight_save) {
            $fight_save->save($db);
        }
        echo "Uploaded " . count($data['fight_saves']) . " fight saves.<br>";
    }

    echo "<a href=\"./\">Return to the previous menu.</a>";
    exit;
}
?>
<table>
    <tr>
        <th>
            <form enctype="multipart/form-data" method="post">
                <table>
                    <caption><b>Select the data to import:</b></caption>
                    <tr>
                        <td><input name="datafile" type="file"></td>
                    </tr>
                </table>
                <input type="submit" value="Import the selected file">
            </form>
            <p><a href="./">Return to the previous menu.</a></p>
        </th>
    </tr>
</table>