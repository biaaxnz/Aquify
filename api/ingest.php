<?php
// POST JSON langsung dari ESP32 (alternatif Firebase). Header: X-Api-Token: <ingest.token>
define('AQ_NOSESSION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Gunakan POST.'], 405);
$tok = (string)cfg('ingest.token', '');
$given = (string)($_SERVER['HTTP_X_API_TOKEN'] ?? '');
if ($tok === '' || !hash_equals($tok, $given)) json_out(['error' => 'Token tidak valid.'], 401);

$raw = file_get_contents('php://input', false, null, 0, 262144);
$j = json_decode((string)$raw, true);
if (!is_array($j)) json_out(['error' => 'Body harus JSON.'], 400);

// Bentuk diterima: satu objek, atau {"measurements":[...],"water":[...]}
$ms = $j['measurements'] ?? (isset($j['water']) ? [] : [$j]);
$ws = $j['water'] ?? [];
$st = ['inserted' => 0, 'duplicate' => 0, 'invalid' => 0];
$key = function (array $r, string $kind): string {
    if (!empty($r['key']) && is_string($r['key'])) return 'api_' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $r['key']), 0, 100);
    return 'api_' . $kind . '_' . substr(sha1(json_encode($r)), 0, 24);
};
foreach (array_slice((array)$ms, 0, 200) as $r) if (is_array($r)) $st[ingest_measurement($key($r, 'm'), $r, ['source' => 'api'])]++; else $st['invalid']++;
foreach (array_slice((array)$ws, 0, 200) as $r) if (is_array($r)) $st[ingest_water($key($r, 'w'), $r, ['source' => 'api'])]++; else $st['invalid']++;
json_out($st + ['ok' => true]);
