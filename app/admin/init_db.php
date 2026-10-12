<?php
#Do LOGIN stuff :)
define('INCLUDE_GAME', '../include/');
require_once INCLUDE_GAME . 'auth.php';
$userid = authenticate_permission_access('dse', 'RESET_DB', AUTH_LOGIN, '../');
redirect_on_hold($userid, 'dse', 'on_hold.php', '../../index.php');

require_once INCLUDE_GAME . 'database.php';

require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'monster.php';
require_once INCLUDE_GAME . 'ability.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'item.php';



if (isset($_GET['confirm'])) {
    $db = get_database();
    switch ($_GET['confirm']) {
        case "YES":
        case "FILE":
            //delete all tables in the databse
            echo 'Recreating items table.<br>';
            ITEM::ensure_table_exists($db, true);
            //Add just the items if need be
            if ($_GET['confirm'] == "FILE") {
                echo 'Importing old items into namespace.<br>';
                echo 'Adding old items to table.<br>';
                foreach (ITEM::load_all_from_cache() as $index => $item)
                    if ($index != 0) {
                        $item->save($db);
                        //echo "Added item $index<br>";
                    }
            }
            echo 'Done with items table.<br>';
            //echo "Init'd items.<br>";
            $items = ITEM::load_all_from_database($db);
            echo 'Recreating abilities table.<br>';
            ABILITY::ensure_table_exists($db, true);
            //echo "Init'd abilities.<br>";
            echo 'Recreating jobs table.<br>';
            JOB::ensure_table_exists($db, true);
            echo 'Recreating personalities table.<br>';
            PERSONALITY::ensure_table_exists($db, true);
            //echo "Init'd jobs.<br>";
            echo 'Recreating monsters table.<br>';
            MONSTER::ensure_table_exists($db, true);
            //echo "Init'd monsters.<br>";

            //Add everything else if need be.
            if ($_GET['confirm'] == "FILE") {
                echo 'Adding old abilities to table.<br>';
                foreach (ABILITY::load_all_from_cache() as $index => $ability) {
                    if (! in_array($ability->skill_effect_type, array('close', 'throw', 'shoot', 'none'))) $ability->skill_effect_type = 'none';
                    $ability->save($db);
                    //echo "Added ability $index<br>";
                }

                echo 'Importing old jobs into namespace.<br>';
                echo 'Adding old jobs to table.<br>';
                foreach (JOB::load_all_from_cache() as $index => $job) {
                    $job->save($db);
                    //echo "Added job $index<br>";
                }

                echo 'Importing old personalities into namespace.<br>';
                echo 'Adding old personalities to table.<br>';
                foreach (PERSONALITY::load_all_from_cache() as $index => $personality) {
                    $personality->save($db);
                    //echo "Added job $index<br>";
                }

                echo 'Importing old monsters into namespace.<br>';
                echo 'Adding old monsters to table.<br>';
                foreach (MONSTER::load_all_from_cache() as $index => $monster) {
                    $monster->save($db);
                    //echo "Added monster $index<br>";
                }
            }
            echo 'Done.<br>';
            echo "<a href=\"./\">Return to the previous menu.</a>";
            exit;
    }
}
?>
<table>
    <tr>
        <th>
            <table>
                <caption><b>Are you sure you want to reinitialize the database?</b></caption>
                <tr>
                    <td><a href="init_db.php?confirm=YES">Yes, start all over.</a></td>
                    <td><a href="init_db.php?confirm=FILE">Yes, but use the existing variable files.</a></td>
                    <td><a href="./">Do not touch a thing!</a></td>
                </tr>
            </table>
        </th>
    </tr>
</table>