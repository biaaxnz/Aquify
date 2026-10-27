<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('do') === 'test_gemini') {
    csrf_check(); require_admin();
    session_write_close();
    $testResult = gemini_ready() ? gemini_call(['contents' => [['role' => 'user', 'parts' => [['text' => 'Balas hanya dengan kata: OK']]]], 'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 32]], 40) : ['ok' => false, 'error' => 'gemini.api_key belum diisi di config.php.', 'attempts' => [], 'ms' => 0];
}
$st = [
    ['Firebase Realtime Database', fb_configured(), 'sync.php', 'refresh'],
    ['Analisa AI (Gemini)', gemini_ready(), null, 'sparkles'],
    ['Verifikasi email (OTP)', otp_enabled(), null, 'mail'],
    ['Token ingest perangkat', (string)cfg('ingest.token', '') !== '', null, 'cpu'],
];
page_head('Pengaturan', 'settings');
app_bar('Pengaturan', 'profile.php'); ?>
<div class="group" style="margin-top:0">Status layanan</div>
<?php foreach ($st as [$l, $on, $href, $ic]): ?>
  <div class="li"><span class="li-ic <?= $on ? 'g' : 'm' ?>"><?= icon($ic, 20) ?></span><span class="li-b"><b><?= e($l) ?></b><small><?= $on ? 'Aktif' : 'Belum dikonfigurasi di config.php' ?></small></span><?= badge($on ? 'Aktif' : 'Nonaktif', $on ? 'ok' : 'mute') ?></div>
<?php endforeach; ?>
<?php if (is_admin()): ?>
<div class="group">Tes koneksi AI</div>
<section class="card">
  <p class="muted" style="margin-top:0">Mengirim satu pesan pendek ke Gemini untuk memastikan kunci dan model berfungsi, lalu menampilkan hasil atau pesan galat apa adanya.</p>
  <form method="post" data-overlay="Menghubungi Gemini…"><?= csrf_field() ?><input type="hidden" name="do" value="test_gemini"><button class="btn" type="submit"<?= gemini_ready() ? '' : ' disabled' ?>><?= icon('sparkles', 18) ?> Tes koneksi Gemini</button></form>
  <?php if ($testResult !== null): ?>
    <div class="alert <?= $testResult['ok'] ? 'info' : 'bad' ?>" style="margin-top:14px"><?= icon($testResult['ok'] ? 'check-circle' : 'alert', 18) ?><div>
      <?php if ($testResult['ok']): ?><b>Terhubung.</b> Gemini menjawab "<?= e(mb_substr((string)$testResult['text'], 0, 40)) ?>" lewat <b><?= e(['studio' => 'Gemini Developer API', 'vertex' => 'Vertex AI Express'][$testResult['provider'] ?? ''] ?? '–') ?></b>, model <code><?= e(gemini_model()) ?></code>, <?= (int)$testResult['ms'] ?> ms.
      <?php else: ?><b>Gagal.</b> <?= e((string)$testResult['error']) ?><?php endif; ?>
    </div></div>
    <?php if (!empty($testResult['attempts'])): ?><div class="tbl-wrap"><table><thead><tr><th>Layanan dicoba</th><th class="r">HTTP</th><th>Hasil</th></tr></thead><tbody>
      <?php foreach ($testResult['attempts'] as $a): ?><tr><td><?= e($a['provider']) ?></td><td class="r num"><?= (int)$a['http'] ?></td><td><?= $a['msg'] ? e($a['msg']) : 'OK' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<div class="group">Kelola</div>
<div class="menu">
  <a href="sync.php"><?= icon('refresh', 20) ?>Sinkron Firebase<?= icon('chev-right', 18) ?></a>
  <a href="device.php"><?= icon('cpu', 20) ?>Status Perangkat<?= icon('chev-right', 18) ?></a>
  <a href="points.php"><?= icon('pin', 20) ?>Daftar titik ukur<?= icon('chev-right', 18) ?></a>
  <?php if (is_admin()): ?><a href="users.php"><?= icon('users', 20) ?>Pengguna<?= icon('chev-right', 18) ?></a><?php endif; ?>
</div>
<p class="hint">Kredensial Firebase, Gemini, dan email disimpan di <code>config.php</code> pada server, bukan di browser. Analisis berbasis aturan bukan AI generatif, dan evaluasi kualitas air hanya indikatif.</p>
<?php page_foot();
