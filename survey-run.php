<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$id = (int)($_GET['id'] ?? $_GET['point'] ?? 0);
$p = owned_point($id);
if (!$p) redirect('survey-config.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('do') === 'add_meas') {
    csrf_check();
    $plan0 = aq_plan($p);
    $row = ['point' => $p['code'], 'config' => $plan0['config'], 'spacing' => post_num('spacing'), 'device' => 'manual'];
    foreach (['dv_mv' => 'dv_mv', 'i_ma' => 'i_ma', 'mn' => 'mn', 'x' => 'x'] as $f => $k) { $v = post_num($f); if ($v !== null) $row[$k] = $v; }
    $r = ingest_measurement('manual_' . bin2hex(random_bytes(8)), $row, ['source' => 'manual']);
    if ($r === 'inserted') flash('Pengukuran jarak ' . fnum($row['spacing'], 1) . ' m disimpan.');
    else flash('Data belum valid. Isi jarak (m), tegangan ΔV (mV), dan arus I (mA) lebih dari 0' . ($plan0['config'] === 'schlumberger' ? ', serta MN (m).' : '.'), 'err');
    redirect('survey-run.php?id=' . $id . '#manual');
}
$L = aq_live_geo($id);
$plan = aq_plan($p);
$nextSp = null; $nextCell = aq_next_cell($plan, aq_measured_cells($id));
if (!empty($L['strata_html'])) { $done = q_all('SELECT DISTINCT spacing_m FROM measurements WHERE point_id=? ORDER BY spacing_m', [$id]); if ($done) $nextSp = round((float)end($done)['spacing_m'] + (float)$plan['spacing'], 1); }
$lat = $L['latest'];
page_head('Survey GeoVolt', 'survey-new');
app_bar('Survey GeoVolt', 'point.php?id=' . $id); step_bar(3); ?>
<div data-live="geo" data-point="<?= $id ?>">
<div class="tabs2"><a href="survey-config.php?id=<?= $id ?>">Konfigurasi</a><a href="survey-run.php?id=<?= $id ?>" aria-current="page">Data Live</a></div>
<div id="conn" class="conn<?= ($L['device']['active'] ?? false) ? '' : ' off' ?>"><span class="d"></span><span class="ct"><?= $L['device'] ? (($L['device']['active']) ? 'Terhubung — ' . e($L['device']['id']) : 'Terakhir aktif ' . e($L['device']['ago']) . ' — ' . e($L['device']['id'])) : 'Menunggu perangkat…' ?></span><a href="device.php?point=<?= urlencode($p['code']) ?>">Hubungkan</a></div>
<div class="metrics3">
  <div><?= icon('zap', 20) ?><b id="lvI"><?= $lat ? fnum($lat['i_ma'], 1) : '–' ?></b><span>Arus (mA)</span></div>
  <div><?= icon('timer', 20) ?><b id="lvV"><?= $lat ? fnum($lat['dv_mv'], 0) : '–' ?></b><span>Tegangan (mV)</span></div>
  <div><?= icon('activity', 20) ?><b id="lvR"><?= $lat ? fnum($lat['rho_a'], 1) : '–' ?></b><span>Ω·m</span></div>
</div>
<div class="progress-h">Progres Pengukuran <span id="lvProg">Titik <?= (int)$L['done'] ?> / <?= (int)$L['total'] ?></span></div>
<div class="bar"><i id="lvBar" style="width:<?= min(100, $L['done'] / max(1, $L['total']) * 100) ?>%"></i></div>
<div class="progress-h" style="margin-top:22px">Penampang tahanan (real-time)</div>
<div class="secwrap" id="lvStrata"><?= $L['strata_html'] ?></div>
<?= aq_res_legend() ?>
<form method="post" action="analyze.php" class="btns">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="rule">
  <button class="btn soft-red" type="button" id="stopBtn">Hentikan</button>
  <button class="btn teal" type="submit" id="saveBtn"<?= $L['done'] < 3 ? ' disabled' : '' ?>>Simpan Data</button>
</form>
<details class="fold" id="manual" style="margin-top:18px"<?= isset($_GET['m']) ? ' open' : '' ?>><summary>Input manual (tanpa perangkat) <?= icon('chev-down', 18) ?></summary><div class="in">
  <?php if ($nextCell && aq_has_col('measurements', 'x_m')): ?><div class="alert info"><?= icon('target', 18) ?><span>Saran pengukuran 2D berikutnya: <b>a = <?= fnum($nextCell['a'], 1) ?> m</b> di <b>x = <?= fnum($nextCell['x'], 1) ?> m</b>. Isi kolom posisi agar muncul di penampang 2D.</span></div><?php endif; ?>
  <p class="hint" style="margin-top:0">Ukur dengan resistivity meter, lalu masukkan angkanya. Resistivitas semu dihitung otomatis dari konfigurasi <b><?= e($plan['config'] === 'schlumberger' ? 'Schlumberger' : 'Wenner') ?></b>.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="add_meas">
    <div class="form-grid">
      <div><label for="m_sp"><?= $plan['config'] === 'schlumberger' ? 'Jarak AB/2 (m)' : 'Jarak elektroda a (m)' ?></label><input id="m_sp" name="spacing" inputmode="decimal" required<?= $nextSp ? ' placeholder="' . e((string)$nextSp) . '"' : '' ?>></div>
      <?php if ($plan['config'] === 'schlumberger'): ?><div><label for="m_mn">MN (m)</label><input id="m_mn" name="mn" inputmode="decimal" required></div><?php endif; ?>
      <div><label for="m_x">Posisi x di lintasan (m), opsional</label><input id="m_x" name="x" inputmode="decimal" placeholder="mis. 12"></div>
      <div><label for="m_dv">ΔV (mV)</label><input id="m_dv" name="dv_mv" inputmode="decimal" required></div>
      <div><label for="m_i">I (mA)</label><input id="m_i" name="i_ma" inputmode="decimal" required></div>
    </div>
    <p><button class="btn sm" type="submit">Simpan pengukuran</button></p>
  </form></div></details>
<p class="hint">Data tersimpan otomatis saat diterima dari perangkat. “Simpan Data” menjalankan analisis dan membuka detail survey (butuh minimal 3 jarak elektroda).</p>
</div>
<?php page_foot();
