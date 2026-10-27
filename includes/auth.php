<?php
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function current_user_id(): int { return (int)(current_user()['id'] ?? 0); }
function owned_point(int $id): ?array {
    return $id > 0 ? q_one('SELECT * FROM points WHERE id=? AND owner_id=?', [$id, current_user_id()]) : null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}
function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }
function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        flash('Hanya admin yang boleh melakukan ini.', 'err');
        redirect('index.php');
    }
    return $u;
}

function attempt_login(string $username, string $password): bool {
    $row = q_one('SELECT id,username,password_hash,role FROM users WHERE username=? OR email=? LIMIT 1', [$username, $username]);
    $hash = $row['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    $ok = password_verify($password, $hash) && $row;
    if (!$ok) { usleep(400000); return false; }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role']];
    return true;
}

function aq_send_mail(string $to, string $subject, string $body): bool {
    $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $from = (string)cfg('mail.from', 'no-reply@' . preg_replace('/:\d+$/', '', $host));
    $headers = "From: Aquify <$from>\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
function otp_enabled(): bool { return (bool)cfg('mail.otp', false); }
function mask_email(string $e): string {
    [$u, $d] = array_pad(explode('@', $e, 2), 2, '');
    return mb_substr($u, 0, 1) . str_repeat('•', max(2, min(4, mb_strlen($u) - 1))) . mb_substr($u, -1) . '@' . $d;
}

/* Pembatasan percobaan login: 5 gagal per (IP + nama) -> kunci 5 menit. */
function login_key(string $u): string { return 'lf_' . substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . mb_strtolower(trim($u))), 0, 24); }
function login_locked(string $u): int {
    $v = json_decode((string)state_get(login_key($u)), true);
    if (is_array($v) && (int)($v['n'] ?? 0) >= 5) { $left = 300 - (time() - (int)($v['t'] ?? 0)); return $left > 0 ? $left : 0; }
    return 0;
}
function login_fail(string $u): void {
    $k = login_key($u); $v = json_decode((string)state_get($k), true);
    $n = (is_array($v) && time() - (int)($v['t'] ?? 0) < 300) ? (int)($v['n'] ?? 0) + 1 : 1;
    state_set($k, json_encode(['n' => $n, 't' => time()]));
}
function login_clear(string $u): void { state_set(login_key($u), null); }
/** Perpanjang cookie sesi (centang "Ingat saya"): 30 hari. */
function login_remember(): void {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(session_name(), session_id(), ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}
