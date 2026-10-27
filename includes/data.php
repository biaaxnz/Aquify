<?php
/** Kueri gabungan untuk beranda, riwayat, perangkat, dan notifikasi. */

function aq_points_overview(): array {
    return q_all('SELECT p.id,p.code,p.name,p.lat,p.lng,p.survey_type,p.created_at,
        (SELECT COUNT(*) FROM measurements m WHERE m.point_id=p.id) AS n,
        (SELECT a.verdict FROM analyses a WHERE a.point_id=p.id ORDER BY a.id DESC LIMIT 1) AS verdict,
        (SELECT a.score FROM analyses a WHERE a.point_id=p.id ORDER BY a.id DESC LIMIT 1) AS score,
        (SELECT a.best_depth FROM analyses a WHERE a.point_id=p.id ORDER BY a.id DESC LIMIT 1) AS best_depth,
        (SELECT a.best_rho FROM analyses a WHERE a.point_id=p.id ORDER BY a.id DESC LIMIT 1) AS best_rho
        FROM points p WHERE p.owner_id=? ORDER BY p.code', [current_user_id()]);
}

/** Daftar survei gabungan (geolistrik + kualitas air), terbaru dulu. */
function aq_feed(?string $type = null, string $q = '', int $limit = 80): array {
    $items = [];
    if ($type !== 'water') {
        $rows = q_all('SELECT p.id,p.code,p.name,p.survey_type,p.created_at,
            (SELECT MAX(m.measured_at) FROM measurements m WHERE m.point_id=p.id) AS t,
            (SELECT COUNT(*) FROM water_samples w WHERE w.point_id=p.id) AS nw,
            (SELECT a.verdict FROM analyses a WHERE a.point_id=p.id ORDER BY a.id DESC LIMIT 1) AS verdict
            FROM points p WHERE p.owner_id=?', [current_user_id()]);
        foreach ($rows as $r) {
            if ($r['t'] === null && ($r['survey_type'] === 'water' || (int)$r['nw'] > 0)) continue; // titik air murni bukan item geolistrik
            [$lab, $tone] = verdict_tone($r['verdict']);
            [$labL] = verdict_tone($r['verdict'], true);
            if ($r['t'] === null) { $lab = 'Belum diukur'; $labL = $lab; $tone = 'mute'; }
            $t = $r['t'] ?: $r['created_at'];
            $items[] = ['kind' => 'geo', 'id' => (int)$r['id'], 'time' => $t, 'code' => $r['code'], 'name' => $r['name'],
                'title' => $r['name'] === $r['code'] ? $r['code'] : $r['code'] . ' · ' . $r['name'], 'sub' => 'Geolistrik · ' . date('H:i', strtotime($t)),
                'dtitle' => 'Titik ' . $r['code'] . ' · GeoVolt', 'dsub' => $r['name'] . ' · ' . ago($t),
                'badge' => $lab, 'badgeL' => $labL, 'tone' => $tone, 'href' => 'point.php?id=' . (int)$r['id']];
        }
    }
    if ($type !== 'geo') {
        $rows = q_all('SELECT w.*, p.code, p.name FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.owner_id=? ORDER BY w.measured_at DESC, w.id DESC LIMIT 200', [current_user_id()]);
        foreach ($rows as $w) {
            $o = water_overall($w);
            $place = $w['name'] ?: ($w['code'] ?: 'Sampel air #' . $w['id']);
            $items[] = ['kind' => 'water', 'id' => (int)$w['id'], 'time' => $w['measured_at'], 'code' => $w['code'], 'name' => $place,
                'title' => $place, 'sub' => 'Kualitas Air · ' . date('H:i', strtotime($w['measured_at'])),
                'dtitle' => $place . ' · Kualitas Air', 'dsub' => ($w['code'] ? $w['code'] . ' · ' : '') . ago($w['measured_at']),
                'badge' => $o['short'], 'badgeL' => $o['short'], 'tone' => $o['tone'] === 'ok' ? 'info' : $o['tone'], 'href' => 'water-detail.php?id=' . (int)$w['id']];
        }
        $rows = q_all("SELECT p.id,p.code,p.name,p.created_at FROM points p WHERE p.owner_id=? AND p.survey_type='water' AND NOT EXISTS (SELECT 1 FROM water_samples w WHERE w.point_id=p.id)", [current_user_id()]);
        foreach ($rows as $r) {
            $items[] = ['kind' => 'water', 'id' => 0, 'time' => $r['created_at'], 'code' => $r['code'], 'name' => $r['name'],
                'title' => $r['name'], 'sub' => 'Kualitas Air · ' . date('H:i', strtotime($r['created_at'])),
                'dtitle' => $r['name'] . ' · Kualitas Air', 'dsub' => $r['code'] . ' · ' . ago($r['created_at']),
                'badge' => 'Belum diukur', 'badgeL' => 'Belum diukur', 'tone' => 'mute', 'href' => 'water.php?point=' . (int)$r['id']];
        }
    }
    if ($q !== '') {
        $ql = mb_strtolower($q);
        $items = array_values(array_filter($items, fn($i) => mb_strpos(mb_strtolower($i['title'] . ' ' . $i['code']), $ql) !== false));
    }
    usort($items, fn($a, $b) => strcmp((string)$b['time'], (string)$a['time']));
    return array_slice($items, 0, $limit);
}

function aq_battery_signal(?string $raw): array {
    $j = $raw ? json_decode($raw, true) : null;
    $bat = null; $sig = null;
    if (is_array($j)) {
        foreach (['battery', 'bat', 'batt', 'battery_pct', 'battery_percent', 'battery_level'] as $k) if (isset($j[$k]) && is_numeric($j[$k])) { $bat = (float)$j[$k]; break; }
        foreach (['rssi', 'signal', 'wifi_rssi'] as $k) if (isset($j[$k]) && is_numeric($j[$k])) { $sig = (float)$j[$k]; break; }
    }
    return [$bat, $sig];
}

/** Perangkat yang pernah mengirim data (dari sensor_readings, pengukuran, dan sampel air). */
function aq_devices(): array {
    $uid = current_user_id();
    $rows = q_all("SELECT device_id, MAX(t) AS last_t, COUNT(*) AS n, MAX(g) AS g, MAX(w) AS w FROM (
        SELECT sr.device_id, COALESCE(sr.measured_at, sr.created_at) AS t, IF(sr.reading_type='geoelectric',1,0) AS g, IF(sr.reading_type='water',1,0) AS w
          FROM sensor_readings sr JOIN points p ON p.id=sr.point_id WHERE p.owner_id=? AND sr.device_id IS NOT NULL AND sr.device_id<>''
        UNION ALL SELECT m.device_id, m.measured_at, 1, 0 FROM measurements m JOIN points p ON p.id=m.point_id WHERE p.owner_id=? AND m.device_id IS NOT NULL AND m.device_id NOT IN ('','manual')
        UNION ALL SELECT w.device_id, w.measured_at, 0, 1 FROM water_samples w WHERE w.owner_id=? AND w.device_id IS NOT NULL AND w.device_id NOT IN ('','manual')
      ) x GROUP BY device_id ORDER BY last_t DESC", [$uid, $uid, $uid]);
    $out = [];
    foreach ($rows as $r) {
        $raw = q_val('SELECT raw FROM (
            SELECT sr.raw_json AS raw, COALESCE(sr.measured_at, sr.created_at) AS t FROM sensor_readings sr JOIN points p ON p.id=sr.point_id WHERE sr.device_id=? AND p.owner_id=?
            UNION ALL SELECT m.raw_json, m.measured_at FROM measurements m JOIN points p ON p.id=m.point_id WHERE m.device_id=? AND p.owner_id=?
            UNION ALL SELECT w.raw_json, w.measured_at FROM water_samples w WHERE w.device_id=? AND w.owner_id=?) x
            WHERE raw IS NOT NULL ORDER BY t DESC LIMIT 1', [$r['device_id'], $uid, $r['device_id'], $uid, $r['device_id'], $uid]);
        [$bat, $sig] = aq_battery_signal($raw ? (string)$raw : null);
        $ts = $r['last_t'] ? strtotime($r['last_t']) : 0;
        $kind = ($r['g'] && $r['w']) ? 'Geolistrik & kualitas air' : ($r['w'] ? 'Sensor kualitas air' : ($r['g'] ? 'Resistivity meter' : 'Perangkat sensor'));
        $out[] = ['id' => $r['device_id'], 'last' => $r['last_t'], 'n' => (int)$r['n'], 'kind' => $kind, 'water' => (bool)$r['w'], 'geo' => (bool)$r['g'],
                  'active' => $ts && (time() - $ts) <= AQ_ACTIVE_MIN * 60, 'battery' => $bat, 'rssi' => $sig];
    }
    return $out;
}

function aq_notifications(int $limit = 40): array {
    static $cache = null;
    if ($cache !== null) return array_slice($cache, 0, $limit);
    $n = [];
    foreach (q_all('SELECT a.id,a.point_id,a.mode,a.created_at,p.code FROM analyses a JOIN points p ON p.id=a.point_id WHERE p.owner_id=? ORDER BY a.id DESC LIMIT 12', [current_user_id()]) as $a)
        $n[] = ['t' => $a['created_at'], 'tone' => 'ok', 'icon' => 'sparkles', 'title' => $a['mode'] === 'ai' ? 'Analisa AI selesai' : 'Analisis cepat selesai', 'sub' => 'Titik ' . $a['code'] . ' siap dilihat · ' . ago($a['created_at']), 'href' => 'analysis-view.php?id=' . (int)$a['point_id']];
    foreach (q_all('SELECT w.*, p.code, p.name FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.owner_id=? ORDER BY w.measured_at DESC, w.id DESC LIMIT 25', [current_user_id()]) as $w) {
        $o = water_overall($w);
        if ($o['tone'] === 'bad') $n[] = ['t' => $w['measured_at'], 'tone' => 'bad', 'icon' => 'alert', 'title' => 'Kualitas air di bawah standar', 'sub' => ($w['name'] ?: ($w['code'] ?: 'Sampel')) . ' · ' . implode(', ', $o['bad']) . ' melewati ambang', 'href' => 'water-detail.php?id=' . (int)$w['id']];
    }
    foreach (q_all('SELECT id,source,started_at,inserted,error FROM sync_log WHERE user_id=? ORDER BY id DESC LIMIT 10', [current_user_id()]) as $s) {
        if ($s['error']) $n[] = ['t' => $s['started_at'], 'tone' => 'warn', 'icon' => 'refresh', 'title' => 'Sinkronisasi ' . $s['source'] . ' gagal', 'sub' => mb_substr((string)$s['error'], 0, 80), 'href' => 'sync.php'];
        elseif ((int)$s['inserted'] > 0) $n[] = ['t' => $s['started_at'], 'tone' => 'info', 'icon' => 'refresh', 'title' => (int)$s['inserted'] . ' data baru dari ' . $s['source'], 'sub' => 'Sinkronisasi selesai · ' . ago($s['started_at']), 'href' => 'sync.php'];
    }
    foreach (q_all('SELECT id,code,name,created_at FROM points WHERE owner_id=? ORDER BY id DESC LIMIT 8', [current_user_id()]) as $p)
        $n[] = ['t' => $p['created_at'], 'tone' => 'info', 'icon' => 'check-circle', 'title' => 'Survey ' . $p['code'] . ' tersimpan', 'sub' => $p['name'] . ' · ' . date('H:i', strtotime($p['created_at'])), 'href' => 'point.php?id=' . (int)$p['id']];
    foreach (aq_devices() as $d) {
        if ($d['battery'] !== null && $d['battery'] <= 25 && $d['last'] && (time() - strtotime($d['last'])) < 86400)
            $n[] = ['t' => $d['last'], 'tone' => 'warn', 'icon' => 'battery', 'title' => 'Baterai ' . $d['id'] . ' rendah', 'sub' => 'Tersisa ' . round($d['battery']) . '% · ' . ago($d['last']), 'href' => 'device.php'];
    }
    usort($n, fn($a, $b) => strcmp((string)$b['t'], (string)$a['t']));
    $read = (string)state_get('notif_read_' . (int)(current_user()['id'] ?? 0));
    foreach ($n as &$i) $i['unread'] = $read === '' || strcmp((string)$i['t'], $read) > 0;
    unset($i);
    $cache = $n;
    return array_slice($cache, 0, $limit);
}
function aq_unread_count(): int {
    try { return count(array_filter(aq_notifications(40), fn($i) => $i['unread'])); } catch (Throwable $e) { return 0; }
}

/** Rencana survei tersimpan di points.plan_json. */
function aq_plan(?array $p): array {
    $d = ['config' => 'wenner', 'electrodes' => 4, 'spacing' => 5.0, 'depth' => 30, 'orientation' => 'Utara–Selatan'];
    $j = $p && !empty($p['plan_json']) ? json_decode((string)$p['plan_json'], true) : null;
    return is_array($j) ? array_merge($d, $j) : $d;
}
function aq_plan_length(array $plan): float { return max(0, ((int)$plan['electrodes'] - 1) * (float)$plan['spacing']); }
/** Perkiraan jumlah jarak (n) untuk susunan Wenner: 3a <= (elektroda-1)*spasi. */
function aq_plan_levels(array $plan): int { return max(1, (int)floor(((int)$plan['electrodes'] - 1) / 3)); }

/** Status langsung survei geolistrik satu titik (dipakai halaman Data Live dan api/live.php). */
function aq_live_geo(int $pid): array {
    $p = q_one('SELECT * FROM points WHERE id=?', [$pid]);
    $plan = aq_plan($p);
    $meas = q_all('SELECT spacing_m, rho_a FROM measurements WHERE point_id=? ORDER BY spacing_m, measured_at', [$pid]);
    $S = build_sounding($meas, (float)cfg('analysis.depth_factor', 0.5));
    $latest = q_one('SELECT dv_mv,i_ma,rho_a,measured_at,device_id FROM measurements WHERE point_id=? ORDER BY measured_at DESC, id DESC LIMIT 1', [$pid]);
    $dev = aq_devices()[0] ?? null;
    return [
        'latest' => $latest, 'done' => count($S), 'total' => max(count($S), aq_plan_levels($plan)),
        'device' => $dev ? ['id' => $dev['id'], 'active' => $dev['active'], 'ago' => ago($dev['last'])] : null,
        'strata_html' => aq_section_svg(count($S) >= 3 ? aq_layers($S) : [], ['electrodes' => (int)$plan['electrodes'], 'length' => aq_plan_length($plan)]),
        'plan' => $plan,
    ];
}
function aq_live_water(int $pid): array {
    $w = $pid ? q_one('SELECT * FROM water_samples WHERE point_id=? AND owner_id=? ORDER BY measured_at DESC, id DESC LIMIT 1', [$pid, current_user_id()]) : null;
    if (!$w && !$pid) $w = q_one('SELECT * FROM water_samples WHERE owner_id=? ORDER BY measured_at DESC, id DESC LIMIT 1', [current_user_id()]);
    $dev = null;
    foreach (aq_devices() as $d) if ($d['water']) { $dev = $d; break; }
    $params = null;
    if ($w) { $params = []; foreach (water_params($w) as $k => $r) $params[$k] = ['val' => $r['val'], 'dec' => $r['dec'], 'st' => $r['st'], 'tone' => $r['tone']]; }
    return ['sample' => $w ? ['id' => (int)$w['id'], 'params' => $params, 'ago' => ago($w['measured_at'])] : null,
            'device' => $dev ? ['id' => $dev['id'], 'active' => $dev['active'], 'ago' => ago($dev['last'])] : null];
}

/** Tarik data Firebase sekarang; bila sinkron lain sedang berjalan, tunggu sebentar lalu coba lagi. */
function aq_pull_firebase(): array {
    if (!fb_configured()) return ['fetched' => 0, 'inserted' => 0, 'duplicate' => 0, 'invalid' => 0, 'error' => 'Firebase belum diatur (firebase.db_url di config.php).', 'skipped' => false, 'configured' => false];
    @set_time_limit(120);
    $r = null;
    for ($i = 0; $i < 4; $i++) {
        $r = fb_sync('manual');
        if (empty($r['skipped'])) break;
        sleep(2);
    }
    $r['configured'] = true;
    return $r;
}
