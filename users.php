<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = post_str('do');
    if ($do === 'add') {
        $u = post_str('username'); $pw = (string)($_POST['password'] ?? ''); $role = post_str('role') === 'admin' ? 'admin' : 'viewer';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $u)) flash('Username 3–50 karakter (huruf, angka, _ . -).', 'err');
        elseif (strlen($pw) < 8) flash('Password minimal 8 karakter.', 'err');
        elseif (q_val('SELECT id FROM users WHERE username=?', [$u])) flash('Username sudah dipakai.', 'err');
        else { q('INSERT INTO users(username,password_hash,role) VALUES(?,?,?)', [$u, password_hash($pw, PASSWORD_DEFAULT), $role]); flash('Pengguna ditambahkan.'); }
    } elseif ($do === 'delete') {
        $uid = (int)post_str('uid');
        if ($uid === (int)$me['id']) flash('Tidak bisa menghapus akun sendiri.', 'err');
        else { q('DELETE FROM users WHERE id=?', [$uid]); flash('Pengguna dihapus.'); }
    }
    redirect('users.php');
}
$rows = q_all('SELECT id,username,role,created_at FROM users ORDER BY username');
page_head('Pengguna', 'users');
?>
<div class="page-title"><div><h1>Pengguna</h1><p>Admin boleh mengubah data dan menyinkron. Viewer hanya melihat dan menjalankan analisis.</p></div></div>
<section class="panel"><div class="tbl-wrap"><table><thead><tr><th>Username</th><th>Peran</th><th>Dibuat</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['username']) ?></td><td><?= e($r['role']) ?></td><td><?= fdt($r['created_at']) ?></td>
<td><?php if ((int)$r['id'] !== (int)$me['id']): ?><form method="post" data-confirm="Hapus pengguna ini?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="uid" value="<?= (int)$r['id'] ?>"><button class="btn ghost sm" type="submit">Hapus</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><h2>Tambah pengguna</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="add">
  <div class="form-grid">
    <div><label for="username">Username</label><input id="username" name="username" required></div>
    <div><label for="password">Password</label><input id="password" name="password" type="password" minlength="8" required autocomplete="new-password"></div>
    <div><label for="role">Peran</label><select id="role" name="role"><option value="viewer">viewer</option><option value="admin">admin</option></select></div>
  </div><p><button class="btn" type="submit">Tambah</button></p>
</form></section>
<?php page_foot();
