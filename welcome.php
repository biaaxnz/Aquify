<?php
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(AQ_ROOT . '/config.php')) redirect('install.php');
if (current_user()) redirect('index.php');
$logoV = @filemtime(AQ_ROOT . '/assets/aquify_badge_hd.png') ?: 1;
page_head('Selamat Datang', '', '', ['auth' => true, 'bare' => true]); ?>
<div class="welcome">
  <div class="w-hero"><img src="assets/aquify_badge_hd.png?v=<?= $logoV ?>" alt="Aquify — Smart Groundwater Solutions" width="1024" height="1024"></div>
  <div class="w-body">
    <h1 class="w-title">Selamat datang di Aquify</h1>
    <p class="lead">Aquify memandu survei, uji kualitas air, dan analisa AI langsung dari lapangan untuk menentukan potensi air tanah.</p>
    <a class="btn block" href="login.php">Masuk ke Akun</a>
    <a class="btn block outline" href="register.php">Buat Akun Baru</a>
    <small>Dengan melanjutkan, kamu menyetujui Syarat &amp; Ketentuan serta Kebijakan Privasi Aquify</small>
  </div>
</div>
<?php page_foot();
