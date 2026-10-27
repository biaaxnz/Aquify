<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p = owned_point($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $p) {
    csrf_check();
    $cfgName = post_str('config') === 'schlumberger' ? 'schlumberger' : 'wenner';
    $n = (int)post_num('electrodes'); $sp = post_num('spacing'); $dp = post_num('depth');
    $ori = post_str('orientation') === 'Timur–Barat' ? 'Timur–Barat' : 'Utara–Selatan';
    if ($n < 4 || $n > 64 || $sp === null || $sp < 0.5 || $sp > 100 || $dp === null || $dp < 0.05 || $dp > 300) {
        flash('Periksa nilai: elektroda 4–64, spasi 0,5–100 m, kedalaman 0,05–300 m.', 'err');
        redirect('survey-config.php?id=' . $id);
    }
    q('UPDATE points SET plan_json=? WHERE id=?', [json_encode(['config' => $cfgName, 'electrodes' => $n, 'spacing' => $sp, 'depth' => $dp, 'orientation' => $ori], JSON_UNESCAPED_UNICODE), $id]);
    redirect('survey-run.php?id=' . $id);
}

if (!$p) {
    $list = q_all("SELECT id,code,name FROM points WHERE owner_id=? AND (survey_type<>'water' OR survey_type IS NULL) ORDER BY id DESC LIMIT 30", [current_user_id()]);
    page_head('Survey Geovolt', 'survey-new');
    app_bar('Survey GeoVolt', 'index.php'); ?>
    <p class="muted">Pilih titik survey untuk diatur konfigurasinya.</p>
    <?php foreach ($list as $r): ?>
      <a class="li" href="survey-config.php?id=<?= (int)$r['id'] ?>"><span class="li-ic g"><?= icon('activity', 22) ?></span><span class="li-b"><b><?= e($r['code']) ?></b><small><?= e($r['name']) ?></small></span><?= icon('chev-right', 18) ?></a>
    <?php endforeach; if (!$list): ?><div class="empty"><b>Belum ada titik survey</b><p><a class="btn sm" href="survey-new.php">Survey Baru</a></p></div><?php endif;
    page_foot(); exit;
}
$plan = aq_plan($p);
page_head('Konfigurasi Survey', 'survey-new');
app_bar('Survey GeoVolt', 'point.php?id=' . $id, ''); step_bar(2); ?>
<div class="tabs2" role="tablist"><a href="survey-config.php?id=<?= $id ?>" aria-current="page">Konfigurasi</a><a href="survey-run.php?id=<?= $id ?>">Data Live</a></div>
<p class="muted" style="margin:0 0 16px"><b style="color:var(--ink)"><?= e($p['code']) ?></b> · <?= e($p['name']) ?></p>
<form method="post" id="planForm">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="field"><label for="config">Konfigurasi Elektroda</label>
    <div class="select-row"><select id="config" name="config"><option value="wenner"<?= $plan['config'] === 'wenner' ? ' selected' : '' ?>>Wenner (α)</option><option value="schlumberger"<?= $plan['config'] === 'schlumberger' ? ' selected' : '' ?>>Schlumberger</option></select><?= icon('chev-down', 18) ?></div></div>
  <div class="field"><label for="electrodes">Jumlah Elektroda</label>
    <div class="stepper"><button type="button" data-step="-1" aria-label="Kurangi"><?= icon('minus', 18) ?></button><input id="electrodes" name="electrodes" type="number" min="4" max="64" step="1" value="<?= (int)$plan['electrodes'] ?>"><button type="button" data-step="1" aria-label="Tambah"><?= icon('plus', 18) ?></button></div></div>
  <div class="field"><label for="spacing">Spasi Elektroda (meter)</label>
    <div class="stepper"><button type="button" data-step="-1" aria-label="Kurangi"><?= icon('minus', 18) ?></button><input id="spacing" name="spacing" type="number" min="0.5" max="100" step="0.5" value="<?= e(rtrim(rtrim(number_format((float)$plan['spacing'], 1, '.', ''), '0'), '.')) ?>"><button type="button" data-step="1" aria-label="Tambah"><?= icon('plus', 18) ?></button></div></div>
  <div class="field"><label for="depth">Target Kedalaman</label><input id="depth" name="depth" type="number" min="0.05" max="300" step="0.01" value="<?= e(rtrim(rtrim(number_format((float)$plan['depth'], 2, '.', ''), '0'), '.')) ?>" aria-describedby="depthH"><p class="hint" id="depthH">Dalam meter; minimum 0,05 m (5 cm).</p></div>
  <div class="field"><span class="lbl">Orientasi Lintasan</span>
    <div class="seg"><label><input type="radio" name="orientation" value="Utara–Selatan"<?= $plan['orientation'] === 'Utara–Selatan' ? ' checked' : '' ?>><span>Utara–Selatan</span></label><label><input type="radio" name="orientation" value="Timur–Barat"<?= $plan['orientation'] === 'Timur–Barat' ? ' checked' : '' ?>><span>Timur–Barat</span></label></div></div>
  <div class="alert warn"><?= icon('info', 18) ?><span>Panjang lintasan total: <b id="trackLen"><?= fnum(aq_plan_length($plan), 0) ?> m</b>. Pastikan area lapangan cukup sebelum memulai.</span></div>
  <button class="btn" type="submit">Mulai Pengukuran</button>
</form>
<?php page_foot();
