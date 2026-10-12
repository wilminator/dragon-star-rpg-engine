<?php
require_once 'php_js/anomaly_common.php';

// A data or logic problem the client caught as it happened (a party
// that doesn't exist, an unknown action): $message says what, $data is
// the offending data, $fight_guid the fight a spectator is watching.
// Stored for review on admin/logs.php -- see Anomaly.
function client_error($message = '', $data = null, $fight_guid = null)
    {
    [$userid, $fightid, $ip] = anomaly_reporter($fight_guid);
    try {
        $stored = Anomaly::record_client_error(get_database(), $message, $data, $userid, $fightid, $ip);
    } catch (\Throwable $e) {
        log_error("Client: $userid\nMessage (not stored: {$e->getMessage()}): $message\n" . json_encode($data), 100);
        $stored = false;
    }
    if ($stored) {
        anomaly_after_storing($fightid);
    }
    }
?>
