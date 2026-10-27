<?php
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(AQ_ROOT . '/config.php')) redirect('install.php');
require_login();

$points = aq_points_overview();
$high = count(array_filter($points, fn($p) => $p['verdict'] === 'Tinggi'));
$devices = aq_devices();
$active = count(array_filter($devices, fn($d) => $d['active']));
$feed = aq_feed(null, '', 4);
// Ringkasan lapisan: total ketebalan per kelas dari analisis terakhir tiap titik
$totals = [];
foreach (q_all('SELECT a.result_json FROM analyses a JOIN (SELECT MAX(id) mid FROM analyses GROUP BY point_id) x ON x.mid=a.id JOIN points p ON p.id=a.point_id WHERE p.owner_id=?', [current_user_id()]) as $a) {
    $r = json_decode((string)$a['result_json'], true);
    foreach (($r['layers'] ?? []) as $L) $totals[$L['key']] = ($totals[$L['key']] ?? 0) + max(0, (float)$L['to'] - (float)$L['from']);
}
$order = ['saline', 'brackish', 'aquifer', 'dry', 'rock'];
uksort($totals, fn($a, $b) => array_search($a, $order) <=> array_search($b, $order));
$unread = aq_unread_count();
$qs = aq_quickstart();
$qsDone = count(array_filter($qs, fn($x) => $x[2]));

page_head('Beranda', 'home', '', ['wide' => true]); ?>
<div class="home-head">
  <span class="av"><?= e(initials(user_display())) ?></span>
  <div class="grow"><small><?= e(greeting()) ?>,</small><b><?= e(user_display()) ?></b></div>
  <a class="iconbtn" href="notifications.php" aria-label="Notifikasi<?= $unread ? ', ' . $unread . ' belum dibaca' : '' ?>"><?= icon('bell', 20) ?><?php if ($unread): ?><span class="dot"></span><?php endif; ?></a>
</div>

<div class="home-grid">
<div>
  <section class="hero" aria-label="Wilayah kerja aktif">
    <div class="hero-top"><span>Wilayah Kerja Aktif</span><span class="live<?= $active ? '' : ' off' ?>"><i></i><?= $active ? 'Live' : 'Siaga' ?></span></div>
    <h2><?= e(aq_region()) ?></h2>
    <div class="hero-stats">
      <div><b><?= count($points) ?></b><span>Titik Survey</span></div>
      <div><b><?= $high ?></b><span>Potensi Tinggi</span></div>
      <div><b><?= $active ?></b><span>Perangkat Aktif</span></div>
    </div>
  </section>
  <a class="cta-geo" href="geolistrik.php"><span class="cg-ic"><?= icon('activity', 26) ?></span><span class="cg-t"><b>Pengukuran Tahanan Tanah</b><small>Tarik data ESP32 dari Firebase, lalu analisis potensi air tanah dengan AI</small></span><?= icon('chev-right', 20) ?></a>
  <nav class="quick" aria-label="Aksi cepat">
    <a href="survey-new.php"><?= icon('plus', 22) ?>Survey Baru</a>
    <a class="q2" href="water.php"><?= icon('droplet', 22) ?>Cek Air</a>
    <a class="q3" href="map.php"><?= icon('map', 22) ?>Peta</a>
    <a href="device.php"><?= icon('cpu', 22) ?>Perangkat</a>
  </nav>
  <?php if ($qsDone < count($qs)): ?>
  <section class="quickstart" aria-label="Mulai cepat">
    <div class="quickstart-h"><h2>Mulai cepat</h2><span><?= $qsDone ?>/<?= count($qs) ?> selesai</span></div>
    <div class="bar"><i style="width:<?= round($qsDone / count($qs) * 100) ?>%"></i></div>
    <?php foreach ($qs as [$t, $d, $done, $href]): ?>
      <a class="qs<?= $done ? ' done' : '' ?>" href="<?= e($href) ?>"><span class="dot"><?= $done ? icon('check', 14) : '' ?></span><span><b><?= e($t) ?></b><small><?= e($d) ?></small></span><?= icon('chev-right', 18) ?></a>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <div class="sec"><h2>Ringkasan Lapisan Wilayah</h2></div>
  <div class="card"><?= strata_legend_bar($totals) ?></div>
</div>
<div>
  <div class="sec" style="margin-top:0"><h2>Survey Terbaru</h2><a class="tx-link" href="history.php">Lihat semua</a></div>
  <?php if ($feed): foreach ($feed as $i): ?>
    <a class="li" href="<?= e($i['href']) ?>">
      <span class="li-ic <?= $i['kind'] === 'geo' ? 'g' : 'w' ?>"><?= icon($i['kind'] === 'geo' ? 'activity' : 'droplet', 22) ?></span>
      <span class="li-b"><b><?= e($i['dtitle']) ?></b><small><?= e($i['dsub']) ?></small></span>
      <?= badge($i['badgeL'], $i['tone']) ?>
    </a>
  <?php endforeach; else: ?>
    <div class="empty"><b>Belum ada survey</b>Mulai survey pertama untuk melihat ringkasan di sini.<p><a class="btn sm" href="survey-new.php">Survey Baru</a></p></div>
  <?php endif; ?>
  <?php if (is_admin()): ?>
  <form method="post" action="demo.php" class="row" style="margin-top:14px" data-confirm="Buat data geolistrik simulasi lalu buka penampang 2D? Data contoh akan tersimpan di database, tetapi bukan hasil ukur lapangan."><?= csrf_field() ?><input type="hidden" name="do" value="create"><button class="btn ghost sm" type="submit">Simulasikan pengukuran 2D</button></form>
  <?php endif; ?>
</div>
</div>
<?php page_foot();
