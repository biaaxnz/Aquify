<?php
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(AQ_ROOT . '/config.php')) redirect('install.php');
if (current_user()) redirect('index.php');

$v = ['name' => '', 'email' => '', 'phone' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['name' => mb_substr(post_str('name'), 0, 120), 'email' => mb_strtolower(mb_substr(post_str('email'), 0, 160)), 'phone' => preg_replace('/[^0-9+\-\s]/', '', post_str('phone'))];
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($v['name']) < 2) flash('Nama lengkap wajib diisi.', 'err');
    elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) flash('Format email belum benar.', 'err');
    elseif ($v['phone'] !== '' && strlen(preg_replace('/\D/', '', $v['phone'])) < 8) flash('Nomor telepon minimal 8 digit.', 'err');
    elseif (strlen($pw) < 8) flash('Kata sandi minimal 8 karakter.', 'err');
    elseif (empty($_POST['agree'])) flash('Setujui Syarat & Ketentuan untuk melanjutkan.', 'err');
    elseif (q_val('SELECT id FROM users WHERE email=? OR username=?', [$v['email'], $v['email']])) flash('Email ini sudah terdaftar. Silakan masuk.', 'err');
    else {
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '', explode('@', $v['email'])[0]) ?: 'user';
        $uname = substr($base, 0, 40); $i = 1;
        while (q_val('SELECT id FROM users WHERE username=?', [$uname])) $uname = substr($base, 0, 40) . (++$i);
        $row = ['username' => $uname, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'name' => $v['name'], 'email' => $v['email'], 'phone' => $v['phone']];
        if (otp_enabled()) {
            $code = (string)random_int(100000, 999999);
            if (!aq_send_mail($v['email'], 'Kode verifikasi Aquify', "Kode verifikasi Aquify kamu: $code\nKode berlaku 10 menit. Abaikan email ini bila kamu tidak mendaftar.")) {
                flash('Email verifikasi gagal dikirim. Periksa pengaturan email server.', 'err');
                redirect('register.php');
            }
            $_SESSION['pending_reg'] = $row + ['otp' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + 600, 'sent' => time(), 'tries' => 0];
            redirect('otp.php');
        }
        q('INSERT INTO users(username,password_hash,role,full_name,email,phone) VALUES(?,?,?,?,?,?)', [$row['username'], $row['hash'], 'viewer', $row['name'], $row['email'], $row['phone'] ?: null]);
        flash('Akun dibuat. Silakan masuk.');
        redirect('login.php');
    }
}
page_head('Buat Akun', '', '', ['auth' => true]); ?>
<div class="auth-top"><a class="iconbtn" href="welcome.php" aria-label="Kembali"><?= icon('back') ?></a></div>
<h1 class="auth-title">Buat Akun</h1>
<p class="auth-sub">Mulai petakan potensi air tanah di wilayahmu</p>
<form method="post">
  <?= csrf_field() ?>
  <div class="field"><label for="name">Nama Lengkap</label><div class="inp"><?= icon('user', 18) ?><input id="name" name="name" required maxlength="120" autocomplete="name" placeholder="Budi Santoso" value="<?= e($v['name']) ?>"></div></div>
  <div class="field"><label for="email">Email</label><div class="inp"><?= icon('mail', 18) ?><input id="email" name="email" type="email" required autocomplete="email" placeholder="nama@email.com" value="<?= e($v['email']) ?>"></div></div>
  <div class="field"><label for="phone">Nomor Telepon</label><div class="inp"><?= icon('phone', 18) ?><input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0812-3456-7890" value="<?= e($v['phone']) ?>"></div></div>
  <div class="field"><label for="password">Kata Sandi</label><div class="inp"><?= icon('lock', 18) ?><input id="password" name="password" type="password" minlength="8" required autocomplete="new-password" placeholder="Minimal 8 karakter">
    <button class="tog" type="button" data-toggle-pw="password" aria-label="Tampilkan kata sandi"><?= icon('eye', 18) ?></button></div>
    <div class="pwmeter" id="pwMeter" aria-hidden="true"><i></i></div><div class="pwtxt" id="pwText" aria-live="polite"></div></div>
  <div class="checkrow"><input type="checkbox" id="agree" name="agree" value="1" required><label for="agree">Saya menyetujui <b>Syarat &amp; Ketentuan</b> dan <b>Kebijakan Privasi</b> Aquify</label></div>
  <button class="btn block" type="submit">Buat Akun</button>
  <p class="under">Sudah punya akun? <a href="login.php">Masuk</a></p>
</form>
<?php page_foot();
