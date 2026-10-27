<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!owned_point($id)) { flash('Titik tidak ditemukan.', 'err'); redirect('points.php'); }
$mode = ($_POST['mode'] ?? '') === 'ai' ? 'ai' : 'rule';
if ($mode === 'ai' && !gemini_ready()) { flash('Gemini belum dikonfigurasi (gemini.api_key di config.php).', 'err'); redirect('analysis-view.php?id=' . $id); }
@set_time_limit(90);
session_write_close();
$out = analyze_point($id, $mode);
session_start();
if ($mode === 'ai' && !empty($out['result']['ai_error'])) flash('Analisis aturan tersimpan, tetapi AI gagal: ' . $out['result']['ai_error'], 'err');
else flash($mode === 'ai' ? 'Analisis AI selesai.' : 'Analisis selesai.');
redirect($mode === 'ai' && empty($out['result']['ai_error']) ? 'analysis-view.php?id=' . $id : 'point.php?id=' . $id . '#analisis');
