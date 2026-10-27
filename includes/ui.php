<?php
/** Pembantu tampilan: tanggal Indonesia, badge, bilah aplikasi, kolom lapisan, penilaian air. */
const AQ_ACTIVE_MIN = 30;

function id_month(int $m): string {
    return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][$m] ?? '';
}
function fdate_id(?string $s, bool $withTime = false): string {
    $t = $s ? strtotime($s) : false;
    if (!$t) return '–';
    return (int)date('j', $t) . ' ' . id_month((int)date('n', $t)) . ' ' . date('Y', $t) . ($withTime ? ', ' . date('H:i', $t) : '');
}
function ago(?string $s): string {
    $t = $s ? strtotime($s) : false;
    if (!$t) return '–';
    $d = time() - $t;
    if ($d < 60) return 'baru saja';
    if ($d < 3600) return floor($d / 60) . ' menit lalu';
    if ($d < 86400) return floor($d / 3600) . ' jam lalu';
    if ($d < 172800) return 'kemarin';
    if ($d < 86400 * 30) return floor($d / 86400) . ' hari lalu';
    return fdate_id($s);
}
function day_label(?string $s): string {
    $t = $s ? strtotime($s) : false;
    if (!$t) return 'Tanpa tanggal';
    $d = date('Y-m-d', $t);
    if ($d === date('Y-m-d')) return 'Hari ini';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin';
    return fdate_id($s);
}
function user_display(): string {
    $u = current_user();
    if (!$u) return '';
    static $name = null;
    if ($name === null) {
        try { $name = (string)q_val('SELECT full_name FROM users WHERE id=?', [(int)$u['id']]); } catch (Throwable $e) { $name = ''; }
    }
    return $name !== '' ? $name : $u['username'];
}
function initials(string $n): string {
    $parts = preg_split('/[\s._-]+/', trim($n)) ?: [];
    $o = '';
    foreach (array_slice(array_filter($parts), 0, 2) as $p) $o .= mb_strtoupper(mb_substr($p, 0, 1));
    return $o !== '' ? $o : '?';
}
function greeting(): string {
    $h = (int)date('G');
    return $h < 11 ? 'Selamat pagi' : ($h < 15 ? 'Selamat siang' : ($h < 18 ? 'Selamat sore' : 'Selamat malam'));
}
function aq_region(): string {
    $r = (string)cfg('app.region', '');
    if ($r !== '') return $r;
    $n = q_val('SELECT name FROM points WHERE owner_id=? ORDER BY id DESC LIMIT 1', [current_user_id()]);
    return $n ? (string)$n : 'Belum ada wilayah kerja';
}

/** Badge berwarna. tone: ok | warn | bad | info | mute */
function badge(string $label, string $tone = 'mute'): string {
    return '<span class="bdg bdg-' . e($tone) . '">' . e($label) . '</span>';
}
/** [label, tone] untuk vonis analisis. $long = "Potensi Tinggi". */
function verdict_tone(?string $v, bool $long = false): array {
    switch ($v) {
        case 'Tinggi': return [$long ? 'Potensi Tinggi' : 'Tinggi', 'ok'];
        case 'Sedang': return [$long ? 'Potensi Sedang' : 'Sedang', 'warn'];
        case 'Rendah': return [$long ? 'Potensi Rendah' : 'Rendah', 'mute'];
        case 'Berisiko asin/lempung': return ['Berisiko', 'bad'];
        case 'Data kurang': return ['Data kurang', 'mute'];
        default: return ['Belum dianalisis', 'mute'];
    }
}
function verdict_pin_color(?string $v): string {
    return ['Tinggi' => '#2CB4A4', 'Sedang' => '#145C84', 'Rendah' => '#D98B3A', 'Berisiko asin/lempung' => '#C4432F'][(string)$v] ?? '#8FA3A8';
}

function app_bar(string $title, ?string $back = null, string $right = ''): void {
    echo '<header class="appbar">';
    if ($back !== null) echo '<a class="iconbtn" href="' . e($back) . '" aria-label="Kembali">' . icon('back', 20) . '</a>';
    echo '<h1>' . e($title) . '</h1>';
    if ($right !== '') echo '<div class="appbar-r">' . $right . '</div>';
    echo '</header>';
}

/* ---------- Lapisan bawah permukaan ---------- */
function strata_color(string $key): string {
    return ['saline' => '#B8C8A4', 'brackish' => '#8CB8C8', 'aquifer' => '#2C6C90', 'dry' => '#C2B391', 'rock' => '#584838'][$key] ?? '#B8C8A4';
}
function strata_name(string $key): string {
    return ['saline' => 'Lempung / asin', 'brackish' => 'Payau / lempung basah', 'aquifer' => 'Akuifer potensial', 'dry' => 'Kering / padat', 'rock' => 'Batuan keras'][$key] ?? $key;
}
/** Kolom stratigrafi bergaya mockup. $layers = hasil aq_layers() / analysis['layers']. */
function strata_html(array $layers, string $id = ''): string {
    if (!$layers) return '<div class="empty-sm">Belum ada profil lapisan. Butuh minimal 3 jarak elektroda.</div>';
    $max = max(array_map(fn($l) => (float)$l['to'], $layers));
    if ($max <= 0) return '';
    $h = (int)max(170, min(300, $max * 8));
    $step = $max > 60 ? 20 : ($max > 25 ? 10 : 5);
    $o = '<div class="strata-wrap"' . ($id ? ' id="' . e($id) . '"' : '') . '><div class="strata-ticks" style="height:' . $h . 'px">';
    for ($d = 0; $d <= $max + 0.01; $d += $step) $o .= '<span style="top:' . round($d / $max * 100, 2) . '%">' . $d . 'm</span>';
    $o .= '</div><div class="strata" style="height:' . $h . 'px">';
    foreach ($layers as $L) {
        $th = max(0.1, (float)$L['to'] - (float)$L['from']);
        $key = $L['key'] ?? 'saline';
        $dark = in_array($key, ['aquifer', 'rock'], true) ? ' on-dark' : '';
        $o .= '<div class="strata-l' . $dark . '" style="flex:' . round($th, 3) . ';background:' . e(strata_color($key)) . '" title="' . e(fnum($L['from'], 1) . '–' . fnum($L['to'], 1) . ' m') . '"><span>' . e(strata_name($key)) . '</span><em>' . e(round((float)$L['rho'])) . ' Ωm</em></div>';
    }
    return $o . '</div></div>';
}
function strata_legend_bar(array $totals): string {
    $sum = array_sum($totals);
    if ($sum <= 0) return '<div class="empty-sm">Belum ada analisis lapisan.</div>';
    $bar = '<div class="sbar">'; $leg = '<div class="sleg">';
    foreach ($totals as $k => $v) {
        $pct = $v / $sum * 100;
        $bar .= '<i style="flex:' . round($v, 2) . ';background:' . strata_color($k) . '" title="' . e(strata_name($k)) . '"></i>';
        $leg .= '<span><b style="background:' . strata_color($k) . '"></b>' . e(strata_name($k)) . ' <em>' . round($pct) . '%</em></span>';
    }
    return $bar . '</div>' . $leg . '</div>';
}

/* ---------- Penilaian kualitas air (indikatif) ---------- */
/** @return array<int,array> baris parameter dengan status. */
function water_params(array $w): array {
    $v = fn($k) => (isset($w[$k]) && $w[$k] !== null && $w[$k] !== '') ? (float)$w[$k] : null;
    $ratio = function (?float $x, float $lim): array {
        if ($x === null) return ['', 'mute'];
        $r = $x / $lim;
        return $r < 0.8 ? ['Baik', 'ok'] : ($r <= 1 ? ['Sedang', 'warn'] : ['Buruk', 'bad']);
    };
    $ec = $v('ec_us'); $tds = $v('tds_ppm');
    $ph = $v('ph'); $t = $v('temp_c'); $tb = $v('turbidity_ntu');
    $rows = [];
    $phS = $ph === null ? ['', 'mute'] : (($ph >= 6.8 && $ph <= 8.2) ? ['Baik', 'ok'] : (($ph >= 6.5 && $ph <= 8.5) ? ['Sedang', 'warn'] : ['Buruk', 'bad']));
    $rows['ph'] = ['label' => 'pH', 'unit' => '', 'val' => $ph, 'dec' => 1, 'limit' => '6,5–8,5', 'frac' => $ph === null ? 0 : min(1, $ph / 14), 'st' => $phS[0], 'tone' => $phS[1]];
    $s = $ratio($tds, 500);
    $rows['tds'] = ['label' => 'TDS', 'unit' => 'ppm', 'val' => $tds, 'dec' => 0, 'limit' => '500', 'frac' => $tds === null ? 0 : min(1, $tds / 500), 'st' => $s[0], 'tone' => $s[1]];
    $tS = $t === null ? ['', 'mute'] : (($t >= 15 && $t <= 32) ? ['Normal', 'info'] : ['Perlu cek', 'warn']);
    $rows['temp'] = ['label' => 'Suhu', 'unit' => '°C', 'val' => $t, 'dec' => 1, 'limit' => '15–32', 'frac' => $t === null ? 0 : min(1, $t / 50), 'st' => $tS[0], 'tone' => $tS[1]];
    $s = $ratio($tb, 5);
    $rows['turb'] = ['label' => 'Kekeruhan', 'unit' => 'NTU', 'val' => $tb, 'dec' => 1, 'limit' => '5,0', 'frac' => $tb === null ? 0 : min(1, $tb / 5), 'st' => $s[0], 'tone' => $s[1]];
    $s = $ratio($ec, 1000);
    $rows['ec'] = ['label' => 'Konduktivitas', 'unit' => 'µS/cm', 'val' => $ec, 'dec' => 0, 'limit' => '1000', 'frac' => $ec === null ? 0 : min(1, $ec / 1000), 'st' => $s[0], 'tone' => $s[1]];
    return $rows;
}
function water_overall(array $w): array {
    $rows = water_params($w);
    $has = false; $bad = []; $warn = [];
    foreach ($rows as $r) {
        if ($r['val'] === null) continue;
        $has = true;
        if ($r['tone'] === 'bad') $bad[] = $r['label'];
        if ($r['tone'] === 'warn') $warn[] = $r['label'];
    }
    if (!$has) return ['tone' => 'mute', 'title' => 'Belum ada data', 'short' => 'Belum ada data', 'bad' => [], 'warn' => []];
    if ($bad) return ['tone' => 'bad', 'title' => 'Tidak Layak Konsumsi', 'short' => 'Tidak layak', 'bad' => $bad, 'warn' => $warn];
    return ['tone' => 'ok', 'title' => 'Layak Konsumsi', 'short' => 'Layak', 'bad' => [], 'warn' => $warn];
}
function water_notes(array $w): array {
    $rows = water_params($w); $o = [];
    foreach ($rows as $r) {
        if ($r['val'] === null) continue;
        $val = fnum($r['val'], $r['dec']) . ($r['unit'] ? ' ' . $r['unit'] : '');
        if ($r['tone'] === 'bad') $o[] = $r['label'] . ' (' . $val . ') melewati ambang batas ' . $r['limit'] . '.';
        elseif ($r['tone'] === 'warn') $o[] = $r['label'] . ' (' . $val . ') mendekati ambang batas ' . $r['limit'] . '.';
    }
    if (!$o) $o[] = 'Semua parameter yang terbaca berada dalam batas acuan.';
    $o[] = 'Penilaian indikatif dari sensor, bukan hasil uji laboratorium.';
    return $o;
}

/** Indikator langkah alur survey: 1 Lokasi, 2 Konfigurasi, 3 Pengukuran, 4 Analisis. */
function step_bar(int $cur): void {
    $labels = ['Lokasi', 'Konfigurasi', 'Pengukuran', 'Analisis'];
    echo '<nav class="steps" aria-label="Langkah survey">';
    foreach ($labels as $i => $l) {
        $n = $i + 1;
        $cls = $n < $cur ? 'done' : ($n === $cur ? 'on' : '');
        if ($i) echo '<span class="ln' . ($n <= $cur ? ' done' : '') . '"></span>';
        echo '<span class="st ' . $cls . '"' . ($n === $cur ? ' aria-current="step"' : '') . '><i>' . ($n < $cur ? '✓' : $n) . '</i><span>' . e($l) . '</span></span>';
    }
    echo '</nav>';
}

/** Daftar "Mulai cepat" untuk pengguna baru, dihitung dari data nyata. */
function aq_quickstart(): array {
    $uid = current_user_id();
    $np = (int)q_val('SELECT COUNT(*) FROM points WHERE owner_id=?', [$uid]);
    $nd = count(aq_devices());
    $withData = q_one('SELECT p.id AS point_id FROM points p JOIN measurements m ON m.point_id=p.id WHERE p.owner_id=? GROUP BY p.id HAVING COUNT(DISTINCT m.spacing_m) >= 3 ORDER BY MAX(m.measured_at) DESC LIMIT 1', [$uid]);
    $na = (int)q_val('SELECT COUNT(*) FROM analyses a JOIN points p ON p.id=a.point_id WHERE p.owner_id=?', [$uid]);
    $nai = (int)q_val("SELECT COUNT(*) FROM analyses a JOIN points p ON p.id=a.point_id WHERE p.owner_id=? AND a.mode='ai' AND a.ai_text IS NOT NULL", [$uid]);
    $latest = (int)q_val('SELECT id FROM points WHERE owner_id=? ORDER BY id DESC LIMIT 1', [$uid]);
    $pid = $withData ? (int)$withData['point_id'] : $latest;
    $steps = [
        ['Buat survey pertama', 'Tentukan lokasi dan jenis survey', $np > 0, 'survey-new.php'],
        ['Hubungkan perangkat', 'ESP32 lewat USB atau Firebase', $nd > 0, 'device.php'],
        ['Ukur minimal 3 jarak elektroda', 'Dari perangkat atau input manual', (bool)$withData, $latest ? 'survey-run.php?id=' . $latest : 'survey-new.php'],
        ['Jalankan analisis', 'Lihat profil lapisan dan skor potensi', $na > 0, $pid ? 'point.php?id=' . $pid : 'survey-new.php'],
    ];
    if (gemini_ready()) $steps[] = ['Coba analisis AI mendalam', 'Rekomendasi bor, risiko, dan saran', $nai > 0, $pid ? 'analysis-view.php?id=' . $pid : 'survey-new.php'];
    return $steps;
}
