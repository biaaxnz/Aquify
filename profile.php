<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = mb_substr(post_str('name'), 0, 120); $email = mb_strtolower(mb_substr(post_str('email'), 0, 160)); $phone = preg_replace('/[^0-9+\-\s]/', '', post_str('phone'));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Format email belum benar.', 'err');
    elseif ($email !== '' && q_val('SELECT id FROM users WHERE email=? AND id<>?', [$email, (int)$u['id']])) flash('Email dipakai akun lain.', 'err');
    else { q('UPDATE users SET full_name=?, email=?, phone=? WHERE id=?', [$name ?: null, $email ?: null, $phone ?: null, (int)$u['id']]); flash('Profil disimpan.'); }
    redirect('profile.php');
}
$me = q_one('SELECT username,role,full_name,email,phone,created_at FROM users WHERE id=?', [(int)$u['id']]) ?: [];
page_head('Profil', 'profile');
app_bar('Profil', 'index.php'); ?>
<div class="center" style="margin:6px 0 22px"><span class="av" style="width:72px;height:72px;border-radius:22px;background:var(--navy2);color:#fff;display:inline-grid;place-items:center;font:700 1.6rem var(--f-head)"><?= e(initials(user_display())) ?></span>
  <h2 style="margin:12px 0 2px"><?= e(user_display()) ?></h2><p class="muted" style="margin:0"><?= e($me['username'] ?? '') ?> · <?= e($me['role'] ?? '') ?></p></div>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="field"><label for="name">Nama lengkap</label><input id="name" name="name" maxlength="120" value="<?= e($me['full_name'] ?? '') ?>"></div>
  <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e($me['email'] ?? '') ?>"></div>
  <div class="field"><label for="phone">Nomor telepon</label><input id="phone" name="phone" type="tel" value="<?= e($me['phone'] ?? '') ?>"></div>
  <button class="btn" type="submit">Simpan profil</button>
</form>
<div class="menu">
  <a href="settings.php"><?= icon('settings', 20) ?>Pengaturan<?= icon('chev-right', 18) ?></a>
  <a href="notifications.php"><?= icon('bell', 20) ?>Notifikasi<?= icon('chev-right', 18) ?></a>
  <?php if (is_admin()): ?><a href="users.php"><?= icon('users', 20) ?>Kelola pengguna<?= icon('chev-right', 18) ?></a><?php endif; ?>
  <a href="logout.php"><?= icon('logout', 20) ?>Keluar<?= icon('chev-right', 18) ?></a>
</div>
<?php page_foot();
