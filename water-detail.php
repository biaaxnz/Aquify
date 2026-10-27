<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); require_admin();
    if (post_str('do') === 'delete') { q('DELETE FROM water_samples WHERE id=? AND owner_id=?', [(int)post_str('wid'), current_user_id()]); flash('Sampel dihapus.'); }
    redirect('history.php?type=water');
}
$id = (int)($_GET['id'] ?? 0);
$w = $id ? q_one('SELECT w.*, p.code, p.name FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.id=? AND w.owner_id=?', [$id, current_user_id()])
         : q_one('SELECT w.*, p.code, p.name FROM water_samples w LEFT JOIN points p ON p.id=w.point_id WHERE w.owner_id=? ORDER BY w.measured_at DESC, w.id DESC LIMIT 1', [current_user_id()]);
page_head('Detail Hasil', 'history');
app_bar('Detail Hasil', 'history.php?type=water', $w ? '<button class="iconbtn" type="button" data-share aria-label="Bagikan hasil">' . icon('share', 20) . '</button>' : '');
if (!$w): ?>
  <div class="empty"><b>Belum ada hasil kualitas air</b>Cek kualitas air dulu untuk melihat detailnya.<p><a class="btn sm" href="water.php">Cek Kualitas Air</a></p></div>
<?php page_foot(); exit; endif;
$o = water_overall($w); $rows = water_params($w);
$place = $w['name'] ?: ($w['code'] ?: 'Sampel #' . $w['id']); ?>
<div class="verdict">
  <div class="verdict-ic <?= e($o['tone']) ?>"><?= icon($o['tone'] === 'bad' ? 'alert' : 'check-circle', 32) ?></div>
  <h2><?= e($o['title']) ?></h2>
  <p><?= e($place) ?> · <?= e(fdate_id($w['measured_at'])) ?></p>
</div>
<div class="rule"></div>
<h3>Parameter vs Ambang Batas</h3>
<?php foreach ($rows as $k => $r): if ($r['val'] === null || $k === 'temp') continue; ?>
  <div class="pb"><div class="pb-h"><?= e($r['label'] . ($r['unit'] ? ' (' . $r['unit'] . ')' : '')) ?><span><b><?= fnum($r['val'], $r['dec']) ?></b> / <?= e($r['limit']) ?></span></div>
  <div class="bar"><i class="<?= e($r['tone']) ?>" style="width:<?= round($r['frac'] * 100) ?>%"></i></div></div>
<?php endforeach; ?>
<?php if ($rows['temp']['val'] !== null): ?><div class="pb"><div class="pb-h">Suhu (°C)<span><b><?= fnum($rows['temp']['val'], 1) ?></b> · <?= e($rows['temp']['st']) ?></span></div></div><?php endif; ?>
<div class="note"><h4><?= icon('sparkles', 16) ?>Catatan Analisis</h4><?php foreach (water_notes($w) as $n): ?><p><?= e($n) ?></p><?php endforeach; ?><?php if (!empty($w['notes'])): ?><p><b>Catatan lapangan:</b> <?= e($w['notes']) ?></p><?php endif; ?></div>
<div class="btns"><a class="btn outline" href="export.php?water=<?= (int)$w['id'] ?>"><?= icon('download', 18) ?> Unduh</a><button class="btn" type="button" data-share>Bagikan Hasil</button></div>
<?php if (is_admin()): ?><form method="post" data-confirm="Hapus sampel ini?" style="margin-top:14px;text-align:center"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="wid" value="<?= (int)$w['id'] ?>"><button class="btn danger sm" type="submit">Hapus sampel</button></form><?php endif; ?>
<?php page_foot();
