<?php
require_once 'php_js/anomaly_common.php';

// Quality telemetry the client gathers and sends in batches (see
// combat.js's report_load_anomalies(): images or sounds that failed to
// load). Each entry is stored for review on admin/logs.php -- see
// Anomaly. $fight_guid: the fight a spectator is watching (players'
// own fights are known already). The answer says how many were kept.
function report_anomalies($anomalies = [], $fight_guid = null)
    {
    [$userid, $fightid, $ip] = anomaly_reporter($fight_guid);
    try {
        $kept = Anomaly::record_reports(get_database(), is_array($anomalies) ? $anomalies : [$anomalies], $userid, $fightid, $ip);
    } catch (\Throwable $e) {
        log_error("Client: $userid\nAnomalies (not stored: {$e->getMessage()}): " . json_encode($anomalies), 100);
        $kept = 0;
    }
    if ($kept > 0) {
        anomaly_after_storing($fightid);
    }
    queue_response('anomalies_received', $kept);
    }
?>
