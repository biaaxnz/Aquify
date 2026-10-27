<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = post_str('do');
    if ($do === 'notes') {
        $sid = (int)post_str('sid');
        if ($sid && q_val('SELECT id FROM water_samples WHERE id=? AND owner_id=?', [$sid, current_user_id()])) {
            q('UPDATE water_samples SET notes=? WHERE id=? AND owner_id=?', [mb_substr(post_str('notes'), 0, 500) ?: null, $sid, current_user_id()]);
            flash('Hasil disimpan.');
            redirect('water-detail.php?id=' . $sid);
        }
        flash('Belum ada sampel untuk disimpan.', 'err');
    } elseif ($do === 'add') {
        require_admin();
        $row = ['point' => post_str('point'), 'tds' => post_num('tds'), 'ec' => post_num('ec'), 'ph' => post_num('ph'), 'temp' => post_num('temp'), 'turb' => post_num('turb'), 'device' => 'manual'];
        $row = array_filter($row, fn($v) => $v !== null && $v !== '');
        $r = ingest_water('manual_' . bin2hex(random_bytes(8)), $row, ['source' => 'manual']);
        flash($r === 'inserted' ? 'Sampel air ditambahkan.' : 'Isi minimal satu nilai (TDS, EC, pH, suhu, kekeruhan). pH harus 0–14.', $r === 'inserted' ? 'ok' : 'err');
    }
    redirect('water.php' . (isset($_POST['pid']) && (int)$_POST['pid'] ? '?point=' . (int)$_POST['pid'] : ''));
}

$pid = (int)($_GET['point'] ?? 0);
if ($pid && !owned_point($pid)) { flash('Titik tidak ditemukan.', 'err'); redirect('history.php?type=water'); }
$L = aq_live_water($pid);
$sid = $L['sample']['id'] ?? 0;
$w = $sid ? q_one('SELECT w.*, p.code, p.name FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.id=? AND w.owner_id=?', [$sid, current_user_id()]) : null;
$pts = q_all('SELECT id,code,name FROM points WHERE owner_id=? ORDER BY id DESC LIMIT 100', [current_user_id()]);
$sel = $pid ?: (int)($w['point_id'] ?? 0);
$placeName = $w ? ($w['name'] ?: ($w['code'] ?: 'Sampel #' . $w['id'])) : '';
$params = $w ? water_params($w) : water_params([]);
$cards = ['ph' => 'pH', 'tds' => 'TDS', 'temp' => 'Suhu', 'turb' => 'Kekeruhan', 'ec' => 'Konduktivitas'];
page_head('Cek Kualitas Air', 'water');
app_bar('Cek Kualitas Air', 'index.php'); ?>
<div data-live="water" data-point="<?= $pid ?>">
<div id="conn" class="banner <?= ($L['device']['active'] ?? false) ? 'ok' : 'off' ?>"><i></i><span class="ct"><?= $L['device'] ? (($L['device']['active']) ? 'Sensor ' . e($L['device']['id']) . ' terhubung' : 'Sensor ' . e($L['device']['id']) . ' terakhir aktif ' . e($L['device']['ago'])) : 'Belum ada sensor kualitas air terbaca' ?></span><a href="device.php">Perangkat</a></div>
<form method="get" class="field"><label for="pointSel">Titik</label>
  <div class="select-row"><select id="pointSel" name="point" onchange="this.form.submit()"><option value="0">Pembacaan terbaru</option><?php foreach ($pts as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $sel === (int)$r['id'] ? ' selected' : '' ?>><?= e($r['code'] . ' — ' . $r['name']) ?></option><?php endforeach; ?></select><?= icon('chev-down', 18) ?></div>
  <noscript><button class="btn sm" type="submit">Tampilkan</button></noscript></form>
<?php if (!$w): ?>
  <div class="empty"><b>Belum ada sampel air</b>Hubungkan sensor atau tambahkan sampel manual di bawah.</div>
<?php else: ?>
  <p class="hint" style="margin:0 0 4px"><b style="color:var(--ink)"><?= e($placeName) ?></b> · <span id="lvTime"><?= e(ago($w['measured_at'])) ?></span></p>
<?php endif; ?>
<div class="pgrid">
<?php foreach ($cards as $k => $lbl): $r = $params[$k]; ?>
  <div class="pcard<?= $k === 'ec' ? ' wide' : '' ?>" data-p="<?= $k ?>">
    <div class="pcard-h"><span><?= e($lbl) ?></span><span class="pb-badge bdg bdg-<?= e($r['tone']) ?>"<?= $r['st'] === '' ? ' hidden' : '' ?>><?= e($r['st']) ?></span></div>
    <div class="pcard-v"><span class="pv"><?= fnum($r['val'], $r['dec']) ?></span><?php if ($r['unit']): ?><small><?= e($r['unit']) ?></small><?php endif; ?></div>
  </div>
<?php endforeach; ?>
</div>
</div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="notes"><input type="hidden" name="sid" value="<?= (int)$sid ?>"><input type="hidden" name="pid" value="<?= $pid ?>">
  <div class="field"><label for="notes">Catatan Lapangan</label><textarea id="notes" name="notes" maxlength="500" placeholder="Air jernih, tidak berbau"<?= $sid ? '' : ' disabled' ?>><?= e($w['notes'] ?? '') ?></textarea></div>
  <button class="btn" type="submit"<?= $sid ? '' : ' disabled' ?>>Simpan Hasil</button>
</form>
<?php if (is_admin()): ?>
<details class="fold" style="margin-top:22px"><summary>Tambah sampel manual <?= icon('chev-down', 18) ?></summary><div class="in">
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="pid" value="<?= $pid ?>">
  <div class="form-grid">
    <div><label for="point">Kode titik</label><input id="point" name="point" maxlength="40" value="<?= e($w['code'] ?? '') ?>"></div>
    <div><label for="ph">pH</label><input id="ph" name="ph" inputmode="decimal"></div>
    <div><label for="tds">TDS (ppm)</label><input id="tds" name="tds" inputmode="decimal"></div>
    <div><label for="ec">EC (µS/cm)</label><input id="ec" name="ec" inputmode="decimal"></div>
    <div><label for="temp">Suhu (°C)</label><input id="temp" name="temp" inputmode="decimal"></div>
    <div><label for="turb">Kekeruhan (NTU)</label><input id="turb" name="turb" inputmode="decimal"></div>
  </div>
  <p><button class="btn sm" type="submit">Tambah</button></p>
</form></div></details>
<?php endif; ?>
<p class="hint">Penilaian bersifat indikatif dan bukan pengganti uji laboratorium.</p>
<?php page_foot();
