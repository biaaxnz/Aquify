<?php
require __DIR__ . '/includes/bootstrap.php';
if (!is_file(AQ_ROOT . '/config.php')) redirect('install.php');
if (current_user()) redirect('index.php');
$pend = $_SESSION['pending_reg'] ?? null;
if (!$pend) { flash('Mulai dari pendaftaran akun.', 'err'); redirect('register.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post_str('do') === 'resend') {
        if (time() - (int)$pend['sent'] < 60) flash('Tunggu sebentar sebelum meminta kode baru.', 'err');
        else {
            $code = (string)random_int(100000, 999999);
            if (aq_send_mail($pend['email'], 'Kode verifikasi Aquify', "Kode verifikasi Aquify kamu: $code\nKode berlaku 10 menit.")) {
                $_SESSION['pending_reg'] = array_merge($pend, ['otp' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + 600, 'sent' => time(), 'tries' => 0]);
                flash('Kode baru sudah dikirim.');
            } else flash('Email gagal dikirim. Coba lagi nanti.', 'err');
        }
        redirect('otp.php');
    }
    $code = preg_replace('/\D/', '', post_str('code'));
    if (time() > (int)$pend['exp']) flash('Kode sudah kedaluwarsa. Minta kode baru.', 'err');
    elseif ((int)$pend['tries'] >= 5) flash('Terlalu banyak percobaan. Minta kode baru.', 'err');
    elseif (!password_verify($code, (string)$pend['otp'])) { $_SESSION['pending_reg']['tries']++; flash('Kode tidak cocok. Periksa lagi email kamu.', 'err'); }
    elseif (q_val('SELECT id FROM users WHERE email=?', [$pend['email']])) { unset($_SESSION['pending_reg']); flash('Email ini sudah terdaftar. Silakan masuk.', 'err'); redirect('login.php'); }
    else {
        q('INSERT INTO users(username,password_hash,role,full_name,email,phone) VALUES(?,?,?,?,?,?)', [$pend['username'], $pend['hash'], 'viewer', $pend['name'], $pend['email'], $pend['phone'] ?: null]);
        unset($_SESSION['pending_reg']);
        flash('Email terverifikasi. Silakan masuk.');
        redirect('login.php');
    }
    redirect('otp.php');
}
$wait = max(0, 60 - (time() - (int)$pend['sent']));
page_head('Verifikasi Email', '', '', ['auth' => true]); ?>
<div class="auth-top"><a class="iconbtn" href="register.php" aria-label="Kembali"><?= icon('back') ?></a></div>
<div class="center">
  <div class="otp-ic"><?= icon('mail', 28) ?></div>
  <h1 class="auth-title">Verifikasi Email Kamu</h1>
  <p class="auth-sub" style="margin-bottom:0">Kami sudah kirim kode 6 digit ke<br><b style="color:var(--ink)"><?= e(mask_email($pend['email'])) ?></b></p>
</div>
<form method="post" id="otpForm">
  <?= csrf_field() ?><input type="hidden" name="code" id="otpCode">
  <div class="otp" role="group" aria-label="Kode verifikasi 6 digit"><?php for ($i = 0; $i < 6; $i++): ?><input inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="<?= $i ? 'off' : 'one-time-code' ?>" aria-label="Digit <?= $i + 1 ?>"><?php endfor; ?></div>
  <button class="btn block" type="submit">Verifikasi</button>
</form>
<form method="post" class="center" style="margin-top:20px"><?= csrf_field() ?><input type="hidden" name="do" value="resend">
  <span class="muted" id="resendTxt" data-wait="<?= $wait ?>">Kirim ulang kode dalam <b class="mono" id="resendT">0:<?= str_pad((string)$wait, 2, '0', STR_PAD_LEFT) ?></b></span>
  <button class="btn ghost sm" id="resendBtn" type="submit" hidden>Kirim ulang kode</button>
</form>
<?php page_foot();
