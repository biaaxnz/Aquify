<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p = owned_point($id);
if (!$p) { flash('Titik tidak ditemukan.', 'err'); redirect('points.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_admin();
    $do = post_str('do');
    if ($do === 'update') {
        $lat = post_num('lat'); $lng = post_num('lng');
        if (($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) flash('Koordinat di luar rentang.', 'err');
        else {
            q('UPDATE points SET name=?,lat=?,lng=?,notes=? WHERE id=?', [mb_substr(post_str('name'), 0, 120) ?: $p['code'], $lat, $lng, post_str('notes') ?: null, $id]);
            flash('Titik diperbarui.');
        }
    } elseif ($do === 'add') {
        $cfgName = post_str('config') === 'schlumberger' ? 'schlumberger' : 'wenner';
        $row = ['point' => $p['code'], 'config' => $cfgName, 'spacing' => post_num('spacing'), 'mn' => post_num('mn'),
                'dv_mv' => post_num('dv_mv'), 'i_ma' => post_num('i_ma'), 'rho' => post_num('rho'), 'device' => 'manual'];
        $row = array_filter($row, fn($v) => $v !== null);
        $r = ingest_measurement('manual_' . bin2hex(random_bytes(8)), $row, ['source' => 'manual']);
        flash($r === 'inserted' ? 'Pengukuran ditambahkan.' : 'Data tidak valid: isi jarak, lalu (ΔV dan I) atau ρa. Schlumberger juga butuh MN.', $r === 'inserted' ? 'ok' : 'err');
    } elseif ($do === 'del_meas') {
        q('DELETE FROM measurements WHERE id=? AND point_id=?', [(int)post_str('mid'), $id]);
        flash('Pengukuran dihapus.');
    } elseif ($do === 'delete') {
        q('DELETE FROM points WHERE id=?', [$id]);
        flash('Titik dihapus beserta datanya.');
        redirect('points.php');
    }
    redirect('point.php?id=' . $id);
}

$meas = q_all('SELECT * FROM measurements WHERE point_id=? ORDER BY spacing_m, measured_at', [$id]);
$S = build_sounding($meas, (float)cfg('analysis.depth_factor', 0.5));
$an = q_one('SELECT * FROM analyses WHERE point_id=? ORDER BY id DESC LIMIT 1', [$id]);
$ai = q_one("SELECT * FROM analyses WHERE point_id=? AND mode='ai' AND ai_text IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]);
$res = $an ? json_decode((string)$an['result_json'], true) : null;
$waters = q_all('SELECT * FROM water_samples WHERE point_id=? AND owner_id=? ORDER BY measured_at DESC LIMIT 5', [$id, current_user_id()]);

$plan = aq_plan($p);
$hasPlan = !empty($p['plan_json']);
$vt = verdict_tone($an['verdict'] ?? null, true);
$layers = $res['layers'] ?? (count($S) >= 3 ? aq_layers($S) : []);
$cfgLabel = $hasPlan ? ($plan['config'] === 'schlumberger' ? 'Schlumberger' : 'Wenner') : ($meas ? ucfirst($meas[0]['config']) : '–');
$lastT = $meas ? max(array_column($meas, 'measured_at')) : $p['created_at'];
$psd = aq_pseudo_data($id);

page_head('Titik ' . $p['code'], 'history', '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">', ['wide' => true]);
app_bar($p['code'], 'history.php', '<a class="iconbtn" href="export.php?point=' . $id . '" aria-label="Unduh CSV">' . icon('download', 20) . '</a>'); ?>
<?php if ($p['lat'] !== null && $p['lng'] !== null): ?>
  <div class="mapbox"><div id="miniMap" data-lat="<?= e($p['lat']) ?>" data-lng="<?= e($p['lng']) ?>" data-color="<?= e(verdict_pin_color($an['verdict'] ?? null)) ?>"></div></div>
<?php else: ?>
  <div class="empty-sm card">Koordinat belum diisi. <?= is_admin() ? 'Isi di bagian “Ubah titik” di bawah.' : '' ?></div>
<?php endif; ?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:16px"><h2 style="margin:0;font-size:1.15rem">Titik <?= e($p['name']) ?></h2><?= badge($vt[0], $vt[1]) ?></div>
<?php if (str_starts_with((string)$p['code'], 'DEMO-')): ?>
  <div class="alert info" style="margin-top:12px"><?= icon('info', 18) ?><span><b>Data simulasi</b> — kurva ini dibuat dari angka contoh dan bukan hasil pengukuran lapangan.</span></div>
  <?php if (is_admin()): ?><form method="post" action="demo.php" style="margin-top:10px" data-confirm="Hapus semua titik dan pengukuran simulasi DEMO? Data survey asli tidak dihapus."><?= csrf_field() ?><input type="hidden" name="do" value="delete"><button class="btn ghost sm" type="submit">Hapus semua data simulasi</button></form><?php endif; ?>
<?php endif; ?>
<p class="mono muted" style="font-size:.76rem;margin:6px 0 0"><?= $p['lat'] !== null ? e($p['lat'] . ', ' . $p['lng']) . ' · ' : '' ?><?= e(fdate_id($lastT, true)) ?></p>
<?php if (!empty($p['team'])): ?><p class="hint">Tim: <?= e($p['team']) ?></p><?php endif; ?>
<div class="statcards">
  <div><b><?= e($cfgLabel) ?></b><span>Konfigurasi</span></div>
  <div><b><?= $hasPlan ? (int)$plan['electrodes'] : '–' ?></b><span>Elektroda</span></div>
  <div><b><?= $hasPlan ? fnum(aq_plan_length($plan), 0) . 'm' : '–' ?></b><span>Lintasan</span></div>
</div>
<?php if ($psd): ?>
<section class="card viz-card viz-2d-card" id="penampang-2d"><div class="viz-h"><div><span class="viz-kicker">PROFIL LINTASAN</span><h3>Penampang resistivitas 2D</h3></div><span class="bdg bdg-info">Interpolasi halus</span></div>
  <p class="viz-lead">Perubahan tahanan semu sepanjang lintasan dan terhadap kedalaman</p>
  <div class="plot-stage"><?= aq_section2d_svg($psd, ['depth_factor' => (float)cfg('analysis.depth_factor', 0.5), 'electrodes' => (int)$plan['electrodes'], 'spacing' => (float)$plan['spacing'], 'length' => aq_plan_length($plan)]) ?></div>
  <div class="viz-caption"><span><i class="legend-dot low"></i>Tahanan rendah</span><span><i class="legend-dot mid"></i>Potensi air</span><span><i class="legend-dot high"></i>Tahanan tinggi</span></div>
  <p class="hint">Warna dihaluskan dari interpolasi data pengukuran. Ini penampang semu untuk visualisasi, bukan inversi 2D atau gambaran pasti bawah tanah.</p>
</section>
<?php endif; ?>
<section class="card viz-card"><div class="viz-h"><h3>Penampang tahanan tanah</h3><span class="bdg bdg-mute">Model lapisan</span></div>
  <?= aq_section_html($layers, ['electrodes' => (int)$plan['electrodes'], 'length' => aq_plan_length($plan)] + (!empty($res['drill_depth']) ? ['drill' => $res['drill_depth']] : [])) ?>
  </section>
<div class="row" style="margin-bottom:16px">
  <form method="post" action="analyze.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="rule"><button class="btn" type="submit"<?= count($S) < 3 ? ' disabled' : '' ?>>Analisis cepat</button></form>
  <form method="post" action="analyze.php" data-overlay="AI sedang menganalisis data titik ini. Biasanya 15–60 detik, jangan tutup halaman…"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="ai"><button class="btn outline" type="submit"<?= (count($S) < 3 || !gemini_ready()) ? ' disabled' : '' ?><?= !gemini_ready() ? ' title="Isi gemini.api_key di config.php"' : '' ?>>Analisis AI (Gemini)</button></form>
  <?php if ($an): ?><a class="btn outline" href="analysis-view.php?id=<?= $id ?>"><?= icon('sparkles', 18) ?> Lihat Analisa AI</a><?php endif; ?>
  <?php if (($p['survey_type'] ?? 'geovolt') !== 'water'): ?><a class="btn outline" href="survey-run.php?id=<?= $id ?>">Data Live</a><?php endif; ?>
</div>
<?php if ($p['notes']): ?><div class="card"><b>Catatan</b><p class="muted" style="margin:.3em 0 0"><?= e($p['notes']) ?></p></div><?php endif; ?>

<?php if ($S): ?>
<details class="fold" id="kurva-sounding"<?= $psd ? '' : ' open' ?>><summary>Kurva sounding 1D <?= icon('chev-down', 18) ?></summary><div class="in">
  <?= aq_curve_svg($S) ?>
</div></details>
<?php endif; ?>

<details class="fold" id="analisis" open><summary>Hasil analisis <?= icon('chev-down', 18) ?></summary><div class="in">
  <?php if ($an && $res): ?>
    <div class="score"><b class="num"><?= (int)$an['score'] ?></b><span>/100 <?= verdict_badge($an['verdict']) ?></span></div>
    <p class="hint">Dianalisis <?= fdt($an['created_at']) ?> (<?= $an['mode'] === 'ai' ? 'dengan AI' : 'aturan' ?>)</p>
    <?php if (!empty($res['best'])): $b = $res['best']; ?>
      <p>Target: <strong><?= e($b['class']) ?></strong> di sekitar <strong><?= fnum($b['depth'], 1) ?> m</strong> (±<?= fnum($b['rho'], 0) ?> Ω·m, jarak <?= fnum($b['s'], 1) ?> m).
      <?= $res['drill_depth'] ? 'Saran kedalaman bor indikatif: <strong>' . fnum($res['drill_depth'], 0) . ' m</strong>.' : '' ?></p>
    <?php endif; ?>
    <?php if (!empty($res['layers'])): ?><div class="tbl-wrap"><table><thead><tr><th>Kedalaman (m)</th><th>Jenis</th><th class="r">ρ (Ω·m)</th></tr></thead><tbody>
      <?php foreach ($res['layers'] as $L): ?><tr><td class="num"><?= fnum($L['from'], 1) ?>–<?= fnum($L['to'], 1) ?></td><td><?= e($L['label']) ?></td><td class="r num"><?= fnum($L['rho'], 0) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <?php if (!empty($res['warnings'])): ?><div class="alert warn"><?= icon('alert', 18) ?><div><b>Peringatan</b><ul class="plain"><?php foreach ($res['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
    <?php if (!empty($res['notes'])): ?><h4>Catatan</h4><ul class="plain"><?php foreach ($res['notes'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (!empty($res['ai_error'])): ?><p class="warn">AI gagal: <?= e($res['ai_error']) ?></p><?php endif; ?>
  <?php else: ?>
    <p><?= count($S) < 3 ? 'Butuh minimal 3 jarak elektroda berbeda sebelum bisa dianalisis.' : 'Belum dianalisis. Tekan “Analisis cepat”.' ?></p>
  <?php endif; ?>
  <?php if ($ai): $aiD = aq_ai_decode((string)$ai['ai_text']); ?>
    <h4>Tafsiran AI</h4><p class="hint">Dibuat <?= fdt($ai['created_at']) ?>. AI bisa keliru; periksa terhadap data di atas.</p>
    <?php if ($aiD): ?><p><?= e($aiD['data']['ringkasan']) ?></p><p><a class="btn sm" href="analysis-view.php?id=<?= $id ?>"><?= icon('sparkles', 16) ?> Lihat analisis AI lengkap</a></p>
    <?php else: ?><div class="md"><?= render_md((string)$ai['ai_text']) ?></div><?php endif; ?>
  <?php endif; ?>
</div></details>

<?php if ($waters): ?><details class="fold"><summary>Sampel air titik ini <?= icon('chev-down', 18) ?></summary><div class="in"><div class="tbl-wrap"><table>
  <thead><tr><th>Waktu</th><th class="r">TDS (ppm)</th><th class="r">EC (µS/cm)</th><th class="r">pH</th><th class="r">Suhu (°C)</th></tr></thead><tbody>
  <?php foreach ($waters as $w): ?><tr><td><a href="water-detail.php?id=<?= (int)$w['id'] ?>"><?= fdt($w['measured_at']) ?></a></td><td class="r num"><?= fnum($w['tds_ppm'], 0) ?></td><td class="r num"><?= fnum($w['ec_us'], 0) ?></td><td class="r num"><?= fnum($w['ph'], 1) ?></td><td class="r num"><?= fnum($w['temp_c'], 1) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></details><?php endif; ?>

<details class="fold"><summary>Data pengukuran (<?= count($meas) ?>) <?= icon('chev-down', 18) ?></summary><div class="in">
  <?php if ($meas): ?><div class="tbl-wrap"><table>
    <thead><tr><th>Konfigurasi</th><th class="r">Jarak (m)</th><th class="r">ΔV (mV)</th><th class="r">I (mA)</th><th class="r">ρa (Ω·m)</th><th>Waktu</th><th>Sumber</th><?= is_admin() ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($meas as $m): ?><tr><td><?= e($m['config']) ?></td><td class="r num"><?= fnum($m['spacing_m'], 2) ?></td><td class="r num"><?= fnum($m['dv_mv'], 2) ?></td><td class="r num"><?= fnum($m['i_ma'], 2) ?></td><td class="r num"><?= fnum($m['rho_a'], 1) ?></td><td><?= fdt($m['measured_at']) ?></td><td><?= e($m['source']) ?></td>
      <?php if (is_admin()): ?><td><form method="post" data-confirm="Hapus pengukuran ini?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="del_meas"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="btn ghost sm" type="submit">Hapus</button></form></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint"><a href="export.php?point=<?= $id ?>">Unduh CSV</a></p>
  <?php else: ?><p>Belum ada pengukuran untuk titik ini.</p><?php endif; ?>
</div></details>

<?php if (is_admin()): ?>
<details class="fold"><summary>Tambah pengukuran manual <?= icon('chev-down', 18) ?></summary><div class="in">
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="add">
  <div class="form-grid">
    <div><label for="config">Konfigurasi</label><select id="config" name="config"><option value="wenner">Wenner (jarak = a)</option><option value="schlumberger">Schlumberger (jarak = AB/2)</option></select></div>
    <div><label for="spacing">Jarak (m)</label><input id="spacing" name="spacing" inputmode="decimal" required></div>
    <div><label for="mn">MN (m, Schlumberger)</label><input id="mn" name="mn" inputmode="decimal"></div>
    <div><label for="dv_mv">ΔV (mV)</label><input id="dv_mv" name="dv_mv" inputmode="decimal"></div>
    <div><label for="i_ma">I (mA)</label><input id="i_ma" name="i_ma" inputmode="decimal"></div>
    <div><label for="rho">atau ρa langsung (Ω·m)</label><input id="rho" name="rho" inputmode="decimal"></div>
  </div>
  <p class="hint">ρa dihitung otomatis dari k·ΔV/I bila ρa dikosongkan.</p>
  <button class="btn sm" type="submit">Tambah</button>
</form></div></details>

<details class="fold"><summary>Ubah titik <?= icon('chev-down', 18) ?></summary><div class="in">
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="update">
  <div class="form-grid">
    <div><label for="name">Nama</label><input id="name" name="name" value="<?= e($p['name']) ?>" maxlength="120"></div>
    <div><label for="lat">Lintang</label><input id="lat" name="lat" value="<?= e($p['lat']) ?>" inputmode="decimal"></div>
    <div><label for="lng">Bujur</label><input id="lng" name="lng" value="<?= e($p['lng']) ?>" inputmode="decimal"></div>
  </div>
  <div class="field" style="margin-top:12px"><label for="notes">Catatan</label><textarea id="notes" name="notes" rows="2"><?= e($p['notes']) ?></textarea></div>
  <button class="btn sm" type="submit">Simpan</button>
</form>
<form method="post" data-confirm="Hapus titik ini beserta semua datanya?" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="delete"><button class="btn danger sm" type="submit">Hapus titik</button></form>
</div></details>
<?php endif; ?>
<?php page_foot('<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script><script src="assets/map.js?v=' . (@filemtime(AQ_ROOT . '/assets/map.js') ?: 1) . '" defer></script>');
