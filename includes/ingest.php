<?php
/** Ambil nilai pertama yang ada dari beberapa kemungkinan nama field (tak peka huruf besar/kecil). */
function pick(array $row, array $names) {
    foreach ($names as $n) {
        foreach ($row as $k => $v) {
            if (strcasecmp((string)$k, $n) === 0 && $v !== null && $v !== '' && !is_array($v)) return $v;
        }
    }
    return null;
}
function to_f($v): ?float {
    if ($v === null) return null;
    if (is_string($v)) $v = str_replace(',', '.', trim($v));
    return (is_numeric($v) && is_finite((float)$v)) ? (float)$v : null;
}
/** Epoch detik/milidetik atau string tanggal -> 'Y-m-d H:i:s'. Epoch kecil (ESP32 tanpa NTP) -> sekarang. */
function parse_ts($v): string {
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 1e12) $n /= 1000;
        if ($n > 1e9) return date('Y-m-d H:i:s', (int)$n);
    } elseif (is_string($v) && $v !== '') {
        $t = strtotime($v);
        if ($t) return date('Y-m-d H:i:s', $t);
    }
    return date('Y-m-d H:i:s');
}

function resolve_point(?string $code, bool $create = true, string $type = 'geovolt'): ?int {
    $code = mb_substr(trim((string)$code), 0, 40);
    if ($code === '') return null;
    $uid = current_user_id();
    $ownerId = $uid ?: (int)(q_val("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1") ?: 0);
    if ($ownerId <= 0) $ownerId = null;
    $id = $uid ? q_val('SELECT id FROM points WHERE code=? AND owner_id=?', [$code, $uid]) : q_val('SELECT id FROM points WHERE code=?', [$code]);
    if ($id) return (int)$id;
    if ($uid && q_val('SELECT id FROM points WHERE code=?', [$code])) return null;
    if (!$create || !cfg('analysis.auto_create_points', true)) return null;
    try {
        try { q('INSERT INTO points(owner_id,code,name,survey_type) VALUES(?,?,?,?)', [$ownerId, $code, $code, $type]); }
        catch (PDOException $e) { if (is_dup($e)) throw $e; q('INSERT INTO points(owner_id,code,name) VALUES(?,?,?)', [$ownerId, $code, $code]); }
        return (int)pdo()->lastInsertId();
    } catch (PDOException $e) {
        $id = $uid ? q_val('SELECT id FROM points WHERE code=? AND owner_id=?', [$code, $uid]) : q_val('SELECT id FROM points WHERE code=?', [$code]);
        return $id ? (int)$id : null;
    }
}

function is_dup(PDOException $e): bool { return (int)($e->errorInfo[1] ?? 0) === 1062; }

/** @return 'inserted'|'duplicate'|'invalid' */
function ingest_measurement(string $fbKey, array $row, array $opts = []): string {
    $cn = strtolower((string)(pick($row, ['config', 'cfg', 'mode', 'konfigurasi']) ?? 'wenner'));
    $config = str_starts_with($cn, 's') ? 'schlumberger' : 'wenner';
    $s   = to_f(pick($row, ['spacing_m', 'spacing', 'a', 'ab2', 'ab_2', 'jarak']));
    $mn  = to_f(pick($row, ['mn_m', 'mn']));
    $dv  = to_f(pick($row, ['dv_mv', 'dv', 'delta_v', 'v_mv']));
    $i   = to_f(pick($row, ['i_ma', 'i', 'current_ma', 'arus_ma']));
    $rho = to_f(pick($row, ['rho_a', 'rho', 'rhoa']));
    if ($s === null || $s <= 0 || $s > 10000) return 'invalid';
    if ($dv !== null) $dv = abs($dv);
    if ($i !== null) $i = abs($i);
    $k = geom_k($config, $s, $mn);
    if ($rho === null) {
        if ($dv === null || $i === null || $i <= 0 || $k === null) return 'invalid';
        $rho = $k * ($dv / $i);                     // mV/mA = Ω
    }
    if (!is_finite($rho) || $rho <= 0 || $rho > 1e6) return 'invalid';
    if ($dv === null) $dv = ($k && $i) ? $rho / $k * $i : 0.0;
    if ($i === null) $i = 0.0;

    $pointCode = trim((string)pick($row, ['point', 'point_id', 'titik', 'site']));
    $pid = resolve_point($pointCode);
    if ($pointCode !== '' && !$pid && current_user_id() && q_val('SELECT id FROM points WHERE code=?', [$pointCode])) return 'invalid';
    $dev = mb_substr((string)(pick($row, ['device', 'device_id', 'dev']) ?? ''), 0, 60);
    $at  = parse_ts(pick($row, ['ts', 'timestamp', 'time', 't']));
    $xm = to_f(pick($row, ['x_m', 'x', 'pos', 'position', 'posisi']));
    if ($xm !== null && (!is_finite($xm) || abs($xm) > 100000)) $xm = null;
    try {
        $cols = 'fb_key,point_id,device_id,config,spacing_m,mn_m,dv_mv,i_ma,rho_a,measured_at,source,raw_json';
        $vals = [mb_substr($fbKey, 0, 120), $pid, $dev ?: null, $config, $s, $mn, $dv, $i, $rho, $at,
                 $opts['source'] ?? 'firebase', mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE), 0, 2000)];
        if ($xm !== null && aq_has_col('measurements', 'x_m')) { $cols .= ',x_m'; $vals[] = $xm; }
        q('INSERT INTO measurements(' . $cols . ') VALUES(' . implode(',', array_fill(0, count($vals), '?')) . ')', $vals);
        return 'inserted';
    } catch (PDOException $e) {
        if (is_dup($e)) return 'duplicate';
        throw $e;
    }
}

function ingest_water(string $fbKey, array $row, array $opts = []): string {
    $tds  = to_f(pick($row, ['tds_ppm', 'tds']));
    $ec   = to_f(pick($row, ['ec_us', 'ec_us_cm', 'ec', 'conductivity']));
    $ph   = to_f(pick($row, ['ph']));
    $temp = to_f(pick($row, ['temp_c', 'water_temp_c', 'temp', 'suhu']));
    $turb = to_f(pick($row, ['turbidity_ntu', 'turbidity', 'turb', 'ntu']));
    if ($tds === null && $ec === null && $ph === null && $temp === null && $turb === null) return 'invalid';
    if ($ph !== null && ($ph < 0 || $ph > 14)) return 'invalid';
    $pointCode = trim((string)pick($row, ['point', 'point_id', 'titik', 'site']));
    $pid = resolve_point($pointCode, true, 'water');
    if ($pointCode !== '' && !$pid && current_user_id() && q_val('SELECT id FROM points WHERE code=?', [$pointCode])) return 'invalid';
    $dev = mb_substr((string)(pick($row, ['device', 'device_id', 'dev']) ?? ''), 0, 60);
    $at  = parse_ts(pick($row, ['ts', 'timestamp', 'time', 't']));
    try {
        $ownerId = $pid ? q_val('SELECT owner_id FROM points WHERE id=?', [$pid]) : (current_user_id() ?: null);
        q('INSERT INTO water_samples(owner_id,fb_key,point_id,device_id,tds_ppm,ec_us,ph,temp_c,turbidity_ntu,measured_at,source,raw_json)
           VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
          [$ownerId, mb_substr($fbKey, 0, 120), $pid, $dev ?: null, $tds, $ec, $ph, $temp, $turb, $at,
           $opts['source'] ?? 'firebase', mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE), 0, 2000)]);
        return 'inserted';
    } catch (PDOException $e) {
        if (is_dup($e)) return 'duplicate';
        throw $e;
    }
}
