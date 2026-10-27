<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!current_user()) json_out(['error' => 'Belum masuk.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Metode tidak didukung.'], 405);
if (!csrf_ok()) json_out(['error' => 'CSRF tidak valid. Muat ulang halaman.'], 400);
if (!gemini_ready()) json_out(['error' => 'Gemini belum dikonfigurasi (gemini.api_key di config.php).'], 400);
$id = (int)($_POST['id'] ?? 0);
if (!owned_point($id)) json_out(['error' => 'Titik tidak ditemukan.'], 404);
$q = trim((string)($_POST['q'] ?? ''));
if (mb_strlen($q) < 3) json_out(['error' => 'Tulis pertanyaan minimal 3 karakter.'], 400);
$q = mb_substr($q, 0, 600);
$an = q_one('SELECT * FROM analyses WHERE point_id=? ORDER BY id DESC LIMIT 1', [$id]);
if (!$an) json_out(['error' => 'Titik ini belum dianalisis. Jalankan analisis dulu.'], 400);
// Batas pemakaian: jeda 4 detik dan maksimal 20 pertanyaan per jam per sesi.
$now = time();
$hist = array_values(array_filter($_SESSION['ai_ask'] ?? [], fn($t) => $t > $now - 3600));
if ($hist && $now - end($hist) < 4) json_out(['error' => 'Tunggu beberapa detik sebelum bertanya lagi.'], 429);
if (count($hist) >= 20) json_out(['error' => 'Batas 20 pertanyaan per jam tercapai. Coba lagi nanti.'], 429);
$hist[] = $now; $_SESSION['ai_ask'] = $hist;
session_write_close();
@set_time_limit(90);
$res = json_decode((string)$an['result_json'], true) ?: [];
$S = $res['sounding'] ?? [];
$ctx = aq_ai_context($id, $res, $S);
$aiRow = q_one("SELECT ai_text FROM analyses WHERE point_id=? AND mode='ai' AND ai_text IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]);
$dec = $aiRow ? aq_ai_decode((string)$aiRow['ai_text']) : null;
$r = gemini_ask($ctx, $dec['data'] ?? null, $q);
q('INSERT INTO ai_analysis_logs(analysis_id,point_id,provider,model,prompt_text,response_text,status,error_message,duration_ms) VALUES(?,?,?,?,?,?,?,?,?)',
  [(int)$an['id'], $id, 'gemini', gemini_model(), 'TANYA: ' . $q, $r['text'] ?? null, $r['ok'] ? 'success' : 'failed', $r['error'] ?? null, (int)($r['ms'] ?? 0)]);
if (!$r['ok']) json_out(['error' => $r['error']], 502);
json_out(['answer_html' => render_md((string)$r['text']), 'ms' => $r['ms']]);
