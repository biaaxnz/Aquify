<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

function next_code(string $type): string {
    $pre = $type === 'water' ? 'KA-' : 'GL-';
    $max = (int)q_val("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) FROM points WHERE code LIKE ?", [$pre . '%']);
    return $pre . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

$f = ['name' => '', 'coord' => '', 'type' => 'geovolt', 'team' => user_display(), 'notes' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = ['name' => mb_substr(post_str('name'), 0, 120), 'coord' => post_str('coord'), 'type' => post_str('type') === 'water' ? 'water' : 'geovolt',
          'team' => mb_substr(post_str('team'), 0, 160), 'notes' => mb_substr(post_str('notes'), 0, 2000)];
    $lat = $lng = null;
    if ($f['coord'] !== '') {
        if (preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d+(?:[.,]\d+)?)\s*$/', $f['coord'], $m)) { $lat = (float)str_replace(',', '.', $m[1]); $lng = (float)str_replace(',', '.', $m[2]); }
        else flash('Format koordinat: lintang, bujur — contoh -5.5483, 95.3238.', 'err');
    }
    if ($f['name'] === '') flash('Nama lokasi / proyek wajib diisi.', 'err');
    elseif (($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) flash('Koordinat di luar rentang.', 'err');
    elseif (!in_array('err', array_column($_SESSION['flash'] ?? [], 0), true)) {
        $code = next_code($f['type']);
        q('INSERT INTO points(owner_id,code,name,lat,lng,notes,survey_type,team) VALUES(?,?,?,?,?,?,?,?)', [current_user_id(), $code, $f['name'], $lat, $lng, $f['notes'] ?: null, $f['type'], $f['team'] ?: null]);
        $id = (int)pdo()->lastInsertId();
        flash('Survey ' . $code . ' dibuat.');
        redirect($f['type'] === 'water' ? 'water.php?point=' . $id : 'survey-config.php?id=' . $id);
    }
}
page_head('Survey Baru', 'survey-new', '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">');
app_bar('Survey Baru', 'index.php'); step_bar(1); ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="field"><label for="name">Nama Lokasi / Proyek</label><div class="inp"><?= icon('pencil', 18) ?><input id="name" name="name" required maxlength="120" placeholder="Titik Sumur Desa …" value="<?= e($f['name']) ?>"></div></div>
  <div class="mapbox" style="margin-bottom:14px"><div id="pickmap" role="application" aria-label="Pilih lokasi di peta"></div></div>
  <div class="field"><label for="coord">Koordinat</label>
    <div class="inp"><?= icon('crosshair', 18) ?><input id="coord" name="coord" inputmode="decimal" placeholder="-5.5483, 95.3238" value="<?= e($f['coord']) ?>"></div>
    <p class="hint">Ketuk peta untuk memilih titik, atau <button type="button" id="useGps" class="tx-link" style="background:none;border:0;padding:0;cursor:pointer">gunakan lokasi saya</button>.</p></div>
  <div class="field"><span class="lbl">Jenis Survey</span>
    <div class="seg"><label><input type="radio" name="type" value="geovolt"<?= $f['type'] === 'geovolt' ? ' checked' : '' ?>><span>GeoVolt</span></label><label><input type="radio" name="type" value="water"<?= $f['type'] === 'water' ? ' checked' : '' ?>><span>Kualitas Air</span></label></div></div>
  <div class="field"><label for="team">Tim / Surveyor</label><div class="inp"><?= icon('user', 18) ?><input id="team" name="team" maxlength="160" placeholder="Budi Santoso, Ade R." value="<?= e($f['team']) ?>"></div></div>
  <div class="field"><label for="notes">Catatan (opsional)</label><textarea id="notes" name="notes" placeholder="Tanah kering, akses sulit di musim hujan"><?= e($f['notes']) ?></textarea></div>
  <button class="btn" type="submit">Mulai Survey</button>
</form>
<?php page_foot('<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script><script src="assets/map.js?v=' . (@filemtime(AQ_ROOT . '/assets/map.js') ?: 1) . '" defer></script>');
