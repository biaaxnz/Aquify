<?php
// Sinkron Firebase -> MySQL. Dua cara akses: sesi login + header X-CSRF (browser), atau ?token=<sync.token> (cron).
require dirname(__DIR__) . '/includes/bootstrap.php';

$tok = (string)cfg('sync.token', '');
$viaToken = $tok !== '' && isset($_GET['token']) && hash_equals($tok, (string)$_GET['token']);
if (!$viaToken) {
    if (!current_user()) json_out(['error' => 'Belum masuk.'], 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) json_out(['error' => 'CSRF tidak valid.'], 400);
}
session_write_close();

if (!$viaToken) {
    // Batasi sinkron otomatis browser agar tidak berlebihan
    $last = state_get('last_sync_at');
    $min = max(20, (int)cfg('sync.auto_interval_sec', 60) - 5);
    if ($last && time() - strtotime($last) < $min) json_out(['skipped' => true, 'inserted' => 0, 'error' => null]);
}
@set_time_limit(60);
json_out(fb_sync($viaToken ? 'cron' : 'auto'));
