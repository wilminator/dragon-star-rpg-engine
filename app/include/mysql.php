<?php
require_once INCLUDE_GAME . 'errorlog.php';

$GLOBALS['__MYSQL_CONNECTION'] = null;

function mysql_do_connect() {
    if (is_null($GLOBALS['__MYSQL_CONNECTION'])) {
        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'dse';
        $pass = getenv('DB_PASSWORD') ?: 'change-me';
        $db = getenv('DB_DATABASE') ?: 'dse';
        $GLOBALS['__MYSQL_CONNECTION'] = mysqli_connect($host, $user, $pass);
        if ($GLOBALS['__MYSQL_CONNECTION'] === false)
            log_error("The server at $host could not be accessed with the username $user.");
        if (mysqli_select_db($GLOBALS['__MYSQL_CONNECTION'], $db) === false)
            log_error("The database $db could not be selected as default.");
    }
}

function mysql_do_fetch_row($resource) {
    $result = mysqli_fetch_row($resource);
    return $result === null ? false : $result;
}

function mysql_do_fetch_assoc($resource) {
    $result = mysqli_fetch_assoc($resource);
    return $result === null ? false : $result;
}

function mysql_do_num_rows($resource) {
    return mysqli_num_rows($resource);
}

function mysql_do_escape_string($resource) {
    return mysqli_real_escape_string($GLOBALS['__MYSQL_CONNECTION'], $resource);
}

function mysql_cleanup($conn, $error_type, $data) {
    mysqli_close($conn);
}

function mysql_do_query(string $query, bool $bail = true) {
    mysql_do_connect();
    //$result=mysqli_query($query,$GLOBALS['__MYSQL_CONNECTION']);
    $result = mysqli_query($GLOBALS['__MYSQL_CONNECTION'], $query);
    if ($result === false)
        if ($bail)
            log_error(mysql_do_error());
        else
            log_error(mysql_do_error());
    return $result;
}

function mysql_do_insert_id(): int {
    return mysqli_insert_id($GLOBALS['__MYSQL_CONNECTION']);
}

function mysql_do_affected_rows(): int {
    return mysqli_affected_rows($GLOBALS['__MYSQL_CONNECTION']);
}

function mysql_do_error(): string {
    return mysqli_error($GLOBALS['__MYSQL_CONNECTION']);
}

function mysql_do_ensure_table_exists(string $validate_sql, string $drop_sql, string $create_sql, bool $reset = false): bool {
    # Returns true if the table is recreated and false otherwise.
    try {
        $result = mysql_do_query($validate_sql, false);
    } catch (\Throwable $e) {
        $result = false;
    }
    if ($result === false || ($data = mysql_do_fetch_row($result)) === false || $reset === true) {
        //Delete table
        mysql_do_query($drop_sql);
        //Recreate table.
        mysql_do_query($create_sql);
        return true;
    }
    return false;
}
