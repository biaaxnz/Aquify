<?php
function cfg(string $path, $default = null) {
    $v = $GLOBALS['AQ_CFG'] ?? [];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function redirect(string $to): void { header('Location: ' . $to); exit; }

function flash(?string $msg = null, string $type = 'ok'): array {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return []; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_ok(): bool {
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
}
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
        http_response_code(400);
        exit('Permintaan tidak valid (token CSRF). Muat ulang halaman lalu coba lagi.');
    }
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fnum($v, int $d = 1): string {
    if ($v === null || $v === '' || !is_numeric($v)) return '–';
    return number_format((float)$v, $d, ',', '.');
}
function fdt(?string $s): string {
    if (!$s) return '–';
    $t = strtotime($s);
    return $t ? date('d M Y H:i', $t) : e($s);
}
function post_str(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }
function post_num(string $k): ?float {
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v === '') return null;
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

/** Mini-markdown aman: escape dulu, lalu ubah **tebal**, `kode`, "## judul", dan daftar "- ". */
function render_md(string $md): string {
    $lines = preg_split('/\R/', e($md));
    $out = '';
    $inList = false;
    $inline = function (string $t): string {
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
        $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
        return $t;
    };
    foreach ($lines as $ln) {
        $t = rtrim($ln);
        if (preg_match('/^\s*[-*]\s+(.*)$/', $t, $m)) {
            if (!$inList) { $out .= '<ul>'; $inList = true; }
            $out .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if ($inList) { $out .= '</ul>'; $inList = false; }
        if ($t === '') continue;
        if (preg_match('/^#{1,4}\s+(.*)$/', $t, $m)) { $out .= '<h4>' . $inline($m[1]) . '</h4>'; continue; }
        $out .= '<p>' . $inline($t) . '</p>';
    }
    if ($inList) $out .= '</ul>';
    return $out;
}
