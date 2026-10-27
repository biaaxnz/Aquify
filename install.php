<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_file(AQ_ROOT . '/installed.lock')) {
    http_response_code(403);
    exit('Aquify sudah terpasang. Hapus install.php dari server demi keamanan.');
}

$err = null;
$f = ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'fb_url' => '', 'admin' => 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (array_keys($f) as $k) $f[$k] = post_str($k, (string)$f[$k]);
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $fbSecret = post_str('fb_secret');
    $gem = post_str('gemini_key');
    $mt = post_str('maptiler_key');

    if ($f['db_name'] === '' || $f['db_user'] === '') $err = 'Nama database dan user database wajib diisi.';
    elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $f['admin'])) $err = 'Username admin 3–50 karakter (huruf, angka, _ . -).';
    elseif (strlen($adminPass) < 8) $err = 'Password admin minimal 8 karakter.';
    elseif ($f['fb_url'] !== '' && !preg_match('#^https://#i', $f['fb_url'])) $err = 'URL Firebase harus diawali https://';
    else {
        $dbc = ['host' => $f['db_host'], 'port' => (int)$f['db_port'], 'name' => $f['db_name'], 'user' => $f['db_user'], 'pass' => $dbPass];
        try {
            $pdo = db_connect($dbc);
            $sql = file_get_contents(AQ_ROOT . '/database/schema.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', (string)$sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
            $st = $pdo->prepare('INSERT INTO users(username,password_hash,role) VALUES(?,?,?)
                                 ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role="admin"');
            $st->execute([$f['admin'], password_hash($adminPass, PASSWORD_DEFAULT), 'admin']);

            $conf = [
                'db' => $dbc,
                'firebase' => ['db_url' => rtrim($f['fb_url'], '/'), 'secret' => $fbSecret, 'paths' => ['measurements' => 'measurements', 'water' => 'water']],
                'sync' => ['auto_interval_sec' => 60, 'live_interval_sec' => 8, 'token' => bin2hex(random_bytes(16))],
                'maps' => ['maptiler_key' => $mt],
                'app' => ['name' => 'Aquify', 'region' => ''],
                'mail' => ['otp' => false, 'from' => 'no-reply@localhost'],
                'ingest' => ['token' => bin2hex(random_bytes(16))],
                'gemini' => ['api_key' => $gem, 'model' => 'gemini-3.6-flash'],
            ];
            $code = "<?php\n// Dibuat oleh install.php\nreturn " . var_export($conf, true) . ";\n";
            if (@file_put_contents(AQ_ROOT . '/config.php', $code, LOCK_EX) === false) {
                $err = 'Database sudah dibuat, tetapi config.php tidak bisa ditulis. Salin config.sample.php menjadi config.php secara manual.';
            } else {
                @file_put_contents(AQ_ROOT . '/installed.lock', date('c'));
                flash('Instalasi selesai. Masuk dengan akun admin, lalu hapus install.php dari server.');
                redirect('login.php');
            }
        } catch (Throwable $e) {
            $err = 'Gagal: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pasang Aquify</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;family=Space+Grotesk:wght@500;700&amp;display=swap">
<link rel="stylesheet" href="assets/style.css"></head>
<body class="is-auth"><main class="auth-main">
<h1 class="auth-title">Pasang Aquify</h1>
<p class="auth-sub">Isi data database MySQL (dari phpMyAdmin / panel hosting). Tabel dibuat otomatis.</p>
<?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="group" style="margin-top:0">Database</div>
  <div class="form-grid field">
    <div><label for="db_host">Host</label><input id="db_host" name="db_host" value="<?= e($f['db_host']) ?>" required></div>
    <div><label for="db_port">Port</label><input id="db_port" name="db_port" value="<?= e($f['db_port']) ?>" required></div>
  </div>
  <div class="field"><label for="db_name">Nama database</label><input id="db_name" name="db_name" value="<?= e($f['db_name']) ?>" placeholder="aquify" required></div>
  <div class="field"><label for="db_user">User database</label><input id="db_user" name="db_user" value="<?= e($f['db_user']) ?>" placeholder="root" required></div>
  <div class="field"><label for="db_pass">Password database</label><input id="db_pass" name="db_pass" type="password" autocomplete="off"><p class="hint">XAMPP bawaan: user <code>root</code>, password kosong.</p></div>
  <div class="group">Akun admin</div>
  <div class="field"><label for="admin">Username</label><input id="admin" name="admin" value="<?= e($f['admin']) ?>" required></div>
  <div class="field"><label for="admin_pass">Password (min. 8 karakter)</label><input id="admin_pass" name="admin_pass" type="password" minlength="8" required autocomplete="new-password"></div>
  <div class="group">Firebase, peta &amp; AI (boleh dikosongkan dulu)</div>
  <div class="field"><label for="fb_url">URL Realtime Database</label><input id="fb_url" name="fb_url" placeholder="https://nama-proyek-default-rtdb.asia-southeast1.firebasedatabase.app" value="<?= e($f['fb_url']) ?>"></div>
  <div class="field"><label for="fb_secret">Firebase database secret (opsional)</label><input id="fb_secret" name="fb_secret" autocomplete="off"></div>
  <div class="field"><label for="maptiler_key">Kunci MapTiler (opsional)</label><input id="maptiler_key" name="maptiler_key" autocomplete="off"><p class="hint">Kosong = peta memakai Esri + OpenStreetMap.</p></div>
  <div class="field"><label for="gemini_key">Gemini API key (opsional)</label><input id="gemini_key" name="gemini_key" autocomplete="off"></div>
  <button class="btn" type="submit">Pasang</button>
</form>
</main></body></html>
