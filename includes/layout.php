<?php
function verdict_class(string $v): string {
    return match ($v) { 'Tinggi' => 'v-high', 'Sedang' => 'v-mid', 'Berisiko asin/lempung' => 'v-risk', default => 'v-low' };
}
function verdict_badge(?string $v): string {
    [$l, $t] = verdict_tone($v);
    return badge($l, $t);
}

/**
 * Kepala halaman.
 * $opt: 'auth' => true  : halaman tamu (tanpa navigasi), kartu di tengah
 *       'wide' => true  : konten lebih lebar di desktop
 */
function aq_auth_page(?bool $set = null): bool { static $v = false; if ($set !== null) $v = $set; return $v; }

function page_head(string $title, string $active = '', string $extraHead = '', array $opt = []): void {
    $u = current_user();
    $auth = !empty($opt['auth']) || !$u;
    aq_auth_page($auth);
    $nav = [
        ['index.php', 'Beranda', 'home', 'home'],
        ['geolistrik.php', 'Pengukuran Tahanan Tanah', 'geo', 'activity'],
        ['map.php', 'Peta Potensi Air', 'map', 'map'],
        ['history.php', 'Riwayat Survey', 'history', 'clock'],
        ['water.php', 'Cek Kualitas Air', 'water', 'droplet'],
        ['analysis-view.php', 'Analisa AI', 'analysis', 'sparkles'],
        ['device.php', 'Status Perangkat', 'device', 'cpu'],
        ['notifications.php', 'Notifikasi', 'notifications', 'bell'],
        ['sync.php', 'Sinkron Firebase', 'sync', 'refresh'],
    ];
    if ($u && is_admin()) {
        $nav[] = ['demo.php', 'Simulasi', 'demo', 'activity'];
        $nav[] = ['users.php', 'Pengguna', 'users', 'users'];
    }
    $nav[] = ['settings.php', 'Pengaturan', 'settings', 'settings'];
    $v = @filemtime(AQ_ROOT . '/assets/style.css') ?: 1;
    $logoV = @filemtime(AQ_ROOT . '/assets/aquify_badge_hd.png') ?: 1;
    $auto = ($u && fb_configured()) ? (int)cfg('sync.auto_interval_sec', 60) : 0;
    $unread = $u ? aq_unread_count() : 0;
    $name = cfg('app.name', 'Aquify');
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F4660">
<title><?= e($title) ?> – <?= e($name) ?></title>
<link rel="icon" href="assets/aquify_badge_hd.png?v=<?= $logoV ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;family=JetBrains+Mono:wght@500;600&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
<?= $extraHead ?>
</head>
<body class="<?= $auth ? 'is-auth' : 'is-app' ?>" data-csrf="<?= e(csrf_token()) ?>" data-autosync="<?= $auto ?>" data-maptiler="<?= e((string)cfg('maps.maptiler_key', '')) ?>">
<a class="skip" href="#main">Lewati ke konten</a>
<?php $flashes = flash(); if ($flashes): ?><div class="toasts" role="region" aria-label="Pemberitahuan"><?php foreach ($flashes as [$t, $m]): ?>
  <div class="toast <?= $t === 'err' ? 'err' : 'ok' ?>" role="<?= $t === 'err' ? 'alert' : 'status' ?>"><?= icon($t === 'err' ? 'alert' : 'check-circle', 20) ?><span><?= e($m) ?></span><button type="button" class="toast-x" aria-label="Tutup"><?= icon('x', 16) ?></button></div>
<?php endforeach; ?></div><?php endif; ?>
<?php if ($auth): ?>
<div class="auth-shell">
<aside class="auth-brand" aria-hidden="true">
  <span class="orb orb1"></span><span class="orb orb2"></span>
  <div class="ab-center">
    <img class="ab-logo" src="assets/aquify_badge_hd.png?v=<?= $logoV ?>" alt="" width="1024" height="1024">
    <p class="ab-tag">Geolistrik · Kualitas Air · Analisa AI · Peta Potensi</p>
  </div>
</aside>
<?php endif; ?>
<?php if (!$auth): ?>
<button class="railtoggle" id="railToggle" type="button" aria-label="Buka menu" aria-expanded="false" aria-controls="rail"><?= icon('more', 22) ?><?php if ($unread): ?><i class="rt-dot"></i><?php endif; ?></button>
<div class="rail-backdrop" id="railBackdrop" hidden></div>
<aside class="rail" id="rail" aria-label="Menu" tabindex="-1">
  <div class="rail-head"><a class="brand" href="index.php"><img src="assets/aquify_badge_hd.png?v=<?= $logoV ?>" alt="" width="1024" height="1024"><span><b><?= e($name) ?></b><small>Smart groundwater</small></span></a>
    <button class="rail-x" id="railClose" type="button" aria-label="Tutup menu"><?= icon('x', 20) ?></button></div>
  <a class="btn teal block" href="survey-new.php"><?= icon('plus', 18) ?> Survey Baru</a>
  <nav aria-label="Menu utama" class="rail-nav">
    <?php foreach ($nav as [$href, $label, $key, $ic]): ?>
      <a href="<?= $href ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($ic, 19) ?><span><?= e($label) ?></span><?php if ($key === 'notifications' && $unread): ?><i class="cnt"><?= $unread ?></i><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="rail-foot">
    <a class="syncwrap" href="<?= $auto ? 'sync.php' : 'settings.php' ?>" title="Status sinkronisasi data"><span id="syncChip" class="chip<?= $auto ? ' live' : '' ?>" role="status"><?= $auto ? 'Sinkron otomatis aktif' : 'Firebase belum diatur' ?></span></a>
    <a class="who" href="profile.php"><span class="av"><?= e(initials(user_display())) ?></span><span><b><?= e(user_display()) ?></b><small><?= e($u['role']) ?></small></span></a>
  </div>
</aside>
<?php endif; ?>
<main id="main" class="<?= $auth ? 'auth-main' . (!empty($opt['bare']) ? ' bare' : '') : 'app-main' . (!empty($opt['wide']) ? ' wide' : '') ?>">
<?php
}

function page_foot(string $extraScripts = ''): void {
    $u = current_user();
    $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $tab = function (string $href, string $label, string $ic, array $match) use ($cur): string {
        $on = in_array($cur, $match, true) ? ' aria-current="page"' : '';
        return '<a href="' . $href . '"' . $on . '>' . icon($ic, 22) . '<span>' . $label . '</span></a>';
    };
    ?></main>
<?php if (aq_auth_page()): ?></div><?php endif; ?>
<?php if ($u): ?>
<nav class="tabbar" aria-label="Navigasi bawah">
  <?= $tab('index.php', 'Beranda', 'home', ['index.php']) ?>
  <?= $tab('map.php', 'Peta', 'map', ['map.php']) ?>
  <a class="fab" href="survey-new.php" aria-label="Survey baru"><?= icon('plus', 26) ?></a>
  <?= $tab('history.php', 'Riwayat', 'clock', ['history.php', 'point.php', 'water-detail.php']) ?>
  <?= $tab('profile.php', 'Profil', 'user', ['profile.php', 'settings.php']) ?>
</nav>
<?php endif; ?>
<?= $extraScripts ?>
<script src="assets/app.js?v=<?= @filemtime(AQ_ROOT . '/assets/app.js') ?: 1 ?>" defer></script>
</body>
</html>
<?php
}
