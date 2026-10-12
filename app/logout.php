<?php
define('INCLUDE_GAME', 'include/');
require_once INCLUDE_GAME . 'auth.php';

log_out();
header('Location: ' . AUTH_LOGIN);
