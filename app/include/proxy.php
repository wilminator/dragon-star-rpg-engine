<?php
require_once INCLUDE_GAME.'errorlog.php';
require_once INCLUDE_GAME.'game_log.php';

// Initialize the proxy response queue
$GLOBALS['__proxy_values']=array();

// Every RPC method the client is allowed to call, and nothing else --
// replaces a previous dispatch that resolved $value[0] dynamically
// (include_once "php_js/{$value[0]}.php"; then
// call_user_func_array($value[0], $value[1])) using the client's own
// JSON payload as the function name. That design would call ANY
// defined function by name with client-controlled arguments the
// moment it happened to exist -- not just the intended php_js/
// handlers, but PHP's own built-ins too (system, exec, unlink, and so
// on), since function_exists() and call_user_func_array() have no
// concept of "only these specific functions are meant to be reachable
// this way." A client sending ["system", ["id"]] would have executed
// it. This is what actually closes that off: $method is matched
// against a fixed, explicit list, never used to build a path or a
// callable name, so nothing outside this list can ever be reached
// through here regardless of what a client sends.
//
// The only calls an anonymous (not logged in) visitor may make: the
// read-only spectator calls, which re-check access on every request
// (see php_js/spectator_common.php). Everything else is refused for
// them here, before any handler is loaded.
const SPECTATOR_RPC_METHODS = ['request_spectator_data', 'poll_spectator_data', 'request_spectator_resource_manifest'];
// Also open to anonymous spectators: reporting problems with the fight
// they're watching, which Anomaly only stores for a real fight, and
// limits per fight.
const ANONYMOUS_REPORT_METHODS = ['client_error', 'report_anomalies'];

// The calls a logged-in session may make without the page's CSRF token:
// the ones anyone may make. (The overworld's map reads, fetch_map_data
// and fetch_map_size, are off the list altogether until maps return:
// they built file paths from what the client sent.) Every other call changes
// something or reads a player's own data, so it needs the token
// (processor.php checks it and sets $GLOBALS['rpc_csrf_valid']).
const CSRF_EXEMPT_RPC_METHODS = ['request_spectator_data', 'poll_spectator_data', 'request_spectator_resource_manifest',
    'client_error', 'report_anomalies'];

function dispatch_rpc_call(string $method, array $args): void {
    if (($GLOBALS['is_anonymous'] ?? false)
        && !in_array($method, SPECTATOR_RPC_METHODS, true) && !in_array($method, ANONYMOUS_REPORT_METHODS, true)) {
        log_error("HACK:\nAnonymous session attempted $method");
        return;
    }
    if (!($GLOBALS['is_anonymous'] ?? false) && !($GLOBALS['rpc_csrf_valid'] ?? false)
        && !in_array($method, CSRF_EXEMPT_RPC_METHODS, true)) {
        log_error("Refused $method: missing or wrong CSRF token", null, 'security');
        return;
    }
    // Named in any log entry this call makes (see GameLog).
    $GLOBALS['current_rpc_method'] = $method;
    switch ($method) {
        case 'request_spectator_data':
            require_once 'php_js/request_spectator_data.php';
            request_spectator_data(...$args);
            break;

        case 'poll_spectator_data':
            require_once 'php_js/poll_spectator_data.php';
            poll_spectator_data(...$args);
            break;

        case 'request_spectator_resource_manifest':
            require_once 'php_js/request_spectator_resource_manifest.php';
            request_spectator_resource_manifest(...$args);
            break;

        case 'request_fight_data':
            require_once 'php_js/request_fight_data.php';
            request_fight_data(...$args);
            break;

        case 'request_resource_manifest':
            require_once 'php_js/request_resource_manifest.php';
            request_resource_manifest(...$args);
            break;

        case 'change_character_command':
            require_once 'php_js/change_character_command.php';
            change_character_command(...$args);
            break;

        case 'process_fight_commands':
            require_once 'php_js/process_fight_commands.php';
            process_fight_commands(...$args);
            break;

        case 'check_fight_timeout':
            require_once 'php_js/check_fight_timeout.php';
            check_fight_timeout(...$args);
            break;

        case 'ack_playback':
            require_once 'php_js/ack_playback.php';
            ack_playback(...$args);
            break;

        case 'character_sheet':
            require_once 'php_js/character_sheet.php';
            character_sheet(...$args);
            break;

        case 'preview_equipment':
            require_once 'php_js/preview_equipment.php';
            preview_equipment(...$args);
            break;

        case 'hub_state':
            require_once 'php_js/hub_state.php';
            hub_state(...$args);
            break;

        case 'save_settings':
            require_once 'php_js/save_settings.php';
            save_settings(...$args);
            break;

        case 'move_hero':
            require_once 'php_js/move_hero.php';
            move_hero(...$args);
            break;

        case 'rest_at_inn':
            require_once 'php_js/rest_at_inn.php';
            rest_at_inn(...$args);
            break;

        case 'change_equipment':
            require_once 'php_js/change_equipment.php';
            change_equipment(...$args);
            break;

        case 'field_options':
            require_once 'php_js/field_options.php';
            field_options(...$args);
            break;

        case 'use_thing':
            require_once 'php_js/use_thing.php';
            use_thing(...$args);
            break;

        case 'store_open':
            require_once 'php_js/store_open.php';
            store_open(...$args);
            break;

        case 'store_goods':
            require_once 'php_js/store_goods.php';
            store_goods(...$args);
            break;

        case 'store_buy':
            require_once 'php_js/store_buy.php';
            store_buy(...$args);
            break;

        case 'store_sell':
            require_once 'php_js/store_sell.php';
            store_sell(...$args);
            break;

        case 'manage_options':
            require_once 'php_js/manage_options.php';
            manage_options(...$args);
            break;

        case 'change_hero':
            require_once 'php_js/change_hero.php';
            change_hero(...$args);
            break;

        case 'alliance_view':
            require_once 'php_js/alliance_view.php';
            alliance_view(...$args);
            break;

        case 'alliance_act':
            require_once 'php_js/alliance_act.php';
            alliance_act(...$args);
            break;

        case 'client_error':
            require_once 'php_js/client_error.php';
            client_error(...$args);
            break;

        case 'report_anomalies':
            // Pure telemetry, no game-state mutation -- a spectator
            // can hit a failed load too, and that's worth knowing about
            // the same as anyone's (see ANONYMOUS_REPORT_METHODS).
            require_once 'php_js/report_anomalies.php';
            report_anomalies(...$args);
            break;

        default:
            log_error("Rejected unknown RPC method: " . var_export($method, true), null, 'security');
    }
}

function process_data($data)
    {
    // A batch is a JSON list of [method, params] pairs. Anything else
    // didn't come from the game's own client.
    $stuff = json_decode((string) $data);
    if (!is_array($stuff)) {
        log_error('Malformed RPC batch: ' . mb_strimwidth((string) $data, 0, 200, '...'), null, 'security');
        return;
    }
    //It should be an array.  Itereate through each.
    foreach($stuff as $value)
        {
        // $value[0] is never used as a path or a callable name --
        // dispatch_rpc_call()'s own fixed case list above is what
        // decides what can run, not this string. See its own comment
        // for why that distinction matters.
        unset($GLOBALS['current_rpc_method']);
        if (!is_array($value) || !isset($value[0]) || !is_string($value[0])) {
            log_error('Malformed RPC call in a batch', null, 'security');
            continue;
        }
        dispatch_rpc_call($value[0], (array) ($value[1] ?? []));
        }
    }

function queue_response()
    {
    //Ensure that there is at least an empty array to store into.
    if(!array_key_exists('__proxy_values',$GLOBALS))
        $GLOBALS['__proxy_values']=array();
    //Store all arguments as an array for transmission.
    $GLOBALS['__proxy_values'][]=func_get_args();
    }

function write_responses()
    {
    $data = $GLOBALS['__proxy_values'];
    //Clear the data buffer.
    $GLOBALS['__proxy_values']=[];
    //Translate from php to js    
    $output=json_encode($data);
    //Output the js.
    echo $output;
    // Request and response tracing, when the debug log's rpc channel is
    // on (off by default). Scrubbed like everything else.
    if (count($data) > 0 || ($GLOBALS['__proxy_request'] ?? '') !== '') {
        unset($GLOBALS['current_rpc_method']);
        log_debug('rpc', 'RPC request and response',
            fn() => ['request' => json_decode($GLOBALS['__proxy_request'] ?? 'null', true), 'response' => $data]);
    }
    }
?>
