<?php
define('AQ_ROOT', dirname(__DIR__));
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/defaults.php';
$aqUser = [];
if (is_file(AQ_ROOT . '/config.php')) {
    $loaded = require AQ_ROOT . '/config.php';
    if (is_array($loaded)) $aqUser = $loaded;
}
$GLOBALS['AQ_CFG'] = array_replace_recursive(aq_defaults(), $aqUser);

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/analysis.php';
require __DIR__ . '/ingest.php';
require __DIR__ . '/firebase.php';
require __DIR__ . '/gemini.php';
require __DIR__ . '/runner.php';
require __DIR__ . '/icons.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/data.php';
require __DIR__ . '/viz.php';
require __DIR__ . '/migrate.php';
require __DIR__ . '/layout.php';

if (!defined('AQ_NOSESSION') && session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    @ini_set('session.gc_maxlifetime', (string)(30 * 86400));
    session_name('aquify');
    session_start();
}

if (!defined('AQ_NOSESSION')) aq_migrate();
