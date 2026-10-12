<?php
define('INCLUDE_GAME', 'include/');

#Do LOGIN stuff :)
require_once INCLUDE_GAME . 'auth.php';

// userid is only ever the logged-in player. Watching a fight that
// isn't yours (live or replay) goes through the spectator calls, which
// act as no one.
if (is_logged_in()) {
    $GLOBALS['userid'] = get_logged_in_userid();
    //If on hold then bail.
    if (is_on_hold($GLOBALS['userid'])) exit;
    // The page's token, sent as a header: another site can't make calls
    // as this player (see dispatch_rpc_call()).
    require_once INCLUDE_GAME . 'csrf.php';
    $GLOBALS['rpc_csrf_valid'] = csrf_header_valid();
} else {
    // Not logged in: allowed through only so public spectators can
    // watch. dispatch_rpc_call() accepts nothing but the read-only
    // spectator calls from an anonymous session.
    $GLOBALS['userid'] = 0;
    $GLOBALS['is_anonymous'] = true;
}

ini_set('display_errors', '0');
require_once INCLUDE_GAME . 'errorlog.php';

#Globals
require_once INCLUDE_GAME . 'paths.php';
#Prep the game objects.
require_once INCLUDE_GAME . 'fight.php';

//Load proxy library
require_once INCLUDE_GAME . 'proxy.php';

try {
    $raw_post_data = file_get_contents('php://input');

    // Kept for the debug log's request tracing (see write_responses()).
    $GLOBALS['__proxy_request'] = $raw_post_data;

    //Here we respond to code from the client.
    if ($raw_post_data != "") {        
        process_data($raw_post_data);
    }

    //Here we add our code to initiate client activity unsolicited.

    //Now we transmit our responses.
    write_responses();

    // Old rows cleared out once the response has gone (see Housekeeping).
    require_once INCLUDE_GAME . 'housekeeping.php';
    Housekeeping::after_response(get_database());
} catch (Exception $e) {
    log_error($e);
}
