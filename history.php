<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$type = in_array($_GET['type'] ?? '', ['geo', 'water'], true) ? $_GET['type'] : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$all = aq_feed(null, '', 300);
$nGeo = count(array_filter($all, fn($i) => $i['kind'] === 'geo')); $nWater = count($all) - $nGeo;
$items = aq_feed($type ?: null, $q, 120);
page_head('Riwayat Survey', 'history');
app_bar('Riwayat Survey', null, '<a class="iconbtn" href="export.php" aria-label="Unduh semua pengukuran (CSV)">' . icon('download', 20) . '</a>'); ?>
<form method="get" class="search"><input type="hidden" name="type" value="<?= e($type) ?>">
  <div class="inp"><?= icon('search', 18) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari lokasi survey…" aria-label="Cari lokasi survey"></div></form>
<div class="chips" role="group" aria-label="Filter jenis">
  <a class="chip-b"<?= $type === '' ? ' aria-current="page"' : '' ?> href="history.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">Semua (<?= count($all) ?>)</a>
  <a class="chip-b"<?= $type === 'geo' ? ' aria-current="page"' : '' ?> href="history.php?type=geo<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">GeoVolt (<?= $nGeo ?>)</a>
  <a class="chip-b"<?= $type === 'water' ? ' aria-current="page"' : '' ?> href="history.php?type=water<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">Kualitas Air (<?= $nWater ?>)</a>
</div>
<?php if (!$items): ?>
  <div class="empty"><b><?= $q !== '' ? 'Tidak ada hasil' : 'Belum ada survey' ?></b><?= $q !== '' ? 'Coba kata kunci lain.' : 'Survey yang kamu buat akan muncul di sini.' ?></div>
<?php else: $last = null; foreach ($items as $i): $g = day_label($i['time']); if ($g !== $last): $last = $g; ?>
  <div class="group"><?= e($g) ?></div>
<?php endif; ?>
  <a class="li" href="<?= e($i['href']) ?>">
    <span class="li-ic <?= $i['kind'] === 'geo' ? 'g' : 'w' ?>"><?= icon($i['kind'] === 'geo' ? 'activity' : 'droplet', 22) ?></span>
    <span class="li-b"><b><?= e($i['title']) ?></b><small><?= e($i['sub']) ?></small></span>
    <span class="li-end"><?= badge($i['badge'], $i['tone']) ?><?= icon('chev-right', 16) ?></span>
  </a>
<?php endforeach; endif; ?>
<?php page_foot();
