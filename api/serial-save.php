<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error'=>'Metode tidak diizinkan.'],405);
if (!current_user()) json_out(['error'=>'Sesi login berakhir. Silakan login ulang.'],401);
if (!csrf_ok()) json_out(['error'=>'Token keamanan tidak valid. Muat ulang halaman.'],400);
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['reading']) || !is_array($body['reading'])) json_out(['error'=>'Payload JSON tidak valid.'],400);
$r = $body['reading'];
$rawJson = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($rawJson === false || strlen($rawJson) > 50000) json_out(['error'=>'Data terlalu besar atau JSON tidak valid.'],413);
$type = strtolower((string)($r['type'] ?? 'other'));
$type = in_array($type, ['water','geoelectric'], true) ? $type : 'other';
$pointCode = mb_substr(trim((string)($body['point'] ?? $r['point'] ?? '')),0,40);
$device = mb_substr(trim((string)($r['device_id'] ?? $r['device'] ?? '')),0,100);
$timestamp = $r['ts'] ?? $r['timestamp'] ?? $r['time'] ?? null;
$measuredAt = parse_ts($timestamp);
$number = static function($keys) use ($r) { foreach ($keys as $key) if (isset($r[$key]) && is_numeric($r[$key]) && is_finite((float)$r[$key])) return (float)$r[$key]; return null; };
$pointId = $pointCode !== '' ? resolve_point($pointCode, true) : null;
try {
  pdo()->beginTransaction();
  q('INSERT INTO sensor_readings(reading_uuid,device_id,point_id,reading_type,latitude,longitude,voltage_mv,current_ma,electrode_spacing_m,mn_m,k_factor_m,apparent_resistivity_ohm_m,ph,tds_ppm,ec_us,temp_c,turbidity_ntu,measured_at,source,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,\'esp32_serial\',?)', [
    bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)).'-4'.substr(bin2hex(random_bytes(2)),1,3).'-a'.substr(bin2hex(random_bytes(2)),1,3).'-'.bin2hex(random_bytes(6)),
    $device ?: null,$pointId,$type,$number(['latitude','lat']),$number(['longitude','lng','lon']),$number(['voltage_mv','dv_mv']),$number(['current_ma','i_ma']),$number(['electrode_spacing_m','spacing_m','spacing','a']),$number(['mn_m','mn']),$number(['k_factor_m','k']),$number(['apparent_resistivity_ohm_m','rho_a','rho']),$number(['ph']),$number(['tds_ppm','tds','tds_mg_l']),$number(['ec_us','ec','ec_us_cm']),$number(['temp_c','temp','water_temp_c']),$number(['turbidity_ntu','turbidity','ntu']),$measuredAt,$rawJson
  ]);
  $readingId = (int)pdo()->lastInsertId();
  pdo()->commit();
  $normalized = 'raw_only';
  if ($type === 'geoelectric') {
    $key = 'serial-'.$readingId;
    $normalized = ingest_measurement($key, $r + ['point'=>$pointCode,'device'=>$device,'ts'=>$timestamp], ['source'=>'serial']);
  } elseif ($type === 'water') {
    $key = 'serial-'.$readingId;
    $normalized = ingest_water($key, $r + ['point'=>$pointCode,'device'=>$device,'ts'=>$timestamp], ['source'=>'serial']);
  }
  q('INSERT INTO activity_history(user_id,action,entity_type,entity_id,status,details_json,ip_address) VALUES(?,?,?,?,?,?,?)', [(int)current_user()['id'],'serial_reading_saved','sensor_readings',(string)$readingId,'success',json_encode(['type'=>$type,'normalized'=>$normalized],JSON_UNESCAPED_UNICODE),substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45)]);
  json_out(['ok'=>true,'id'=>$readingId,'type'=>$type,'normalized'=>$normalized,'message'=>'Data tersimpan di MySQL Aquify.']);
} catch (Throwable $e) {
  if (pdo()->inTransaction()) pdo()->rollBack();
  try { q('INSERT INTO activity_history(user_id,action,entity_type,status,details_json,ip_address) VALUES(?,?,?,?,?,?)', [(int)current_user()['id'],'serial_save_failed','sensor_readings','failed',json_encode(['error'=>substr($e->getMessage(),0,300)],JSON_UNESCAPED_UNICODE),substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45)]); } catch (Throwable $ignored) {}
  json_out(['error'=>'Penyimpanan gagal: '.$e->getMessage()],500);
}
