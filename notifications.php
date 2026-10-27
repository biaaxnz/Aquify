<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    state_set('notif_read_' . (int)$u['id'], date('Y-m-d H:i:s'));
    redirect('notifications.php');
}
$items = aq_notifications(40);
$unread = count(array_filter($items, fn($i) => $i['unread']));
page_head('Notifikasi', 'notifications');
app_bar('Notifikasi', 'index.php', $unread ? '<form method="post">' . csrf_field() . '<button class="tx-link" style="background:none;border:0;cursor:pointer;padding:6px">Tandai dibaca</button></form>' : ''); ?>
<?php if (!$items): ?>
  <div class="empty"><b>Belum ada notifikasi</b>Hasil analisis, peringatan kualitas air, dan status sinkronisasi akan muncul di sini.</div>
<?php else: $last = null; foreach ($items as $i): $g = day_label($i['t']); if ($g !== $last): $last = $g; ?><div class="group"><?= e($g) ?></div><?php endif; ?>
  <a class="notif <?= e($i['tone']) ?><?= $i['unread'] ? '' : ' read' ?>" href="<?= e($i['href']) ?>">
    <span class="li-ic <?= ['ok' => 'g', 'warn' => 'o', 'bad' => 'r', 'info' => 'w'][$i['tone']] ?? 'm' ?>"><?= icon($i['icon'], 20) ?></span>
    <span class="li-b"><b><?= e($i['title']) ?></b><small><?= e($i['sub']) ?></small></span>
  </a>
<?php endforeach; endif; ?>
<?php page_foot();
