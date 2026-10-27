<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$pts = q_all("SELECT p.id,p.code,p.name,
    (SELECT COUNT(*) FROM measurements m WHERE m.point_id=p.id) AS n,
    (SELECT MAX(m.measured_at) FROM measurements m WHERE m.point_id=p.id) AS t
  FROM points p WHERE p.owner_id=? ORDER BY t IS NULL, t DESC, p.id DESC LIMIT 100", [current_user_id()]);
$pts = array_values(array_filter($pts, fn($r) => (int)$r['n'] > 0 || true));
$id = (int)($_GET['point'] ?? $_POST['id'] ?? 0);
if (!$id && $pts) $id = (int)$pts[0]['id'];
$p = owned_point($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = post_str('do');
    if ($do === 'sync') {
        $r = aq_pull_firebase();
        if (!$r['configured'] || $r['error']) flash($r['error'] ?: 'Sinkron gagal.', 'err');
        else flash($r['inserted'] > 0 ? $r['inserted'] . ' data baru masuk dari Firebase ke database.' : 'Tidak ada data baru di Firebase.');
        redirect('geolistrik.php?point=' . $id);
    }
    if ($do === 'pipeline' && $p) {
        @set_time_limit(240);
        $r = aq_pull_firebase();
        $msgs = [];
        if ($r['configured'] && !$r['error']) $msgs[] = $r['inserted'] . ' data baru dari Firebase';
        elseif ($r['configured']) flash('Firebase: ' . $r['error'] . ' (analisis memakai data yang sudah ada di database).', 'err');
        $n = (int)q_val('SELECT COUNT(DISTINCT spacing_m) FROM measurements WHERE point_id=?', [$id]);
        if ($n < 3) {
            flash(($msgs ? implode(', ', $msgs) . '. ' : '') . 'Titik ' . $p['code'] . ' baru punya ' . $n . ' jarak elektroda; butuh minimal 3 untuk dianalisis. Lanjutkan pengukuran lalu coba lagi.', 'err');
            redirect('geolistrik.php?point=' . $id);
        }
        $mode = gemini_ready() ? 'ai' : 'rule';
        session_write_close();
        $out = analyze_point($id, $mode);
        session_start();
        $aiErr = $out['result']['ai_error'] ?? null;
        if ($mode === 'ai' && $aiErr) flash(implode(', ', $msgs) . ($msgs ? '. ' : '') . 'Analisis aturan tersimpan, tetapi AI gagal: ' . $aiErr, 'err');
        else flash(($msgs ? ucfirst(implode(', ', $msgs)) . '. ' : '') . ($mode === 'ai' ? 'Data diolah dan dianalisis AI.' : 'Data diolah (AI belum aktif: isi gemini.api_key di config.php).'));
        redirect('analysis-view.php?id=' . $id);
    }
    redirect('geolistrik.php?point=' . $id);
}

$L = $p ? aq_live_geo($id) : null;
$rows = $p ? q_all('SELECT spacing_m FROM measurements WHERE point_id=? ORDER BY spacing_m', [$id]) : [];
$S = $p ? build_sounding(q_all('SELECT spacing_m, rho_a FROM measurements WHERE point_id=? ORDER BY spacing_m, measured_at', [$id]), (float)cfg('analysis.depth_factor', 0.5)) : [];
$meas = $p ? q_all('SELECT spacing_m,dv_mv,i_ma,rho_a,measured_at,source FROM measurements WHERE point_id=? ORDER BY measured_at DESC, id DESC LIMIT 15', [$id]) : [];
$nTotal = $p ? (int)q_val('SELECT COUNT(*) FROM measurements WHERE point_id=?', [$id]) : 0;
$an = $p ? q_one('SELECT * FROM analyses WHERE point_id=? ORDER BY id DESC LIMIT 1', [$id]) : null;
$aiRow = $p ? q_one("SELECT * FROM analyses WHERE point_id=? AND mode='ai' AND ai_text IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]) : null;
$aiDec = $aiRow ? aq_ai_decode((string)$aiRow['ai_text']) : null;
$lastSync = state_get('last_sync_at');
$syncs = q_all('SELECT source,started_at,inserted,error FROM sync_log WHERE user_id=? ORDER BY id DESC LIMIT 4', [current_user_id()]);

page_head('Pengukuran Tahanan Tanah', 'geo', '', ['wide' => true]);
app_bar('Pengukuran Tahanan Tanah', 'index.php');

$fbOn = fb_configured();
$pipe = [
    ['Firebase', $fbOn ? ($lastSync ? 'Sinkron ' . ago($lastSync) : 'Siap, belum sinkron') : 'Belum diatur', $fbOn ? 'done' : 'off', 'refresh'],
    ['Database', $p ? $nTotal . ' data · ' . $L['done'] . ' jarak' : 'Belum ada titik', $nTotal > 0 ? 'done' : '', 'cpu'],
    ['Olah data', $an ? 'Skor ' . (int)$an['score'] . ' · ' . $an['verdict'] : 'Belum diolah', $an ? 'done' : '', 'activity'],
    ['Analisa AI', gemini_ready() ? ($aiDec ? 'Selesai ' . ago($aiRow['created_at']) : 'Belum dijalankan') : 'Belum diaktifkan', $aiDec ? 'done' : (gemini_ready() ? '' : 'off'), 'sparkles'],
];
?>
<section class="geo-intro">
  <span class="geo-intro-mark"><?= icon('activity', 22) ?></span>
  <div><span class="geo-eyebrow">GEOLISTRIK · PROFIL BAWAH PERMUKAAN</span><h2>Kenali lapisan tanah di lokasi survei</h2><p>Pantau pengukuran, lihat sebaran resistivitas, lalu jalankan analisis potensi air tanah.</p></div>
  <span class="geo-intro-badge"><?= icon('pin', 15) ?> <?= e($p['code'] ?? 'Belum ada titik') ?></span>
</section>

<section class="geo-section">
<div class="geo-section-title"><div><span class="geo-eyebrow">ALUR DATA</span><h2>Status pengolahan</h2></div><span class="geo-step-note">Tahapan analisis</span></div>
<div class="pipe" aria-label="Alur pengolahan data">
<?php foreach ($pipe as $i => [$t, $d, $st, $ic]): ?>
  <div class="pn <?= $st ?>"><span class="pn-ic"><?= icon($ic, 20) ?></span><div><b><?= e($t) ?></b><small><?= e($d) ?></small></div><?php if ($i < 3): ?><?= icon('chev-right', 16, 'pn-arrow') ?><?php endif; ?></div>
<?php endforeach; ?>
</div>
</section>

<?php if (!$p): ?>
  <div class="empty"><b>Belum ada titik pengukuran</b>Buat titik survey dulu. Setelah ESP32 mengirim data dengan kode titik itu, datanya ditarik dari Firebase ke sini.<p><a class="btn sm" href="survey-new.php">Buat survey baru</a></p></div>
<?php page_foot(); exit; endif; ?>

<form method="get" class="field geo-point-select"><label for="pointSel"><span>Pilih titik pengukuran</span><small><?= count($pts) ?> titik tersedia</small></label>
  <div class="select-row"><select id="pointSel" name="point" onchange="this.form.submit()"><?php foreach ($pts as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)$r['id'] === $id ? ' selected' : '' ?>><?= e($r['code'] . ' — ' . $r['name'] . ' (' . (int)$r['n'] . ' data)') ?></option><?php endforeach; ?></select><?= icon('chev-down', 18) ?></div>
  <noscript><button class="btn sm" type="submit">Tampilkan</button></noscript></form>

<section class="card geo-cta">
  <div class="geo-cta-copy"><span class="geo-cta-icon"><?= icon('sparkles', 20) ?></span><div><span class="geo-eyebrow">ANALISIS OTOMATIS</span><h2>Tentukan potensi air tanah</h2><p>Tarik data terbaru, susun profil lapisan, dan dapatkan interpretasi analisis dalam satu langkah.</p></div></div>
  <div class="geo-cta-actions"><form method="post" data-overlay="Menarik data dari Firebase, mengolahnya, lalu AI menganalisis. Biasanya 15–60 detik, jangan tutup halaman…">
    <?= csrf_field() ?><input type="hidden" name="do" value="pipeline"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn teal block" type="submit"><?= icon('sparkles', 18) ?> Tarik data &amp; analisis</button>
  </form><div class="geo-secondary-actions">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="sync"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn outline sm block" type="submit"<?= $fbOn ? '' : ' disabled' ?>><?= icon('refresh', 16) ?> Tarik data saja</button></form>
    <a class="btn outline sm" href="survey-run.php?id=<?= $id ?>#manual"><?= icon('pencil', 16) ?> Input manual</a>
  </div></div>
  <div class="geo-cta-foot"><span><?= icon('info', 15) ?> Kode perangkat untuk titik ini: <code>POINT <?= e($p['code']) ?></code></span>
    <?php if (!$fbOn): ?><span class="geo-config-note">Firebase belum diatur; isi <code>firebase.db_url</code> dan <code>firebase.secret</code> di config.php.</span><?php endif; ?>
  </div>
</section>

<section class="card geo-live-card" data-live="geo" data-point="<?= $id ?>" data-done="<?= (int)$L['done'] ?>">
  <div class="geo-section-title"><div><span class="geo-eyebrow">PEMANTAUAN LANGSUNG</span><h2>Kondisi pengukuran</h2></div>
  <div id="conn" class="conn<?= ($L['device']['active'] ?? false) ? '' : ' off' ?>"><span class="d"></span><span class="ct"><?= $L['device'] ? (($L['device']['active']) ? 'Terhubung — ' . e($L['device']['id']) : 'Terakhir aktif ' . e($L['device']['ago']) . ' — ' . e($L['device']['id'])) : 'Menunggu data perangkat…' ?></span><a href="device.php">Perangkat</a></div>
  </div>
  <div id="newData" class="alert info" hidden><?= icon('info', 18) ?><span>Data baru masuk. <a href="geolistrik.php?point=<?= $id ?>"><b>Muat ulang</b></a> untuk melihat tabel dan kurva terbaru.</span></div>
  <?php $lat = $L['latest']; ?>
  <div class="metrics3">
    <div><span class="metric-icon"><?= icon('zap', 19) ?></span><b id="lvI"><?= $lat ? fnum($lat['i_ma'], 1) : '–' ?></b><span>Arus <small>mA</small></span></div>
    <div><span class="metric-icon"><?= icon('timer', 19) ?></span><b id="lvV"><?= $lat ? fnum($lat['dv_mv'], 0) : '–' ?></b><span>Tegangan <small>mV</small></span></div>
    <div><span class="metric-icon"><?= icon('activity', 19) ?></span><b id="lvR"><?= $lat ? fnum($lat['rho_a'], 1) : '–' ?></b><span>Tahanan semu <small>Ω·m</small></span></div>
  </div>
  <div class="progress-h"><b>Progres jarak elektroda</b><span id="lvProg">Titik <?= (int)$L['done'] ?> / <?= (int)$L['total'] ?></span></div>
  <div class="bar geo-progress"><i id="lvBar" style="width:<?= min(100, $L['done'] / max(1, $L['total']) * 100) ?>%"></i></div>
</section>

<?php
$layers = count($S) >= 3 ? aq_layers($S) : [];
$resA = $an ? json_decode((string)$an['result_json'], true) : null;
$plan = $L['plan'];
$secOpt = ['electrodes' => (int)$plan['electrodes'], 'length' => aq_plan_length($plan)];
$rb = $aiDec['data']['rekomendasi_bor'] ?? null;
if ($rb && ($rb['kedalaman_maks_m'] ?? null) !== null && $rb['keputusan'] !== 'belum_cukup_data') $secOpt += ['drill_min' => $rb['kedalaman_min_m'], 'drill_max' => $rb['kedalaman_maks_m']];
elseif (!empty($resA['drill_depth'])) $secOpt += ['drill' => $resA['drill_depth']];
$pseudo = aq_pseudo_data($id);
?>
<?php $df = (float)cfg('analysis.depth_factor', 0.5); $set = aq_measured_cells($id); $cells = aq_plan_cells($plan); $nextCell = aq_next_cell($plan, $set);
$s2dOpt = ['depth_factor' => $df, 'electrodes' => (int)$plan['electrodes'], 'spacing' => (float)$plan['spacing'], 'length' => aq_plan_length($plan)] + (isset($secOpt['drill']) ? ['drill_max' => $secOpt['drill']] : (isset($secOpt['drill_max']) ? ['drill_max' => $secOpt['drill_max']] : [])); ?>
<section class="card viz-card"><div class="viz-h"><h3>Penampang tahanan tanah</h3><span class="bdg bdg-mute">Model lapisan · seragam ke samping</span></div>
  <div class="secwrap" id="lvStrata"><?= aq_section_svg($layers, $secOpt) ?></div>
  <?= aq_section_extras($layers) ?>
  <?php if (!$layers): ?><p class="muted" style="margin:8px 0 0">Penampang tampil setelah minimal 3 jarak elektroda terukur.</p><?php endif; ?>
  <p class="hint">Warna menunjukkan perkiraan tahanan tiap lapisan dari kurva sounding, tanpa inversi 2D. Lapisan dianggap sama ke arah samping.</p></section>
<section class="card viz-card viz-2d-card"><div class="viz-h"><div><span class="viz-kicker">PROFIL LINTASAN</span><h3>Kurva geolistrik 2D</h3></div><span class="bdg bdg-info">Interpolasi data ukur</span></div>
  <?php if ($pseudo): ?>
  <p class="viz-lead">Perubahan tahanan semu sepanjang lintasan dan terhadap kedalaman</p>
  <div class="plot-stage"><?= aq_section2d_svg($pseudo, $s2dOpt) ?></div>
  <div class="viz-caption"><span><i class="legend-dot low"></i>Tahanan rendah</span><span><i class="legend-dot mid"></i>Potensi air</span><span><i class="legend-dot high"></i>Tahanan tinggi</span></div>
  <p class="hint">Garis putih menandai zona yang diperkirakan berpotensi air. Penampang ini interpolasi data ukur, bukan hasil inversi 2D; kedalaman dan nilai masih perkiraan.</p>
  <?php else: ?>
  <div class="viz-empty"><span class="viz-empty-icon"><?= icon('activity', 20) ?></span><div><b>Data lintasan belum cukup</b><p>Tambahkan pengukuran dengan posisi (POS) di minimal 3 lokasi dan 2 variasi jarak elektroda untuk membentuk penampang 2D.</p></div></div>
  <?php endif; ?>
  <details class="acc sounding-acc"><summary><span><b>Sounding 1D</b><small>Ringkasan tahanan terhadap jarak elektroda</small></span><?= icon('chev-down', 18) ?></summary><div class="in">
    <div class="sounding-chart"><?= aq_curve_svg($S) ?></div>
    <p>Grafik ini merangkum satu titik ukur. Berbeda dari penampang 2D, sounding tidak menunjukkan perubahan ke samping sepanjang lintasan.</p>
  </div></details>
</section>

<?php if ($cells): ?>
<details class="fold viz-fold"<?= $pseudo ? '' : ' open' ?>><summary>Peta pengukuran lintasan 2D (<?= count($set) ?>/<?= count($cells) ?> titik terukur) <?= icon('chev-down', 18) ?></summary><div class="in">
  <p class="muted" style="margin-top:0">Penampang 2D seperti RES2DINV butuh pengukuran di banyak posisi sepanjang lintasan. Tiap lingkaran adalah satu pengukuran Wenner; lingkaran hijau sudah terukur.</p>
  <div class="secwrap"><?= aq_grid_plan_svg($plan, $set, $df) ?></div>
  <p class="hint"><?= $nextCell ? 'Berikutnya: jarak a = <b>' . fnum($nextCell['a'], 1) . ' m</b> di posisi x = <b>' . fnum($nextCell['x'], 1) . ' m</b>. Kirim dari ESP32 (<code>SPACING</code> dan <code>POS</code>) atau <a href="survey-run.php?id=' . $id . '#manual">input manual</a>.' : 'Semua titik rencana sudah terukur.' ?></p>
</div></details>
<?php endif; ?>

<?php if ($an): [$vl, $vt] = verdict_tone($an['verdict'], true); ?>
<section class="card geo-result"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><h3 style="margin:0">Hasil terakhir</h3><?= badge($vl, $vt) ?></div>
  <p class="hint" style="margin:6px 0 10px">Skor potensi <b class="mono"><?= (int)$an['score'] ?>/100</b><?= $an['best_depth'] !== null ? ' · target ±' . fnum($an['best_depth'], 1) . ' m' : '' ?> · <?= e(ago($an['created_at'])) ?></p>
  <?php if ($aiDec && $aiDec['data']['ringkasan'] !== ''): ?><p style="margin:0 0 12px"><?= e($aiDec['data']['ringkasan']) ?></p><?php endif; ?>
  <a class="btn sm" href="analysis-view.php?id=<?= $id ?>"><?= icon('sparkles', 16) ?> Buka Analisa AI lengkap</a></section>
<?php endif; ?>

<details class="fold geo-data-fold"<?= $meas ? ' open' : '' ?>><summary>Data pengukuran terbaru (<?= $nTotal ?>) <?= icon('chev-down', 18) ?></summary><div class="in">
<?php if ($meas): ?><div class="tbl-wrap"><table>
  <thead><tr><th class="r">Jarak (m)</th><th class="r">ΔV (mV)</th><th class="r">I (mA)</th><th class="r">ρa (Ω·m)</th><th>Waktu</th><th>Sumber</th></tr></thead><tbody>
  <?php foreach ($meas as $m): ?><tr><td class="r num"><?= fnum($m['spacing_m'], 2) ?></td><td class="r num"><?= fnum($m['dv_mv'], 2) ?></td><td class="r num"><?= fnum($m['i_ma'], 2) ?></td><td class="r num"><?= fnum($m['rho_a'], 1) ?></td><td><?= fdt($m['measured_at']) ?></td><td><?= e($m['source']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p class="muted" style="margin:0">Belum ada data untuk titik ini.</p><?php endif; ?>
</div></details>

<?php if ($syncs): ?><details class="fold"><summary>Aktivitas sinkron Firebase <?= icon('chev-down', 18) ?></summary><div class="in"><div class="tbl-wrap"><table><thead><tr><th>Waktu</th><th>Sumber</th><th class="r">Data baru</th><th>Status</th></tr></thead><tbody>
<?php foreach ($syncs as $s): ?><tr><td><?= fdt($s['started_at']) ?></td><td><?= e($s['source']) ?></td><td class="r num"><?= (int)$s['inserted'] ?></td><td><?= $s['error'] ? '<span class="bdg bdg-bad">Gagal</span>' : '<span class="bdg bdg-ok">OK</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></details><?php endif; ?>
<?php page_foot();
