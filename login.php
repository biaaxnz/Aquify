<?php
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(AQ_ROOT . '/config.php')) redirect('install.php');
if (current_user()) redirect('index.php');

$err = null; $uname = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $uname = post_str('username');
    if (($left = login_locked($uname)) > 0) $err = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . ceil($left / 60) . ' menit.';
    elseif (attempt_login($uname, (string)($_POST['password'] ?? ''))) {
        login_clear($uname);
        if (!empty($_POST['remember'])) login_remember();
        redirect('index.php');
    } else { login_fail($uname); $err = 'Email/username atau kata sandi salah.'; }
}
page_head('Masuk', '', '', ['auth' => true]);
?>
<div class="auth-top"><a class="iconbtn" href="welcome.php" aria-label="Kembali"><?= icon('back') ?></a></div>
<h1 class="auth-title">Masuk ke Aquify</h1>
<p class="auth-sub">Lanjutkan pekerjaan survei air tanahmu</p>
<?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
<form method="post" autocomplete="on">
  <?= csrf_field() ?>
  <div class="field"><label for="username">Email atau username</label>
    <div class="inp"><?= icon('mail', 18) ?><input id="username" name="username" required autofocus autocomplete="username" placeholder="nama@email.com" value="<?= e($uname) ?>"<?= $err ? ' aria-invalid="true"' : '' ?>></div></div>
  <div class="field"><label for="password">Kata Sandi</label>
    <div class="inp"><?= icon('lock', 18) ?><input id="password" name="password" type="password" required autocomplete="current-password" placeholder="••••••••"<?= $err ? ' aria-invalid="true"' : '' ?>>
      <button class="tog" type="button" data-toggle-pw="password" aria-label="Tampilkan kata sandi"><?= icon('eye', 18) ?></button></div></div>
  <div class="caps" id="capsHint" role="status"><?= icon('alert', 16) ?>Caps Lock aktif</div>
  <div class="rememb"><label><input type="checkbox" name="remember" value="1"> Ingat saya 30 hari</label><span class="muted">Lupa sandi? Hubungi admin.</span></div>
  <button class="btn block" type="submit">Masuk</button>
  <div class="sep">atau</div>
  <button class="btn outline block" type="button" disabled title="Login Google belum dikonfigurasi">Masuk dengan Google</button>
  <p class="under">Belum punya akun? <a href="register.php">Daftar</a></p>
</form>
<?php page_foot();
