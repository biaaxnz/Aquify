<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!current_user()) json_out(['error' => 'Belum masuk.'], 401);
$kind = ($_GET['kind'] ?? '') === 'water' ? 'water' : 'geo';
$pid = (int)($_GET['point'] ?? 0);
if ($pid && !owned_point($pid)) json_out(['error' => 'Titik tidak ditemukan.'], 404);
session_write_close();
// Halaman live terbuka -> tarik data terbaru dari Firebase (dibatasi, dan dikunci agar tidak tumpang tindih).
if (fb_configured()) {
    $min = max(5, (int)cfg('sync.live_interval_sec', 8));
    $last = state_get('last_sync_at');
    if (!$last || time() - strtotime($last) >= $min) { @set_time_limit(40); try { fb_sync('live'); } catch (Throwable $e) {} }
}
try {
    json_out($kind === 'water' ? aq_live_water($pid) : aq_live_geo($pid));
} catch (Throwable $e) {
    json_out(['error' => 'Data tidak dapat dibaca.'], 500);
}
