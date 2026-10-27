<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $demoPoints = q_all("SELECT id,code,name,(SELECT COUNT(*) FROM measurements m WHERE m.point_id=p.id) AS n FROM points p WHERE p.owner_id=? AND p.code LIKE 'DEMO-%' ORDER BY p.code", [current_user_id()]);
    page_head('Simulasi Pengukuran', 'demo');
    app_bar('Simulasi Pengukuran', 'index.php'); ?>
    <section class="panel">
      <h2>Coba penampang geolistrik 2D</h2>
      <p>Simulasi membuat pengukuran contoh di beberapa posisi lintasan dan menampilkan penampang 2D yang dihaluskan. Data ini hanya untuk belajar, bukan hasil ukur lapangan atau hasil inversi geofisika.</p>
      <form method="post" data-confirm="Buat data geolistrik simulasi? Data contoh akan tersimpan di database, tetapi bukan hasil ukur lapangan.">
        <?= csrf_field() ?><input type="hidden" name="do" value="create">
        <button class="btn teal" type="submit">Buat simulasi 2D &amp; lihat penampang</button>
      </form>
    </section>
    <?php if ($demoPoints): ?>
    <section class="panel">
      <h2>Data simulasi tersimpan</h2>
      <?php foreach ($demoPoints as $dp): ?>
        <a class="li" href="point.php?id=<?= (int)$dp['id'] ?><?= $dp['code'] === 'DEMO-2D' ? '#penampang-2d' : '' ?>"><span class="li-b"><b><?= e($dp['code']) ?> · <?= e($dp['name']) ?></b><small><?= (int)$dp['n'] ?> pengukuran contoh<?= $dp['code'] === 'DEMO-2D' ? ' · buka penampang 2D' : '' ?></small></span><?= icon('chev-right', 18) ?></a>
      <?php endforeach; ?>
      <form method="post" style="margin-top:14px" data-confirm="Hapus semua titik dan pengukuran simulasi DEMO? Data survey asli tidak dihapus.">
        <?= csrf_field() ?><input type="hidden" name="do" value="delete">
        <button class="btn ghost sm" type="submit">Hapus semua data simulasi</button>
      </form>
    </section>
    <?php endif; ?>
    <?php page_foot(); exit;
}

csrf_check();

if (post_str('do') === 'delete') {
    q("DELETE FROM points WHERE code LIKE 'DEMO-%' AND owner_id=?", [current_user_id()]);
    flash('Semua data simulasi dihapus.');
    redirect('index.php');
}
if (post_str('do') !== 'create') {
    flash('Permintaan simulasi tidak valid.', 'err');
    redirect('index.php');
}

// Tiga profil bawah permukaan sintetis: [rho lapisan 1, 2, 3, tebal 1, tebal 2, lintang, bujur, nama]
$profiles = [
    ['DEMO-01', 'Simulasi: akuifer tawar',    [120, 45, 400], 4, 14, -6.9147, 107.6098],
    ['DEMO-02', 'Simulasi: lempung/asin',      [60, 6, 20],   3, 20, -6.9301, 107.6212],
    ['DEMO-03', 'Simulasi: batuan kering',     [200, 350, 900], 5, 12, -6.9022, 107.6355],
];
$spacings = [1, 2, 3, 5, 8, 12, 18, 27];
mt_srand(42);
$n = 0;
foreach ($profiles as [$code, $name, $r, $h1, $h2, $lat, $lng]) {
    if (q_val('SELECT id FROM points WHERE code=?', [$code])) continue;
    q('INSERT INTO points(owner_id,code,name,lat,lng,notes) VALUES(?,?,?,?,?,?)', [current_user_id(), $code, $name, $lat, $lng, 'Data simulasi untuk demo, bukan hasil ukur.']);
    $pid = (int)pdo()->lastInsertId();
    foreach ($spacings as $a) {
        $d = 0.5 * $a;                                  // kedalaman efektif kasar
        $w1 = max(0.0, 1 - $d / $h1);
        $w3 = min(1.0, max(0.0, ($d - $h1) / max(1.0, $h2)));
        $w2 = max(0.0, 1 - $w1 - $w3);
        $lnr = ($w1 * log($r[0]) + $w2 * log($r[1]) + $w3 * log($r[2])) / max(0.0001, $w1 + $w2 + $w3);
        foreach ([0, 1] as $rep) {
            $rho = exp($lnr) * (1 + (mt_rand(-40, 40) / 1000));
            $i = 10.0;
            $dv = $rho / (2 * M_PI * $a) * $i;
            $ts = date('Y-m-d H:i:s', time() - mt_rand(600, 7200));
            ingest_measurement('demo_' . $code . '_' . $a . '_' . $rep, ['point' => $code, 'config' => 'wenner', 'spacing' => $a, 'dv_mv' => round($dv, 3), 'i_ma' => $i, 'device' => 'DEMO', 'ts' => $ts], ['source' => 'demo']);
            $n++;
        }
    }
    ingest_water('demo_w_' . $code, ['point' => $code, 'tds' => $code === 'DEMO-02' ? 2400 : 320, 'ph' => 7.1, 'temp' => 28.5, 'device' => 'DEMO'], ['source' => 'demo']);
    analyze_point($pid, 'rule');
}
// Lintasan 2D simulasi (Wenner, 25 elektroda, spasi 3 m): model sintetis lalu maju-hitung dengan kernel sederhana.
if (!q_val("SELECT id FROM points WHERE code='DEMO-2D'")) {
    q('INSERT INTO points(owner_id,code,name,lat,lng,notes,survey_type,team,plan_json) VALUES(?,?,?,?,?,?,?,?,?)', [current_user_id(), 'DEMO-2D', 'Simulasi: lintasan 2D', -6.9250, 107.6050, 'Data simulasi untuk demo, bukan hasil ukur.', 'geovolt', 'Simulasi',
        json_encode(['config' => 'wenner', 'electrodes' => 25, 'spacing' => 3, 'depth' => 15, 'orientation' => 'Utara–Selatan'], JSON_UNESCAPED_UNICODE)]);
    $pid2 = (int)pdo()->lastInsertId();
    $trueRho = function (float $x, float $z): float {
        $r = $z < 2 ? 380 : ($z < 6 ? 75 : ($z < 11 ? 5 : 130));
        if ($z >= 2 && (abs($x - 24) < 2.2 || abs($x - 48) < 2.2)) $r = 9;
        if ($x > 54 && $x < 66 && $z > 3 && $z < 8) $r = 1400;
        return $r;
    };
    mt_srand(7);
    for ($k = 1; 3 * $k <= 24; $k++) {
        $a = 3.0 * $k; $zp = 0.519 * $a;
        for ($i = 0; $i + 3 * $k <= 24; $i++) {
            $x = 1.5 * $a + 3 * $i; $sw = 0; $sl = 0;
            for ($xi = $x - 2.6 * $a; $xi <= $x + 2.6 * $a; $xi += 1.5) for ($zj = 0.25; $zj <= $zp * 2.6 + 1; $zj += 0.5) {
                $w = exp(-0.5 * pow(($xi - $x) / (1.2 * $a), 2)) * exp(-0.5 * pow(($zj - $zp) / (0.55 * $zp + 0.3), 2));
                $sw += $w; $sl += $w * log($trueRho($xi, $zj));
            }
            $rho = exp($sl / $sw) * (1 + mt_rand(-20, 20) / 1000); $cur = 10.0;
            ingest_measurement('demo_2d_' . $a . '_' . $i, ['point' => 'DEMO-2D', 'config' => 'wenner', 'spacing' => $a, 'x' => round($x, 2), 'dv_mv' => round($rho / (2 * M_PI * $a) * $cur, 3), 'i_ma' => $cur, 'device' => 'DEMO'], ['source' => 'demo']);
            $n++;
        }
    }
    analyze_point($pid2, 'rule');
}
$demoId = q_val('SELECT id FROM points WHERE code=? AND owner_id=?', ['DEMO-2D', current_user_id()]);
flash($n ? "Data simulasi dibuat ($n pengukuran, termasuk satu lintasan 2D). Data ini bukan hasil ukur lapangan." : 'Data simulasi sudah ada. Data ini bukan hasil ukur lapangan.');
if ($demoId) redirect('point.php?id=' . (int)$demoId . '#penampang-2d');
redirect('index.php');
