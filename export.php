<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$wid = (int)($_GET['water'] ?? 0);
if ($wid) {
    $r = q_one('SELECT p.code, w.tds_ppm, w.ec_us, w.ph, w.temp_c, w.turbidity_ntu, w.measured_at, w.device_id, w.source, w.notes FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.id=? AND w.owner_id=?', [$wid, current_user_id()]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aquify-kualitas-air-' . $wid . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['titik', 'tds_ppm', 'ec_us_cm', 'ph', 'suhu_c', 'kekeruhan_ntu', 'waktu', 'perangkat', 'sumber', 'catatan'], ',', '"', '\\');
    if ($r) { foreach (['code', 'device_id', 'notes'] as $k) if (isset($r[$k]) && preg_match('/^[=+\-@]/', (string)$r[$k])) $r[$k] = "'" . $r[$k]; fputcsv($out, array_values($r), ',', '"', '\\'); }
    fclose($out); exit;
}
$pid = (int)($_GET['point'] ?? 0);
if ($pid && !owned_point($pid)) { http_response_code(404); exit('Titik tidak ditemukan.'); }
$sql = 'SELECT p.code, m.config, m.spacing_m, m.mn_m, m.dv_mv, m.i_ma, m.rho_a, m.measured_at, m.device_id, m.source
        FROM measurements m JOIN points p ON p.id=m.point_id WHERE p.owner_id=?' . ($pid ? ' AND m.point_id=?' : '') . ' ORDER BY p.code, m.spacing_m, m.measured_at';
$rows = q_all($sql, $pid ? [current_user_id(), $pid] : [current_user_id()]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="aquify-pengukuran' . ($pid ? '-' . $pid : '') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['titik', 'konfigurasi', 'jarak_m', 'mn_m', 'dv_mv', 'i_ma', 'rho_a_ohm_m', 'waktu', 'perangkat', 'sumber'], ',', '"', '\\');
foreach ($rows as $r) {
    // cegah injeksi rumus spreadsheet pada teks
    foreach (['code', 'device_id'] as $k) if (isset($r[$k]) && preg_match('/^[=+\-@]/', (string)$r[$k])) $r[$k] = "'" . $r[$k];
    fputcsv($out, array_values($r), ',', '"', '\\');
}
fclose($out);
