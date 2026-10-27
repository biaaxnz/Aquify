<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_admin();
    $code = mb_substr(post_str('code'), 0, 40);
    $name = mb_substr(post_str('name'), 0, 120);
    $lat = post_num('lat'); $lng = post_num('lng');
    if ($code === '') flash('Kode titik wajib diisi.', 'err');
    elseif (($lat !== null && abs($lat) > 90) || ($lng !== null && abs($lng) > 180)) flash('Koordinat di luar rentang.', 'err');
    elseif (q_val('SELECT id FROM points WHERE code=?', [$code])) flash('Kode titik sudah dipakai.', 'err');
    else {
        q('INSERT INTO points(owner_id,code,name,lat,lng,notes) VALUES(?,?,?,?,?,?)', [current_user_id(), $code, $name !== '' ? $name : $code, $lat, $lng, post_str('notes') ?: null]);
        flash('Titik ' . $code . ' ditambahkan.');
        redirect('point.php?id=' . (int)pdo()->lastInsertId());
    }
    redirect('points.php');
}

$rows = q_all('SELECT p.*, (SELECT COUNT(*) FROM measurements m WHERE m.point_id=p.id) n,
    (SELECT a.verdict FROM analyses a WHERE a.point_id=p.id ORDER BY a.id DESC LIMIT 1) verdict FROM points p WHERE p.owner_id=? ORDER BY p.code', [current_user_id()]);
page_head('Titik ukur', 'points');
?>
<div class="page-title"><div><h1>Titik ukur</h1><p>Satu titik = satu lokasi sounding (VES) dengan beberapa jarak elektroda.</p></div></div>
<section class="panel">
  <?php if ($rows): ?><div class="tbl-wrap"><table>
    <thead><tr><th>Kode</th><th>Nama</th><th>Koordinat</th><th class="r">Ukuran</th><th>Potensi</th></tr></thead>
    <tbody><?php foreach ($rows as $p): ?>
      <tr><td><a href="point.php?id=<?= (int)$p['id'] ?>"><?= e($p['code']) ?></a></td><td><?= e($p['name']) ?></td>
      <td class="num"><?= $p['lat'] !== null ? e($p['lat'] . ', ' . $p['lng']) : '–' ?></td><td class="r num"><?= (int)$p['n'] ?></td><td><?= verdict_badge($p['verdict']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php else: ?><p>Belum ada titik. Titik juga dibuat otomatis saat data Firebase membawa field <code>point</code>.</p><?php endif; ?>
</section>
<?php if (is_admin()): ?>
<section class="panel"><h2>Tambah titik</h2>
<form method="post"><?= csrf_field() ?>
  <div class="form-grid">
    <div><label for="code">Kode</label><input id="code" name="code" required maxlength="40" placeholder="P01"></div>
    <div><label for="name">Nama lokasi</label><input id="name" name="name" maxlength="120"></div>
    <div><label for="lat">Lintang</label><input id="lat" name="lat" inputmode="decimal" placeholder="-6.9175"></div>
    <div><label for="lng">Bujur</label><input id="lng" name="lng" inputmode="decimal" placeholder="107.6191"></div>
  </div>
  <p><label for="notes">Catatan lapangan</label><textarea id="notes" name="notes" rows="2"></textarea></p>
  <button class="btn" type="submit">Simpan titik</button>
</form></section>
<?php endif; ?>
<?php page_foot();
