<?php
function db_connect(array $c): PDO {
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)($c['port'] ?? 3306) . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    $db = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Samakan zona waktu sesi MySQL dengan PHP agar CURRENT_TIMESTAMP dan "x menit lalu" konsisten.
    try { $db->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
    return $db;
}

function pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = db_connect(cfg('db'));
        } catch (PDOException $e) {
            aq_db_error_page($e);
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = pdo()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q_all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function q_one(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r ?: null; }
function q_val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

function state_get(string $k): ?string {
    $v = q_val('SELECT state_value FROM sync_state WHERE state_key=?', [$k]);
    return $v === null ? null : (string)$v;
}
function state_set(string $k, ?string $v): void {
    q('INSERT INTO sync_state(state_key,state_value) VALUES(?,?) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)', [$k, $v]);
}

/** Cek apakah kolom ada (di-cache), agar kode tetap jalan bila migrasi belum bisa dijalankan. */
function aq_has_col(string $table, string $col): bool {
    static $c = [];
    $k = $table . '.' . $col;
    if (!isset($c[$k])) $c[$k] = (int)q_val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $col]) > 0;
    return $c[$k];
}

/** Halaman galat koneksi database yang menjelaskan penyebab dan langkah perbaikannya. */
function aq_db_error_page(PDOException $e): void {
    $c = (array)cfg('db', []);
    $code = (int)($e->errorInfo[1] ?? $e->getCode());
    $msg = (string)$e->getMessage();
    foreach ([(string)($c['pass'] ?? '')] as $secret) if ($secret !== '') $msg = str_replace($secret, '***', $msg);
    $host = (string)($c['host'] ?? 'localhost'); $port = (int)($c['port'] ?? 3306); $name = (string)($c['name'] ?? ''); $user = (string)($c['user'] ?? '');
    if ($code === 2002 || $code === 2003 || stripos($msg, 'refused') !== false || stripos($msg, 'No such file') !== false) {
        $why = 'MySQL tidak menjawab di ' . $host . ':' . $port . '. Biasanya MySQL belum dinyalakan.';
        $fix = ['Buka <b>XAMPP Control Panel</b>, lalu tekan <b>Start</b> pada baris <b>MySQL</b> sampai berwarna hijau.', 'Bila langsung mati lagi, lihat tombol <b>Logs</b> di baris MySQL. Penyebab umum: port 3306 dipakai program lain.', 'Bila MySQL memakai port lain, ubah <code>port</code> di <code>config.php</code> agar sama.'];
    } elseif ($code === 1045) {
        $why = 'Username atau password database ditolak (user: ' . $user . ').';
        $fix = ['Samakan <code>user</code> dan <code>pass</code> di <code>config.php</code> dengan akun MySQL Anda. Bawaan XAMPP: user <code>root</code>, password kosong.', 'Pastikan tidak ada spasi atau tanda kutip yang hilang saat mengedit <code>config.php</code>.'];
    } elseif ($code === 1049) {
        $why = 'Database "' . $name . '" belum ada di MySQL.';
        $fix = ['Buka <b>phpMyAdmin</b> dan cek nama database yang benar, lalu samakan <code>name</code> di <code>config.php</code>.', 'Atau buat database kosong bernama itu, lalu buka <code>install.php</code>.'];
    } elseif ($code === 1044) {
        $why = 'User "' . $user . '" tidak punya izin ke database "' . $name . '".';
        $fix = ['Beri hak akses di phpMyAdmin (Hak Istimewa) atau pakai user <code>root</code>.'];
    } else {
        $why = 'Koneksi ke database gagal.';
        $fix = ['Periksa bagian <code>db</code> di <code>config.php</code> (host, port, nama, user, password).', 'Pastikan MySQL menyala di XAMPP Control Panel.'];
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database belum terhubung</title>'
       . '<body style="margin:0;background:#F4F8F8;font:16px/1.55 system-ui,Segoe UI,sans-serif;color:#102428"><div style="max-width:560px;margin:8vh auto;padding:0 20px">'
       . '<div style="background:#fff;border:1px solid #E1EBEB;border-radius:18px;padding:26px">'
       . '<h1 style="font-size:1.35rem;margin:0 0 8px">Database belum terhubung</h1><p style="margin:0 0 14px;color:#C4432F"><b>' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8') . '</b></p>'
       . '<p style="margin:0 0 6px">Cara memperbaiki:</p><ol style="margin:0 0 16px;padding-left:20px">';
    foreach ($fix as $f) echo '<li style="margin-bottom:6px">' . $f . '</li>';
    echo '</ol><p style="margin:0 0 16px"><a href="" style="display:inline-block;background:#145C84;color:#fff;text-decoration:none;padding:11px 20px;border-radius:12px;font-weight:600">Coba lagi</a></p>'
       . '<details style="color:#6A8085;font-size:.85rem"><summary style="cursor:pointer">Detail teknis</summary><p style="word-break:break-word">Kode ' . (int)$code . ': ' . htmlspecialchars(mb_substr($msg, 0, 300), ENT_QUOTES, 'UTF-8') . '</p></details>'
       . '</div></div></body></html>';
    exit;
}
