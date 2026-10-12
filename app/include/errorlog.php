<?php
// Error logging for the whole site. Everything goes to the site log
// (GameLog, reviewed on admin/logs.php), scrubbed of secrets first:
//
//   log_error($message)  -- the site's own messages. Those starting
//                          "HACK" go to the security log, the rest to
//                          server errors.
//   log_debug($channel, $message) -- routine tracing: the debug log,
//                          by channel, each off unless an admin switches
//                          it on.
//   PHP's own errors, warnings and notices, and uncaught exceptions --
//                          server errors, caught by the handlers below.
//
// Stack traces are kept as file, line and function only: never the
// arguments, which is where passwords and the like would show up.
//
// If the database can't take an entry, it goes to DEFAULT_ERROR_LOG
// instead (see GameLog).

define('DEFAULT_ERROR_LOG', 'errorlog.txt');

// $unused: an old severity number some callers still pass.
// $category: to choose the log yourself; normally worked out from the
// message.
function log_error($message, $unused = null, ?string $category = null): void {
    require_once INCLUDE_GAME . 'game_log.php';
    if ($message instanceof \Throwable) {
        $text = get_class($message) . ': ' . $message->getMessage();
        $level = 'error';
        $kind = 'exception';
        $location = $message->getFile() . ':' . $message->getLine();
        $details = ['trace' => log_trace($message->getTrace())];
    } else {
        $text = is_scalar($message) || $message === null ? (string) $message : (string) json_encode($message);
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $location = isset($caller['file']) ? $caller['file'] . ':' . $caller['line'] : null;
        $level = 'notice';
        $kind = 'log_error';
        $details = null;
    }
    if ($category === null) {
        $category = preg_match('/^\s*HACK\b/i', $text) ? 'security' : 'server_error';
    }
    if ($category === 'security') {
        $level = 'warning';
        $kind = 'refused_request';
    }
    GameLog::write($category, $level, $kind, $text, $details, ['location' => $location]);
}

// Routine tracing, for following what the game is doing while
// investigating something: the debug log, in channels
// (GameLog::DEBUG_CHANNELS -- 'fight', 'ai', 'xp', ...). A channel
// records nothing unless an admin switches it on (admin/logs.php), and
// they're all off by default. $details: extra data, kept as JSON and
// scrubbed like everything else -- pass a function to build it only when
// the channel is on, for anything costly (dumping AI tables, say).
function log_debug(string $channel, string $message, $details = null): void {
    require_once INCLUDE_GAME . 'game_log.php';
    if (!GameLog::debug_enabled($channel)) {
        return;
    }
    if ($details instanceof \Closure) {
        $details = $details();
    }
    $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    GameLog::write('debug', 'debug', $channel, $message, $details,
        ['location' => isset($caller['file']) ? $caller['file'] . ':' . $caller['line'] : null]);
}

// A trace as "file:line function" lines, without argument values.
function log_trace(array $trace): array {
    return array_map(fn($frame) => ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?') . ' '
        . (isset($frame['class']) ? $frame['class'] . $frame['type'] : '') . ($frame['function'] ?? ''), array_slice($trace, 0, 30));
}

function handleError($errno, $errstr, $errfile, $errline): bool {
    if (!($errno & error_reporting())) {
        return false;
    }
    [$level, $name] = match (true) {
        (bool) ($errno & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => ['warning', 'php_warning'],
        (bool) ($errno & (E_NOTICE | E_USER_NOTICE)) => ['notice', 'php_notice'],
        (bool) ($errno & (E_DEPRECATED | E_USER_DEPRECATED)) => ['notice', 'php_deprecated'],
        default => ['error', 'php_error'],
    };
    require_once INCLUDE_GAME . 'game_log.php';
    GameLog::write('server_error', $level, $name, (string) $errstr,
        ['trace' => log_trace(array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1))],
        ['location' => "$errfile:$errline"]);
    // Handled: nothing more for PHP to do (as before -- warnings aren't
    // printed into pages).
    return true;
}

function handleUncaught(\Throwable $e): void {
    log_error($e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo ini_get('display_errors') ? htmlspecialchars(get_class($e) . ': ' . $e->getMessage()) : 'Something went wrong.';
}

set_error_handler('handleError');
set_exception_handler('handleUncaught');
