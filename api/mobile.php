<?php
/**
 * API JSON untuk aplikasi Android Aquify.
 * - Autentikasi memakai token bertanda tangan HMAC (header X-Aquify-Token), bukan cookie sesi.
 * - Memakai ulang fungsi yang sama dengan halaman web, jadi hasil analisis dan penilaian air identik.
 * Pasang di folder aquify-web/api/ (di samping ingest.php).
 */
define('AQ_NOSESSION', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Cache-Control: no-store');

$action = (string)($_GET['a'] ?? '');
if ($action === 'ping') {
    json_out(['ok' => true, 'app' => (string)cfg('app.name', 'Aquify'), 'otp' => otp_enabled()]);
}
if (!is_file(AQ_ROOT . '/config.php') || !is_file(AQ_ROOT . '/installed.lock')) {
    json_out(['error' => 'Aquify belum diinstal di server. Jalankan install.php dulu.'], 503);
}

/* ---------- Pembantu ---------- */
function m_secret(): string {
    $s = state_get('mobile_secret');
    if (!$s) { $s = bin2hex(random_bytes(32)); state_set('mobile_secret', $s); }
    return $s;
}
function m_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function m_b64d(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }
function m_token(int $uid): string {
    $p = m_b64((string)json_encode(['u' => $uid, 'e' => time() + 86400 * 30]));
    return $p . '.' . hash_hmac('sha256', $p, m_secret());
}
function m_auth(): array {
    $h = (string)($_SERVER['HTTP_X_AQUIFY_TOKEN'] ?? '');
    if ($h === '') {
        $a = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($a, 'Bearer ') === 0) $h = substr($a, 7);
    }
    $parts = explode('.', $h);
    if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], m_secret()), $parts[1])) {
        json_out(['error' => 'Sesi tidak valid. Silakan masuk lagi.'], 401);
    }
    $p = json_decode(m_b64d($parts[0]), true);
    if (!is_array($p) || (int)($p['e'] ?? 0) < time()) json_out(['error' => 'Sesi berakhir. Silakan masuk lagi.'], 401);
    $row = q_one('SELECT id,username,role FROM users WHERE id=?', [(int)($p['u'] ?? 0)]);
    if (!$row) json_out(['error' => 'Akun tidak ditemukan. Silakan masuk lagi.'], 401);
    // Fungsi bersama (current_user, aq_notifications, dst.) membaca $_SESSION['user'].
    $_SESSION = ['user' => ['id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role']]];
    return $row;
}
function m_in(): array {
    static $in = null;
    if ($in !== null) return $in;
    $raw = (string)file_get_contents('php://input');
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $in = is_array($j) ? $j : $_POST;
    return $in;
}
function m_str(string $k, string $d = ''): string { return trim((string)(m_in()[$k] ?? $d)); }
function m_num(string $k): ?float {
    $v = str_replace(',', '.', m_str($k));
    return ($v !== '' && is_numeric($v)) ? (float)$v : null;
}
function m_post_only(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Metode harus POST.'], 405);
}
function m_user(int $id): array {
    $r = q_one('SELECT id,username,role,full_name,email,phone FROM users WHERE id=?', [$id]) ?: [];
    $name = trim((string)($r['full_name'] ?? '')) !== '' ? (string)$r['full_name'] : (string)($r['username'] ?? '');
    return ['id' => (int)($r['id'] ?? 0), 'name' => $name, 'username' => (string)($r['username'] ?? ''), 'role' => (string)($r['role'] ?? 'viewer'),
            'initials' => initials($name), 'email' => (string)($r['email'] ?? ''), 'phone' => (string)($r['phone'] ?? '')];
}
/** Ubah href halaman web menjadi tujuan layar aplikasi. */
function m_target(string $href): array {
    if (preg_match('~^point\.php\?id=(\d+)~', $href, $m)) return ['point', (int)$m[1]];
    if (preg_match('~^analysis-view\.php\?id=(\d+)~', $href, $m)) return ['analysis', (int)$m[1]];
    if (preg_match('~^water-detail\.php\?id=(\d+)~', $href, $m)) return ['water', (int)$m[1]];
    if (preg_match('~^water\.php\?point=(\d+)~', $href, $m)) return ['waterpoint', (int)$m[1]];
    if (strpos($href, 'device.php') === 0) return ['device', 0];
    return ['none', 0];
}
function m_feed_item(array $i): array {
    [$t, $ref] = m_target((string)$i['href']);
    return ['kind' => (string)$i['kind'], 'target' => $t, 'ref' => $ref, 'title' => (string)$i['title'], 'sub' => (string)$i['sub'],
            'dtitle' => (string)$i['dtitle'], 'dsub' => (string)$i['dsub'], 'badge' => (string)$i['badge'], 'badgeL' => (string)$i['badgeL'],
            'tone' => (string)$i['tone'], 'day' => day_label((string)$i['time'])];
}
function m_strata(): array {
    $totals = [];
    foreach (q_all('SELECT a.result_json FROM analyses a JOIN (SELECT MAX(id) mid FROM analyses GROUP BY point_id) x ON x.mid=a.id JOIN points p ON p.id=a.point_id WHERE p.owner_id=?', [current_user_id()]) as $a) {
        $r = json_decode((string)$a['result_json'], true);
        foreach (($r['layers'] ?? []) as $L) $totals[$L['key']] = ($totals[$L['key']] ?? 0) + max(0, (float)$L['to'] - (float)$L['from']);
    }
    $order = ['saline', 'brackish', 'aquifer', 'dry', 'rock'];
    uksort($totals, fn($a, $b) => array_search($a, $order) <=> array_search($b, $order));
    $sum = array_sum($totals);
    $out = [];
    if ($sum > 0) foreach ($totals as $k => $v) {
        $out[] = ['key' => (string)$k, 'name' => strata_name((string)$k), 'color' => strata_color((string)$k), 'pct' => (int)round($v / $sum * 100)];
    }
    return $out;
}
/* Pendaftaran yang menunggu verifikasi OTP (tabel kecil sendiri, karena sync_state hanya 255 karakter). */
function m_pend_init(): void {
    pdo()->exec("CREATE TABLE IF NOT EXISTS mobile_pending (email VARCHAR(160) NOT NULL PRIMARY KEY, data TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function m_pend_get(string $email): ?array {
    m_pend_init();
    $d = q_val('SELECT data FROM mobile_pending WHERE email=?', [$email]);
    $a = $d ? json_decode((string)$d, true) : null;
    return is_array($a) ? $a : null;
}
function m_pend_set(string $email, array $row): void {
    m_pend_init();
    q('INSERT INTO mobile_pending(email,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)', [$email, json_encode($row)]);
}
function m_pend_del(string $email): void {
    m_pend_init();
    q('DELETE FROM mobile_pending WHERE email=?', [$email]);
}
function m_send_otp(string $email, string $name = ''): bool {
    $code = (string)random_int(100000, 999999);
    if (!aq_send_mail($email, 'Kode verifikasi Aquify', "Kode verifikasi Aquify kamu: $code\nKode berlaku 10 menit. Abaikan email ini bila kamu tidak mendaftar.")) return false;
    $row = m_pend_get($email) ?: [];
    m_pend_set($email, array_merge($row, ['otp' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + 600, 'sent' => time(), 'tries' => 0]));
    return true;
}
function m_f($v): ?float { return ($v === null || $v === '') ? null : (float)$v; }

function m_point_payload(int $id): ?array {
    $p = owned_point($id);
    if (!$p) return null;
    $meas = q_all('SELECT * FROM measurements WHERE point_id=? ORDER BY spacing_m, measured_at', [$id]);
    $S = build_sounding($meas, (float)cfg('analysis.depth_factor', 0.5));
    $an = q_one('SELECT * FROM analyses WHERE point_id=? ORDER BY id DESC LIMIT 1', [$id]);
    $ai = q_one("SELECT ai_text FROM analyses WHERE point_id=? AND mode='ai' AND ai_text IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]);
    $res = $an ? json_decode((string)$an['result_json'], true) : null;
    if (!is_array($res)) $res = null;
    $plan = aq_plan($p);
    $hasPlan = !empty($p['plan_json']);
    $vt = verdict_tone($an['verdict'] ?? null, true);
    $layersRaw = $res['layers'] ?? (count($S) >= 3 ? aq_layers($S) : []);
    $layers = [];
    foreach ($layersRaw as $L) {
        $layers[] = ['from' => (float)$L['from'], 'to' => (float)$L['to'], 'key' => (string)$L['key'], 'name' => strata_name((string)$L['key']),
                     'color' => strata_color((string)$L['key']), 'rho' => isset($L['rho']) ? (float)$L['rho'] : null];
    }
    $analysis = null;
    if ($an && $res) {
        $best = $res['best'] ?? null;
        $analysis = [
            'score' => (int)$an['score'], 'mode' => (string)$an['mode'], 'ago' => ago($an['created_at']),
            'bestDepth' => $best ? (float)$best['depth'] : null, 'bestRho' => $best ? (float)$best['rho'] : null,
            'bestClass' => $best ? (string)$best['class'] : '',
            'drill' => isset($res['drill_depth']) ? (float)$res['drill_depth'] : null,
            'warnings' => array_values(array_map('strval', $res['warnings'] ?? [])),
            'notes' => array_values(array_map('strval', $res['notes'] ?? [])),
            'ai' => $ai ? (string)$ai['ai_text'] : '',
        ];
    }
    $sounding = [];
    foreach ($S as $x) $sounding[] = ['s' => (float)$x['s'], 'rho' => (float)$x['rho'], 'depth' => (float)$x['depth']];
    $mlist = [];
    foreach (array_slice(array_reverse($meas), 0, 60) as $m) {
        $mlist[] = ['id' => (int)$m['id'], 'spacing' => (float)$m['spacing_m'], 'rho' => (float)$m['rho_a'], 'dv' => m_f($m['dv_mv']), 'i' => m_f($m['i_ma']),
                    'time' => fdate_id($m['measured_at'], true), 'device' => (string)($m['device_id'] ?? '')];
    }
    $waters = [];
    foreach (q_all('SELECT * FROM water_samples WHERE point_id=? AND owner_id=? ORDER BY measured_at DESC LIMIT 5', [$id, current_user_id()]) as $w) {
        $o = water_overall($w);
        $waters[] = ['id' => (int)$w['id'], 'ago' => ago($w['measured_at']), 'label' => $o['short'], 'tone' => $o['tone'] === 'ok' ? 'info' : $o['tone']];
    }
    $lastT = $meas ? max(array_column($meas, 'measured_at')) : $p['created_at'];
    $cfgLabel = $hasPlan ? ($plan['config'] === 'schlumberger' ? 'Schlumberger' : 'Wenner') : ($meas ? ucfirst((string)$meas[0]['config']) : '–');
    return [
        'point' => [
            'id' => (int)$p['id'], 'code' => (string)$p['code'], 'name' => (string)$p['name'],
            'lat' => m_f($p['lat']), 'lng' => m_f($p['lng']), 'notes' => (string)($p['notes'] ?? ''), 'team' => (string)($p['team'] ?? ''),
            'type' => (string)($p['survey_type'] ?? 'geovolt'), 'last' => fdate_id($lastT, true), 'configLabel' => $cfgLabel, 'hasPlan' => $hasPlan,
            'plan' => ['config' => (string)$plan['config'], 'electrodes' => (int)$plan['electrodes'], 'spacing' => (float)$plan['spacing'],
                       'depth' => (float)$plan['depth'], 'orientation' => (string)$plan['orientation']],
        ],
        'verdict' => ['label' => $vt[0], 'tone' => $vt[1]],
        'analysis' => $analysis, 'layers' => $layers, 'sounding' => $sounding, 'measurements' => $mlist, 'waters' => $waters,
        'canAnalyze' => count($S) >= 3,
    ];
}

function m_sample(array $w): array {
    $o = water_overall($w);
    $params = [];
    foreach (water_params($w) as $k => $r) {
        $params[] = ['key' => (string)$k, 'label' => (string)$r['label'], 'unit' => (string)$r['unit'], 'val' => $r['val'] === null ? null : (float)$r['val'],
                     'dec' => (int)$r['dec'], 'limit' => (string)$r['limit'], 'frac' => (float)$r['frac'], 'st' => (string)$r['st'], 'tone' => (string)$r['tone']];
    }
    $place = $w['name'] ?: ($w['code'] ?: 'Sampel #' . $w['id']);
    return ['id' => (int)$w['id'], 'place' => (string)$place, 'ago' => ago($w['measured_at']), 'date' => fdate_id($w['measured_at'], true),
            'params' => $params, 'overall' => ['tone' => $o['tone'], 'title' => $o['title'], 'short' => $o['short']],
            'notes' => water_notes($w), 'fieldNotes' => (string)($w['notes'] ?? '')];
}
function m_next_code(string $type): string {
    $pre = $type === 'water' ? 'KA-' : 'GL-';
    $max = (int)q_val("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) FROM points WHERE code LIKE ?", [$pre . '%']);
    return $pre . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/* ---------- Aksi tanpa login ---------- */
try {
    try { aq_migrate(); } catch (Throwable $e) {}

    if ($action === 'login') {
        m_post_only();
        $u = m_str('username');
        $pw = (string)(m_in()['password'] ?? '');
        if ($u === '' || $pw === '') json_out(['error' => 'Isi email/username dan kata sandi.'], 400);
        $row = q_one('SELECT id,username,password_hash,role FROM users WHERE username=? OR email=? LIMIT 1', [$u, $u]);
        $hash = $row['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
        if (!($row && password_verify($pw, $hash))) { usleep(400000); json_out(['error' => 'Email/username atau kata sandi salah.'], 401); }
        json_out(['token' => m_token((int)$row['id']), 'user' => m_user((int)$row['id'])]);
    }

    if ($action === 'register') {
        m_post_only();
        $name = mb_substr(m_str('name'), 0, 120);
        $email = mb_strtolower(mb_substr(m_str('email'), 0, 160));
        $phone = preg_replace('/[^0-9+\-\s]/', '', m_str('phone'));
        $pw = (string)(m_in()['password'] ?? '');
        if (mb_strlen($name) < 2) json_out(['error' => 'Nama lengkap wajib diisi.'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Format email belum benar.'], 400);
        if ($phone !== '' && strlen(preg_replace('/\D/', '', $phone)) < 8) json_out(['error' => 'Nomor telepon minimal 8 digit.'], 400);
        if (strlen($pw) < 8) json_out(['error' => 'Kata sandi minimal 8 karakter.'], 400);
        if (q_val('SELECT id FROM users WHERE email=? OR username=?', [$email, $email])) json_out(['error' => 'Email ini sudah terdaftar. Silakan masuk.'], 400);
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '', explode('@', $email)[0]) ?: 'user';
        $uname = substr($base, 0, 40);
        $i = 1;
        while (q_val('SELECT id FROM users WHERE username=?', [$uname])) $uname = substr($base, 0, 40) . (++$i);
        $row = ['username' => $uname, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'name' => $name, 'email' => $email, 'phone' => $phone];
        if (otp_enabled()) {
            m_pend_set($email, $row);
            if (!m_send_otp($email)) json_out(['error' => 'Email verifikasi gagal dikirim. Periksa pengaturan email server.'], 500);
            json_out(['otp' => true, 'email' => $email, 'masked' => mask_email($email)]);
        }
        q('INSERT INTO users(username,password_hash,role,full_name,email,phone) VALUES(?,?,?,?,?,?)',
          [$row['username'], $row['hash'], 'viewer', $row['name'], $row['email'], $row['phone'] ?: null]);
        json_out(['ok' => true]);
    }

    if ($action === 'resend_otp') {
        m_post_only();
        $email = mb_strtolower(m_str('email'));
        $pend = m_pend_get($email);
        if (!$pend) json_out(['error' => 'Pendaftaran tidak ditemukan. Mulai dari awal.'], 404);
        if (time() - (int)($pend['sent'] ?? 0) < 60) json_out(['error' => 'Tunggu sebentar sebelum meminta kode baru.'], 429);
        if (!m_send_otp($email)) json_out(['error' => 'Email gagal dikirim. Coba lagi nanti.'], 500);
        json_out(['ok' => true]);
    }

    if ($action === 'verify_otp') {
        m_post_only();
        $email = mb_strtolower(m_str('email'));
        $code = preg_replace('/\D/', '', m_str('code'));
        $pend = m_pend_get($email);
        if (!$pend || empty($pend['otp'])) json_out(['error' => 'Pendaftaran tidak ditemukan. Mulai dari awal.'], 404);
        if (time() > (int)$pend['exp']) json_out(['error' => 'Kode sudah kedaluwarsa. Minta kode baru.'], 400);
        if ((int)$pend['tries'] >= 5) json_out(['error' => 'Terlalu banyak percobaan. Minta kode baru.'], 429);
        if (!password_verify($code, (string)$pend['otp'])) {
            $pend['tries'] = (int)$pend['tries'] + 1;
            m_pend_set($email, $pend);
            json_out(['error' => 'Kode tidak cocok. Periksa lagi email kamu.'], 400);
        }
        if (q_val('SELECT id FROM users WHERE email=?', [$email])) { m_pend_del($email); json_out(['error' => 'Email ini sudah terdaftar. Silakan masuk.'], 400); }
        q('INSERT INTO users(username,password_hash,role,full_name,email,phone) VALUES(?,?,?,?,?,?)',
          [$pend['username'], $pend['hash'], 'viewer', $pend['name'], $pend['email'], ($pend['phone'] ?? '') ?: null]);
        m_pend_del($email);
        json_out(['ok' => true]);
    }

    /* ---------- Aksi yang butuh login ---------- */
    $me = m_auth();
    $uid = (int)$me['id'];

    switch ($action) {
        case 'me':
            json_out(['user' => m_user($uid)]);

        case 'home':
            $points = aq_points_overview();
            $high = count(array_filter($points, fn($p) => $p['verdict'] === 'Tinggi'));
            $devices = aq_devices();
            $active = count(array_filter($devices, fn($d) => $d['active']));
            json_out([
                'user' => m_user($uid), 'greeting' => greeting(), 'region' => aq_region(), 'live' => $active > 0,
                'stats' => ['points' => count($points), 'high' => $high, 'devices' => $active],
                'strata' => m_strata(), 'feed' => array_map('m_feed_item', aq_feed(null, '', 4)), 'unread' => aq_unread_count(),
            ]);

        case 'feed':
            $type = in_array($_GET['type'] ?? '', ['geo', 'water'], true) ? (string)$_GET['type'] : '';
            $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
            $all = aq_feed(null, '', 300);
            $nGeo = count(array_filter($all, fn($i) => $i['kind'] === 'geo'));
            json_out(['counts' => ['all' => count($all), 'geo' => $nGeo, 'water' => count($all) - $nGeo],
                      'items' => array_map('m_feed_item', aq_feed($type ?: null, $q, 120))]);

        case 'point':
            $d = m_point_payload((int)($_GET['id'] ?? 0));
            if (!$d) json_out(['error' => 'Titik tidak ditemukan.'], 404);
            json_out($d);

        case 'analyze':
            m_post_only();
            $pid = (int)(m_in()['id'] ?? 0);
            if (!owned_point($pid)) json_out(['error' => 'Titik tidak ditemukan.'], 404);
            $n = (int)q_val('SELECT COUNT(DISTINCT spacing_m) FROM measurements WHERE point_id=?', [$pid]);
            if ($n < 3) json_out(['error' => 'Butuh minimal 3 jarak elektroda berbeda (saat ini ' . $n . ').'], 400);
            $mode = m_str('mode') === 'ai' ? 'ai' : 'rule';
            @set_time_limit(60);
            analyze_point($pid, $mode);
            json_out(m_point_payload($pid));

        case 'live':
            $pid = (int)($_GET['id'] ?? 0);
            if (!owned_point($pid)) json_out(['error' => 'Titik tidak ditemukan.'], 404);
            $L = aq_live_geo($pid);
            $mm = q_all('SELECT spacing_m, rho_a FROM measurements WHERE point_id=? ORDER BY spacing_m, measured_at', [$pid]);
            $S = build_sounding($mm, (float)cfg('analysis.depth_factor', 0.5));
            $lay = [];
            foreach ((count($S) >= 3 ? aq_layers($S) : []) as $x) {
                $lay[] = ['from' => (float)$x['from'], 'to' => (float)$x['to'], 'key' => (string)$x['key'], 'name' => strata_name((string)$x['key']),
                          'color' => strata_color((string)$x['key']), 'rho' => isset($x['rho']) ? (float)$x['rho'] : null];
            }
            $lt = $L['latest'];
            json_out(['device' => $L['device'], 'done' => (int)$L['done'], 'total' => (int)$L['total'],
                      'latest' => $lt ? ['i' => m_f($lt['i_ma']), 'dv' => m_f($lt['dv_mv']), 'rho' => m_f($lt['rho_a'])] : null,
                      'layers' => $lay]);

        case 'water':
            $sid = (int)($_GET['id'] ?? 0);
            $pid = (int)($_GET['point'] ?? 0);
            if ($pid && !owned_point($pid)) json_out(['error' => 'Titik tidak ditemukan.'], 404);
            $L = aq_live_water($pid);
            if (!$sid) $sid = (int)($L['sample']['id'] ?? 0);
            $w = $sid ? q_one('SELECT w.*, p.code, p.name FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.id=? AND w.owner_id=?', [$sid, current_user_id()]) : null;
            $recent = [];
            foreach (q_all('SELECT w.*, p.code, p.name FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.owner_id=? ORDER BY w.measured_at DESC, w.id DESC LIMIT 15', [current_user_id()]) as $r) {
                $o = water_overall($r);
                $recent[] = ['id' => (int)$r['id'], 'place' => (string)($r['name'] ?: ($r['code'] ?: 'Sampel #' . $r['id'])), 'ago' => ago($r['measured_at']),
                             'label' => $o['short'], 'tone' => $o['tone'] === 'ok' ? 'info' : $o['tone']];
            }
            $pts = [];
            foreach (q_all('SELECT id,code,name FROM points WHERE owner_id=? ORDER BY id DESC LIMIT 100', [current_user_id()]) as $r) $pts[] = ['id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name']];
            json_out(['device' => $L['device'], 'sample' => $w ? m_sample($w) : null, 'points' => $pts, 'recent' => $recent]);

        case 'water_note':
            m_post_only();
            $sid = (int)(m_in()['id'] ?? 0);
            if (!$sid || !q_val('SELECT id FROM water_samples WHERE id=? AND owner_id=?', [$sid, current_user_id()])) json_out(['error' => 'Sampel tidak ditemukan.'], 404);
            q('UPDATE water_samples SET notes=? WHERE id=? AND owner_id=?', [mb_substr(m_str('notes'), 0, 500) ?: null, $sid, current_user_id()]);
            json_out(['ok' => true]);

        case 'device':
            $devs = [];
            foreach (aq_devices() as $d) {
                $devs[] = ['id' => (string)$d['id'], 'kind' => (string)$d['kind'], 'active' => (bool)$d['active'], 'ago' => ago($d['last']), 'n' => (int)$d['n'],
                           'battery' => $d['battery'] === null ? null : (float)$d['battery'], 'rssi' => $d['rssi'] === null ? null : (float)$d['rssi'],
                           'water' => (bool)$d['water'], 'geo' => (bool)$d['geo']];
            }
            json_out(['devices' => $devs]);

        case 'notifications':
            $items = [];
            foreach (aq_notifications(40) as $i) {
                [$t, $ref] = m_target((string)$i['href']);
                $items[] = ['tone' => (string)$i['tone'], 'icon' => (string)$i['icon'], 'title' => (string)$i['title'], 'sub' => (string)$i['sub'],
                            'unread' => (bool)$i['unread'], 'day' => day_label((string)$i['t']), 'target' => $t, 'ref' => $ref];
            }
            json_out(['items' => $items]);

        case 'notifications_read':
            m_post_only();
            state_set('notif_read_' . $uid, date('Y-m-d H:i:s'));
            json_out(['ok' => true]);

        case 'points':
            $out = [];
            foreach (aq_points_overview() as $p) {
                if ($p['lat'] === null || $p['lng'] === null) continue;
                $out[] = ['id' => (int)$p['id'], 'code' => (string)$p['code'], 'name' => (string)$p['name'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'],
                          'label' => verdict_tone($p['verdict'], true)[0], 'tone' => verdict_tone($p['verdict'])[1], 'color' => verdict_pin_color($p['verdict']),
                          'depth' => $p['best_depth'] !== null ? round((float)$p['best_depth'], 1) : null,
                          'rho' => $p['best_rho'] !== null ? round((float)$p['best_rho']) : null,
                          'score' => $p['score'] !== null ? (int)$p['score'] : null];
            }
            json_out(['points' => $out]);

        case 'survey_create':
            m_post_only();
            $name = mb_substr(m_str('name'), 0, 120);
            $coord = m_str('coord');
            $type = m_str('type') === 'water' ? 'water' : 'geovolt';
            $team = mb_substr(m_str('team'), 0, 160);
            $notes = mb_substr(m_str('notes'), 0, 2000);
            $lat = $lng = null;
            if ($coord !== '') {
                if (preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d+(?:[.,]\d+)?)\s*$/', $coord, $m)) {
                    $lat = (float)str_replace(',', '.', $m[1]);
                    $lng = (float)str_replace(',', '.', $m[2]);
                } else json_out(['error' => 'Format koordinat: lintang, bujur — contoh -5.5483, 95.3238.'], 400);
            }
            if ($name === '') json_out(['error' => 'Nama lokasi / proyek wajib diisi.'], 400);
            if (($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) json_out(['error' => 'Koordinat di luar rentang.'], 400);
            $code = m_next_code($type);
            q('INSERT INTO points(owner_id,code,name,lat,lng,notes,survey_type,team) VALUES(?,?,?,?,?,?,?,?)', [$uid, $code, $name, $lat, $lng, $notes ?: null, $type, $team ?: null]);
            json_out(['id' => (int)pdo()->lastInsertId(), 'code' => $code, 'type' => $type]);

        case 'survey_config':
            m_post_only();
            $pid = (int)(m_in()['id'] ?? 0);
            if (!owned_point($pid)) json_out(['error' => 'Titik tidak ditemukan.'], 404);
            $cfgName = m_str('config') === 'schlumberger' ? 'schlumberger' : 'wenner';
            $n = (int)m_num('electrodes');
            $sp = m_num('spacing');
            $dp = m_num('depth');
            $ori = m_str('orientation') === 'Timur–Barat' ? 'Timur–Barat' : 'Utara–Selatan';
            if ($n < 4 || $n > 64 || $sp === null || $sp < 0.5 || $sp > 100 || $dp === null || $dp < 0.05 || $dp > 300) {
                json_out(['error' => 'Periksa nilai: elektroda 4–64, spasi 0,5–100 m, kedalaman 0,05–300 m.'], 400);
            }
            q('UPDATE points SET plan_json=? WHERE id=?', [json_encode(['config' => $cfgName, 'electrodes' => $n, 'spacing' => $sp, 'depth' => $dp, 'orientation' => $ori], JSON_UNESCAPED_UNICODE), $pid]);
            json_out(['ok' => true]);

        case 'profile_update':
            m_post_only();
            $name = mb_substr(m_str('name'), 0, 120);
            $email = mb_strtolower(mb_substr(m_str('email'), 0, 160));
            $phone = preg_replace('/[^0-9+\-\s]/', '', m_str('phone'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Format email belum benar.'], 400);
            if ($email !== '' && q_val('SELECT id FROM users WHERE email=? AND id<>?', [$email, $uid])) json_out(['error' => 'Email dipakai akun lain.'], 400);
            q('UPDATE users SET full_name=?, email=?, phone=? WHERE id=?', [$name ?: null, $email ?: null, $phone ?: null, $uid]);
            json_out(['user' => m_user($uid)]);

        case 'settings':
            json_out(['services' => [
                ['name' => 'Firebase Realtime Database', 'on' => (bool)fb_configured()],
                ['name' => 'Analisa AI (Gemini)', 'on' => (bool)gemini_ready()],
                ['name' => 'Verifikasi email (OTP)', 'on' => (bool)otp_enabled()],
                ['name' => 'Token ingest perangkat', 'on' => (string)cfg('ingest.token', '') !== ''],
            ]]);

        default:
            json_out(['error' => 'Aksi tidak dikenal.'], 404);
    }
} catch (Throwable $e) {
    error_log('Aquify mobile API: ' . $e->getMessage());
    json_out(['error' => 'Terjadi kesalahan di server. Periksa log PHP/Apache.'], 500);
}
