<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_admin();
    $do = post_str('do');
    if ($do === 'sync') {
        @set_time_limit(90);
        session_write_close();
        $st = fb_sync('manual');
        session_start();
        if ($st['error']) flash('Sinkron gagal: ' . $st['error'], 'err');
        elseif ($st['skipped']) flash('Sinkron lain sedang berjalan. Coba lagi sebentar.', 'err');
        else flash("Sinkron selesai: {$st['inserted']} baru, {$st['duplicate']} sudah ada, {$st['invalid']} tidak valid.");
    } elseif ($do === 'reset') {
        q("DELETE FROM sync_state WHERE state_key LIKE 'fb\\_last\\_%'");
        flash('Penanda sinkron direset. Sinkron berikutnya membaca ulang semua data (duplikat otomatis dilewati).');
    }
    redirect('sync.php');
}

$log = q_all('SELECT * FROM sync_log WHERE user_id=? ORDER BY id DESC LIMIT 15', [current_user_id()]);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
page_head('Sinkron Firebase', 'sync');
?>
<div class="page-title"><div><h1>Sinkron Firebase</h1><p>Website menarik data baru dari Firebase ke database MySQL, lalu menganalisisnya.</p></div>
<?php if (is_admin()): ?><div class="row">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="sync"><button class="btn" type="submit"<?= fb_configured() ? '' : ' disabled' ?>>Sinkron sekarang</button></form>
  <form method="post" data-confirm="Reset penanda sinkron?"><?= csrf_field() ?><input type="hidden" name="do" value="reset"><button class="btn ghost" type="submit">Reset penanda</button></form>
</div><?php endif; ?></div>

<section class="panel"><h2>Status</h2>
<ul class="plain">
  <li>URL Firebase: <?= fb_configured() ? '<code>' . e(cfg('firebase.db_url')) . '</code>' : '<span class="warn">belum diisi (config.php)</span>' ?></li>
  <li>Path data ukur: <code><?= e(cfg('firebase.paths.measurements')) ?></code> · path air: <code><?= e(cfg('firebase.paths.water')) ?></code></li>
  <li>Sinkron terakhir: <?= fdt(state_get('last_sync_at')) ?><?= state_get('last_sync_error') ? ' – <span class="warn">' . e(state_get('last_sync_error')) . '</span>' : '' ?></li>
  <li>Gemini: <?= gemini_ready() ? 'aktif (' . e(cfg('gemini.model')) . ')' : '<span class="warn">belum diisi</span>' ?></li>
  <li>Sinkron otomatis di browser: setiap <?= (int)cfg('sync.auto_interval_sec', 60) ?> detik selama halaman terbuka.</li>
</ul></section>

<section class="panel"><h2>Format data di Firebase</h2>
<p>Satu pengukuran = satu node baru (<code>push()</code>) di path ukur:</p>
<pre><code>{ "point":"P01", "device":"AQ-01", "config":"wenner",
  "spacing":2, "dv_mv":12.4, "i_ma":9.8, "ts":1760000000 }</code></pre>
<p>Alias yang dikenali: <code>spacing/a/ab2</code>, <code>dv_mv/dv</code>, <code>i_ma/i</code>, <code>rho/rho_a</code> (opsional; dihitung jika kosong), <code>ts/timestamp</code>. Schlumberger perlu <code>mn</code>. Sampel air di path air:</p>
<pre><code>{ "point":"P01", "tds":310, "ec":480, "ph":7.2, "temp":28.4, "ts":1760000000 }</code></pre></section>

<section class="panel"><h2>Jalur alternatif</h2>
<p><strong>Pemicu terjadwal (cron-job.org dsb.):</strong> panggil <code><?= e($base) ?>/api/sync.php?token=<?= e(cfg('sync.token') ? '(sync.token di config.php)' : 'ISI-sync.token-dulu') ?></code></p>
<p><strong>Kirim langsung dari ESP32 tanpa Firebase:</strong> POST JSON ke <code><?= e($base) ?>/api/ingest.php</code> dengan header <code>X-Api-Token</code> (nilai <code>ingest.token</code>).</p>
<p class="hint">Sebagian hosting gratis memasang pemeriksaan bot (JS/cookie) atau membatasi koneksi keluar, sehingga cron/ESP32 bisa diblokir. Jika terjadi, andalkan sinkron otomatis dari browser atau pindah hosting.</p></section>

<section class="panel"><h2>Riwayat sinkron</h2>
<?php if ($log): ?><div class="tbl-wrap"><table><thead><tr><th>Waktu</th><th>Sumber</th><th class="r">Dibaca</th><th class="r">Baru</th><th class="r">Duplikat</th><th class="r">Tidak valid</th><th>Galat</th></tr></thead><tbody>
<?php foreach ($log as $l): ?><tr><td><?= fdt($l['started_at']) ?></td><td><?= e($l['source']) ?></td><td class="r num"><?= (int)$l['fetched'] ?></td><td class="r num"><?= (int)$l['inserted'] ?></td><td class="r num"><?= (int)$l['duplicate'] ?></td><td class="r num"><?= (int)$l['invalid'] ?></td><td><?= e($l['error']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><p class="muted">Belum ada riwayat.</p><?php endif; ?></section>
<?php page_foot();
