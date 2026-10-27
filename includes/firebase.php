<?php
function fb_configured(): bool { return trim((string)cfg('firebase.db_url', '')) !== ''; }

/** GET dari Firebase Realtime Database (REST). Melempar RuntimeException jika gagal. */
function fb_get(string $path, array $query = []) {
    $base = rtrim((string)cfg('firebase.db_url'), '/');
    if (!preg_match('#^https://#i', $base)) throw new RuntimeException('firebase.db_url harus diawali https://');
    $secret = (string)cfg('firebase.secret', '');
    if ($secret !== '') $query['auth'] = $secret;
    $url = $base . '/' . trim($path, '/') . '.json' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Gagal menghubungi Firebase: ' . $err);
    if ($code === 401 || $code === 403) throw new RuntimeException('Firebase menolak akses (HTTP ' . $code . '). Atur rules baca untuk path ini atau isi firebase.secret.');
    if ($code >= 400) throw new RuntimeException('Firebase HTTP ' . $code . ': ' . mb_substr((string)$body, 0, 160));
    $data = json_decode((string)$body, true);
    if ($data === null && trim((string)$body) !== 'null') throw new RuntimeException('Respons Firebase bukan JSON valid.');
    return $data;
}

function fb_sync_path(string $kind, array &$st): void {
    $path = (string)cfg("firebase.paths.$kind", $kind);
    $stateKey = 'fb_last_' . $kind;
    $last = state_get($stateKey);
    $batch = max(10, min(1000, (int)cfg('firebase.batch', 200)));
    for ($b = 0; $b < max(1, (int)cfg('firebase.max_batches', 10)); $b++) {
        $q = ['orderBy' => '"$key"', 'limitToFirst' => $batch + ($last !== null ? 1 : 0)];
        if ($last !== null) $q['startAt'] = json_encode($last);
        $data = fb_get($path, $q);
        if (!is_array($data) || !$data) break;
        ksort($data, SORT_STRING);
        $fresh = 0;
        foreach ($data as $key => $row) {
            $key = (string)$key;
            if ($last !== null && strcmp($key, $last) <= 0) continue;
            $fresh++;
            $last = $key;
            if (!is_array($row)) {
                $st['invalid']++;
                store_firebase_event($path, $key, $kind, ['value' => $row], 'failed', 'Payload Firebase bukan objek JSON.');
                continue;
            }
            try {
                $r = $kind === 'water' ? ingest_water($key, $row) : ingest_measurement($key, $row);
                store_firebase_event($path, $key, $kind, $row, $r === 'invalid' ? 'failed' : 'processed', $r === 'invalid' ? 'Payload tidak memenuhi validasi pengukuran.' : null);
            } catch (Throwable $e) {
                store_firebase_event($path, $key, $kind, $row, 'failed', $e->getMessage());
                throw $e;
            }
            $st['fetched']++;
            $st[$r]++;
        }
        if ($last !== null) state_set($stateKey, $last);
        if ($fresh < $batch) break;
    }
}

/** Simpan payload mentah Firebase untuk audit, termasuk baris invalid agar bisa diperiksa ulang. */
function store_firebase_event(string $path, string $key, string $kind, array $row, string $status, ?string $error = null): void {
    $device = pick($row, ['device', 'device_id', 'dev']);
    $timestamp = pick($row, ['ts', 'timestamp', 'time', 't']);
    $eventAt = null;
    if ($timestamp !== null) {
        $parsed = parse_ts($timestamp);
        if ($parsed !== date('Y-m-d H:i:s') || is_string($timestamp)) $eventAt = $parsed;
    }
    q('INSERT INTO firebase_events(event_key,data_path,device_id,event_type,event_timestamp,payload_json,sync_status,error_message,processed_at)
       VALUES(?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),event_type=VALUES(event_type),event_timestamp=VALUES(event_timestamp),
       payload_json=VALUES(payload_json),sync_status=VALUES(sync_status),error_message=VALUES(error_message),processed_at=VALUES(processed_at)',
      [mb_substr($key, 0, 190), mb_substr($path, 0, 255), $device !== null ? mb_substr((string)$device, 0, 100) : null,
       $kind, $eventAt, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $status,
       $error !== null ? mb_substr($error, 0, 1000) : null, date('Y-m-d H:i:s')]);
}

/** Tarik data baru dari Firebase. $source: 'manual' | 'auto' | 'cron'. */
function fb_sync(string $source = 'manual'): array {
    $st = ['fetched' => 0, 'inserted' => 0, 'duplicate' => 0, 'invalid' => 0, 'error' => null, 'skipped' => false];
    if (!fb_configured()) { $st['error'] = 'firebase.db_url belum diisi.'; return $st; }
    $got = (int)q_val("SELECT GET_LOCK('aquify_sync', 0)");
    if (!$got) { $st['skipped'] = true; return $st; }
    $started = date('Y-m-d H:i:s');
    try {
        fb_sync_path('measurements', $st);
        fb_sync_path('water', $st);
    } catch (Throwable $e) {
        $st['error'] = mb_substr($e->getMessage(), 0, 480);
    } finally {
        q("SELECT RELEASE_LOCK('aquify_sync')");
    }
    state_set('last_sync_at', date('Y-m-d H:i:s'));
    state_set('last_sync_error', $st['error']);
    if ($source !== 'live' || $st['error'] || $st['inserted'] > 0 || $st['invalid'] > 0) {
        q('INSERT INTO sync_log(user_id,source,started_at,fetched,inserted,duplicate,invalid,error) VALUES(?,?,?,?,?,?,?,?)',
          [current_user_id() ?: null, $source, $started, $st['fetched'], $st['inserted'], $st['duplicate'], $st['invalid'], $st['error']]);
        q('DELETE FROM sync_log WHERE id < (SELECT m FROM (SELECT CAST(MAX(id) AS SIGNED) - 200 AS m FROM sync_log) t)');
    }
    return $st;
}
