<?php
/* Visual penampang tahanan tanah: skala warna logaritmik ala RES2DINV, penampang model lapisan, kurva sounding, pseudosection. */

function aq_res_stops(): array {
    return [[1.35, [10, 10, 94]], [4, [0, 0, 224]], [12, [10, 132, 255]], [20, [0, 229, 229]], [35, [0, 201, 135]], [60, [61, 220, 61]],
            [105, [127, 184, 0]], [190, [242, 230, 0]], [310, [217, 154, 0]], [600, [255, 106, 0]], [919, [240, 30, 30]], [1800, [176, 0, 64]], [2726, [106, 0, 106]]];
}
function aq_res_rgb(float $rho): array {
    $st = aq_res_stops();
    $r = max($st[0][0], min(end($st)[0], $rho));
    for ($i = 0; $i < count($st) - 1; $i++) {
        [$a, $ca] = $st[$i]; [$b, $cb] = $st[$i + 1];
        if ($r <= $b) {
            $t = (log10($r) - log10($a)) / (log10($b) - log10($a));
            return [(int)round($ca[0] + ($cb[0] - $ca[0]) * $t), (int)round($ca[1] + ($cb[1] - $ca[1]) * $t), (int)round($ca[2] + ($cb[2] - $ca[2]) * $t)];
        }
    }
    return end($st)[1];
}
function aq_res_color(float $rho): string { [$r, $g, $b] = aq_res_rgb($rho); return sprintf('#%02X%02X%02X', $r, $g, $b); }
function aq_on_color(float $rho): string { [$r, $g, $b] = aq_res_rgb($rho); return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#14282C' : '#FFFFFF'; }

function aq_plain_layer(string $key): array {
    return [
        'saline' => ['Lempung / tanah basah', 'Menahan air dan sulit dialiri. Tahanan sangat rendah juga bisa tanda air asin.'],
        'brackish' => ['Tanah lembap (payau)', 'Mungkin lempung berpasir atau air yang agak asin.'],
        'aquifer' => ['Lapisan berpotensi air', 'Pasir atau kerikil jenuh air tawar. Inilah target pengeboran.'],
        'dry' => ['Tanah kering / padat', 'Kurang air: pasir kering atau lapukan batuan.'],
        'rock' => ['Batuan keras', 'Sulit dibor dan biasanya tidak menyimpan air.'],
    ][$key] ?? [$key, ''];
}

/** Legenda warna: bilah gradasi, angka tahanan, dan arti warna dalam bahasa awam. */
function aq_res_legend(): string {
    $stops = []; $min = 1; $max = 3000;
    for ($i = 0; $i <= 24; $i++) { $v = pow(10, log10($min) + (log10($max) - log10($min)) * $i / 24); $stops[] = aq_res_color($v) . ' ' . round($i / 24 * 100, 1) . '%'; }
    $tot = log10($max) - log10($min);
    $bands = [[1, 10, 'Lempung / asin'], [10, 25, 'Payau'], [25, 150, 'Berpotensi air'], [150, 500, 'Kering'], [500, 3000, 'Batuan']];
    $o = '<div class="rlegend" role="img" aria-label="Skala warna tahanan: biru rendah, hijau berpotensi air, kuning sampai merah kering atau batuan"><div class="rl-bar" style="background:linear-gradient(90deg,' . implode(',', $stops) . ')"></div><div class="rl-ticks">';
    foreach ([1, 10, 100, 1000] as $t) $o .= '<span style="left:' . round((log10($t) - log10($min)) / $tot * 100, 1) . '%">' . $t . '</span>';
    $o .= '</div><div class="rl-bands">';
    foreach ($bands as [$a, $b, $l]) $o .= '<span style="flex:' . round((log10($b) - log10($a)) / $tot, 3) . '">' . e($l) . '</span>';
    return $o . '</div><p class="rl-note"><b>Tahanan (Ω·m).</b> Biru = tanah basah, lempung, atau asin · Hijau = lapisan yang berpotensi menyimpan air tawar · Kuning sampai merah = kering atau batuan keras.</p></div>';
}

function aq_nice_step(float $range, int $target = 5): float {
    $raw = $range / max(1, $target); $p = pow(10, floor(log10(max($raw, 1e-9)))); $f = $raw / $p;
    return ($f < 1.5 ? 1 : ($f < 3.5 ? 2 : ($f < 7.5 ? 5 : 10))) * $p;
}

/**
 * Penampang tahanan tanah dari model lapisan (1D): warna halus mengikuti tahanan tiap lapisan.
 * $o: electrodes, length (m), drill_min, drill_max, drill (kedalaman tunggal)
 */
function aq_section_svg(array $layers, array $o = []): string {
    if (!$layers) return '<div class="empty-sm">Belum ada profil lapisan. Butuh minimal 3 jarak elektroda.</div>';
    $maxD = max(array_map(fn($l) => (float)$l['to'], $layers));
    if ($maxD <= 0) return '';
    $W = 480; $H = 300; $ml = 46; $mr = 12; $mt = 42; $mb = 12; $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $y = fn($d) => $mt + max(0, min(1, $d / $maxD)) * $ph;
    $step = aq_nice_step($maxD, 5);
    $id = 'sg' . substr(md5(json_encode($layers)), 0, 6);
    $first = $layers[0]; $last = end($layers);
    $stops = '<stop offset="0" stop-color="' . aq_res_color((float)$first['rho']) . '"/>';
    foreach ($layers as $L) $stops .= '<stop offset="' . round(max(0, min(1, ((float)$L['from'] + (float)$L['to']) / 2 / $maxD)), 4) . '" stop-color="' . aq_res_color((float)$L['rho']) . '"/>';
    $stops .= '<stop offset="1" stop-color="' . aq_res_color((float)$last['rho']) . '"/>';
    $s = '<svg class="secsvg" viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Penampang tahanan tanah sampai kedalaman ' . round($maxD, 1) . ' meter">';
    $s .= '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1">' . $stops . '</linearGradient>'
        . '<linearGradient id="' . $id . 'h" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".16"/><stop offset=".5" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".10"/></linearGradient>'
        . '<clipPath id="' . $id . 'c"><rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" rx="14"/></clipPath></defs>';
    // elektroda di permukaan
    $n = max(4, min(24, (int)($o['electrodes'] ?? 12)));
    $s .= '<g stroke="#8FA3A8" stroke-width="2" stroke-linecap="round">';
    for ($i = 0; $i < $n; $i++) { $x = $ml + 14 + $i * ($pw - 28) / ($n - 1); $s .= '<path d="M' . round($x, 1) . ' ' . ($mt - 14) . 'V' . $mt . '"/>'; }
    $s .= '</g><g fill="#145C84">';
    for ($i = 0; $i < $n; $i++) { $x = $ml + 14 + $i * ($pw - 28) / ($n - 1); $s .= '<circle cx="' . round($x, 1) . '" cy="' . ($mt - 16) . '" r="3.6"/>'; }
    $s .= '</g><text x="' . $ml . '" y="12" class="sv-t">Permukaan tanah · elektroda</text>';
    if (!empty($o['length'])) $s .= '<text x="' . ($W - $mr) . '" y="12" text-anchor="end" class="sv-t">Lintasan ±' . round((float)$o['length']) . ' m</text>';
    // tubuh penampang
    $s .= '<g clip-path="url(#' . $id . 'c)"><rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" fill="url(#' . $id . ')"/><rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" fill="url(#' . $id . 'h)"/>';
    foreach ($layers as $i => $L) {
        if ($i > 0) $s .= '<line x1="' . $ml . '" x2="' . ($ml + $pw) . '" y1="' . round($y((float)$L['from']), 1) . '" y2="' . round($y((float)$L['from']), 1) . '" stroke="#fff" stroke-opacity=".55" stroke-dasharray="5 5"/>';
        if (($L['key'] ?? '') === 'aquifer') $s .= '<rect x="' . ($ml + 3) . '" y="' . round($y((float)$L['from']) + 2, 1) . '" width="' . ($pw - 6) . '" height="' . max(4, round($y((float)$L['to']) - $y((float)$L['from']) - 4, 1)) . '" rx="9" fill="none" stroke="#fff" stroke-width="2" stroke-dasharray="2 5" stroke-linecap="round"/>';
    }
    $s .= '</g>';
    // sumbu kedalaman
    $s .= '<g class="sv-ax">';
    for ($d = 0; $d <= $maxD + 0.001; $d += $step) $s .= '<text x="' . ($ml - 7) . '" y="' . round($y($d) + 4, 1) . '" text-anchor="end">' . rtrim(rtrim(number_format($d, 1, '.', ''), '0'), '.') . '</text>';
    $s .= '<text x="' . ($ml - 7) . '" y="' . ($mt - 4) . '" text-anchor="end" class="sv-t">m</text></g>';
    // pil nilai tahanan
    foreach ($layers as $L) {
        $y0 = $y((float)$L['from']); $y1 = $y((float)$L['to']);
        if ($y1 - $y0 < 20) continue;
        $cy = ($y0 + $y1) / 2; $txt = round((float)$L['rho']) . ' Ω·m';
        $s .= '<g><rect x="' . ($ml + $pw - 82) . '" y="' . round($cy - 11, 1) . '" width="72" height="22" rx="11" fill="#0F2A33" fill-opacity=".72"/><text x="' . ($ml + $pw - 46) . '" y="' . round($cy + 4, 1) . '" text-anchor="middle" class="sv-pill">' . e($txt) . '</text></g>';
    }
    // titik bor
    $dmin = $o['drill_min'] ?? ($o['drill'] ?? null); $dmax = $o['drill_max'] ?? ($o['drill'] ?? null);
    if ($dmax !== null && (float)$dmax > 0) {
        $cx = $ml + $pw / 2; $dm = min((float)$dmax, $maxD * 1.0); $yb = $y($dm);
        if ($dmin !== null && (float)$dmin > 0 && (float)$dmin < (float)$dmax) $s .= '<rect x="' . ($cx - 8) . '" y="' . round($y((float)$dmin), 1) . '" width="16" height="' . round(max(2, $y(min((float)$dmax, $maxD)) - $y((float)$dmin)), 1) . '" rx="5" fill="#fff" fill-opacity=".38" stroke="#fff" stroke-width="1.5"/>';
        $s .= '<line x1="' . $cx . '" x2="' . $cx . '" y1="' . ($mt - 2) . '" y2="' . round($yb, 1) . '" stroke="#fff" stroke-width="3" stroke-dasharray="7 5" stroke-linecap="round"/>'
            . '<circle cx="' . $cx . '" cy="' . round($yb, 1) . '" r="7" fill="#fff" stroke="#0F2A33" stroke-width="2.5"/>'
            . '<g><rect x="' . ($cx + 12) . '" y="' . round(max($mt + 2, min($yb - 11, $H - $mb - 24)), 1) . '" width="' . (((float)$dmax >= 100) ? 86 : 78) . '" height="22" rx="11" fill="#fff"/><text x="' . ($cx + 12 + (((float)$dmax >= 100) ? 43 : 39)) . '" y="' . round(max($mt + 2, min($yb - 11, $H - $mb - 24)) + 15, 1) . '" text-anchor="middle" class="sv-bor">Bor ±' . round((float)$dmax) . ' m</text></g>';
    }
    return $s . '</svg>';
}

/** Kalimat penjelas penampang untuk orang awam. */
function aq_section_story(array $layers): string {
    $o = [];
    foreach ($layers as $L) {
        [$nm] = aq_plain_layer($L['key'] ?? ''); $a = round((float)$L['from'], 1); $b = round((float)$L['to'], 1);
        $o[] = ($a <= 0 ? 'Dari permukaan sampai ±' . $b . ' m' : 'Pada ±' . $a . '–' . $b . ' m') . ': <b>' . e(mb_strtolower($nm)) . '</b>' . (($L['key'] ?? '') === 'aquifer' ? ' (target air)' : '');
    }
    return implode('. ', $o) . '.';
}

/** Legenda warna, kalimat penjelas, dan rincian lapisan (di bawah penampang). */
function aq_section_extras(array $layers): string {
    if (!$layers) return '';
    $h = aq_res_legend() . '<p class="story">' . aq_section_story($layers) . '</p><div class="lrows">';
    foreach ($layers as $L) {
        [$nm, $ds] = aq_plain_layer($L['key'] ?? '');
        $h .= '<div class="lrow" style="--c:' . aq_res_color((float)$L['rho']) . '"><i></i><div><b>' . e(fnum($L['from'], 1) . '–' . fnum($L['to'], 1) . ' m · ' . $nm) . '</b><small>' . e($ds) . '</small></div><em>' . round((float)$L['rho']) . ' Ω·m</em></div>';
    }
    return $h . '</div>';
}

/** Penampang + legenda + rincian lapisan (siap tampil). */
function aq_section_html(array $layers, array $o = []): string {
    return '<div class="secwrap">' . aq_section_svg($layers, $o) . '</div>' . aq_section_extras($layers);
}

/** Kurva sounding log-log dengan pita kelas tahanan dan pembacaan sederhana. */
function aq_curve_svg(array $S): string {
    if (count($S) < 2) return '<div class="empty-sm">Kurva muncul setelah minimal 2 jarak elektroda terukur.</div>';
    $W = 480; $H = 270; $ml = 50; $mr = 14; $mt = 14; $mb = 46; $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
    $xs = array_column($S, 's'); $ys = array_column($S, 'rho');
    $lx0 = log10(min($xs)) - 0.1; $lx1 = log10(max($xs)) + 0.1;
    $ly0 = floor(log10(min($ys) / 1.6) * 4) / 4; $ly1 = ceil(log10(max($ys) * 1.6) * 4) / 4;
    if ($ly1 - $ly0 < 1.0) { $ly0 -= (1.0 - ($ly1 - $ly0)) / 2; $ly1 = $ly0 + 1.0; }
    $px = fn($s) => $ml + (log10($s) - $lx0) / ($lx1 - $lx0) * $pw;
    $py = fn($r) => $mt + (1 - (log10($r) - $ly0) / ($ly1 - $ly0)) * $ph;
    $svg = '<svg class="curvesvg" viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Kurva tahanan semu terhadap jarak elektroda">';
    $svg .= '<rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" rx="12" fill="#fff"/>';
    $bands = [[0.01, 10, 'Lempung / asin'], [10, 25, 'Payau'], [25, 150, 'Berpotensi air'], [150, 500, 'Kering'], [500, 1e7, 'Batuan']];
    $svg .= '<defs><clipPath id="cvc"><rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" rx="12"/></clipPath></defs><g clip-path="url(#cvc)">';
    foreach ($bands as [$a, $b, $l]) {
        $ya = max($mt, min($mt + $ph, $py(min($b, pow(10, $ly1))))); $yb = max($mt, min($mt + $ph, $py(max($a, pow(10, $ly0)))));
        if ($yb - $ya < 1) continue;
        $svg .= '<rect x="' . $ml . '" y="' . round($ya, 1) . '" width="' . $pw . '" height="' . round($yb - $ya, 1) . '" fill="' . aq_res_color(sqrt(max($a, 0.5) * min($b, 4000))) . '" fill-opacity=".17"/>';
        if ($yb - $ya >= 15) $svg .= '<text x="' . ($ml + $pw - 8) . '" y="' . round(($ya + $yb) / 2 + 4, 1) . '" text-anchor="end" class="sv-band">' . e($l) . '</text>';
    }
    $svg .= '</g><g stroke="#C9D8D8" stroke-width="1" stroke-dasharray="3 4">';
    for ($e = (int)ceil($ly0); $e <= floor($ly1); $e++) $svg .= '<line x1="' . $ml . '" x2="' . ($ml + $pw) . '" y1="' . round($py(pow(10, $e)), 1) . '" y2="' . round($py(pow(10, $e)), 1) . '"/>';
    $svg .= '</g><g class="sv-ax">';
    for ($e = (int)ceil($ly0); $e <= floor($ly1); $e++) $svg .= '<text x="' . ($ml - 8) . '" y="' . round($py(pow(10, $e)) + 4, 1) . '" text-anchor="end">' . number_format(pow(10, $e), 0, '', '') . '</text>';
    $skip = count($S) > 8 ? 2 : 1;
    foreach ($S as $i => $p) if ($i % $skip === 0 || $i === count($S) - 1) $svg .= '<text x="' . round($px($p['s']), 1) . '" y="' . ($mt + $ph + 18) . '" text-anchor="middle">' . rtrim(rtrim(number_format($p['s'], 1, '.', ''), '0'), '.') . '</text>';
    $svg .= '<text x="' . ($ml + $pw / 2) . '" y="' . ($H - 6) . '" text-anchor="middle" class="sv-t">Jarak elektroda (m) · makin ke kanan makin dalam</text>';
    $svg .= '<text transform="translate(13 ' . ($mt + $ph / 2) . ') rotate(-90)" text-anchor="middle" class="sv-t">Tahanan semu (Ω·m)</text></g>';
    // garis halus (Catmull-Rom)
    $P = array_map(fn($p) => [$px($p['s']), $py($p['rho'])], $S); $d = 'M' . round($P[0][0], 1) . ' ' . round($P[0][1], 1);
    for ($i = 0; $i < count($P) - 1; $i++) {
        $p0 = $P[max(0, $i - 1)]; $p1 = $P[$i]; $p2 = $P[$i + 1]; $p3 = $P[min(count($P) - 1, $i + 2)];
        $d .= ' C' . round($p1[0] + ($p2[0] - $p0[0]) / 6, 1) . ' ' . round($p1[1] + ($p2[1] - $p0[1]) / 6, 1) . ' ' . round($p2[0] - ($p3[0] - $p1[0]) / 6, 1) . ' ' . round($p2[1] - ($p3[1] - $p1[1]) / 6, 1) . ' ' . round($p2[0], 1) . ' ' . round($p2[1], 1);
    }
    $svg .= '<path d="' . $d . '" fill="none" stroke="#0F4660" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>';
    $iMin = array_search(min($ys), $ys); $iMax = array_search(max($ys), $ys); $lab = array_unique([0, count($S) - 1, $iMin, $iMax]);
    foreach ($S as $i => $p) {
        $c = aq_res_color($p['rho']);
        $svg .= '<g><circle cx="' . round($P[$i][0], 1) . '" cy="' . round($P[$i][1], 1) . '" r="7" fill="#fff" stroke="' . $c . '" stroke-width="3"/><circle cx="' . round($P[$i][0], 1) . '" cy="' . round($P[$i][1], 1) . '" r="3" fill="' . $c . '"/><title>Jarak ' . round($p['s'], 1) . ' m: ' . round($p['rho'], 1) . ' Ω·m</title></g>';
        if (in_array($i, $lab, true)) $svg .= '<text x="' . round($P[$i][0], 1) . '" y="' . round($P[$i][1] - 12, 1) . '" text-anchor="middle" class="sv-val">' . round($p['rho']) . '</text>';
    }
    return $svg . '</svg>';
}

/** Data pseudosection 2D: baris pengukuran yang punya posisi x sepanjang lintasan. */
function aq_pseudo_data(int $pid): array {
    if (!aq_has_col('measurements', 'x_m')) return [];
    $rows = q_all('SELECT x_m, spacing_m, rho_a FROM measurements WHERE point_id=? AND x_m IS NOT NULL AND rho_a>0', [$pid]);
    $g = [];
    foreach ($rows as $r) $g[sprintf('%.2f|%.2f', $r['x_m'], $r['spacing_m'])][] = (float)$r['rho_a'];
    $pts = [];
    foreach ($g as $k => $v) { [$x, $a] = array_map('floatval', explode('|', $k)); $pts[] = ['x' => $x, 'a' => $a, 'rho' => aq_median($v)]; }
    $nx = count(array_unique(array_column($pts, 'x'))); $na = count(array_unique(array_column($pts, 'a')));
    return ($nx >= 3 && $na >= 2) ? $pts : [];
}

/** Pseudosection tahanan semu (bukan hasil inversi): interpolasi halus hanya di area yang punya data. */
function aq_pseudosection_svg(array $pts, float $depthFactor = 0.5): string {
    if (!$pts) return '';
    $xs = array_values(array_unique(array_column($pts, 'x'))); sort($xs);
    $as = array_values(array_unique(array_column($pts, 'a'))); sort($as);
    $dx = PHP_FLOAT_MAX; for ($i = 1; $i < count($xs); $i++) $dx = min($dx, $xs[$i] - $xs[$i - 1]); if ($dx === PHP_FLOAT_MAX || $dx <= 0) $dx = 1;
    $da = PHP_FLOAT_MAX; for ($i = 1; $i < count($as); $i++) $da = min($da, $as[$i] - $as[$i - 1]); if ($da === PHP_FLOAT_MAX || $da <= 0) $da = $as[0];
    $sy = max(0.01, $depthFactor * $da);
    $P = array_map(fn($p) => ['x' => $p['x'], 'y' => $depthFactor * $p['a'], 'v' => log10($p['rho']), 'nx' => $p['x'] / $dx, 'ny' => $depthFactor * $p['a'] / $sy, 'rho' => $p['rho'], 'a' => $p['a']], $pts);
    $x0 = min($xs) - $dx / 2; $x1 = max($xs) + $dx / 2; $dmax = $depthFactor * max($as) * 1.12;
    $W = 480; $ml = 44; $mr = 10; $mt = 34; $pw = $W - $ml - $mr; $ph = (int)max(130, min(230, $pw * ($dmax / max(1, ($x1 - $x0))) * 1.6)); $H = $mt + $ph + 24;
    $cols = 84; $rows = 34; $cw = $pw / $cols; $chh = $ph / $rows;
    $draw = []; $hull = [];
    for ($r = 0; $r < $rows; $r++) {
        $cy = ($r + .5) / $rows * $dmax; $line = []; $hr = [];
        for ($c = 0; $c < $cols; $c++) {
            $cx = $x0 + ($c + .5) / $cols * ($x1 - $x0);
            $nxx = $cx / $dx; $nyy = $cy / $sy; $ds = [];
            foreach ($P as $i => $p) $ds[$i] = sqrt(($nxx - $p['nx']) ** 2 + ($nyy - $p['ny']) ** 2);
            asort($ds); $near = reset($ds);
            if ($near > 2.1) { $line[$c] = null; $hr[$c] = false; continue; }
            $sw = 0; $sv = 0; $k = 0;
            foreach ($ds as $i => $d) { if ($k++ >= 5) break; $w = 1 / ($d * $d + 0.06); $sw += $w; $sv += $w * $P[$i]['v']; }
            $line[$c] = (int)round(($sv / $sw) * 14); $hr[$c] = $near <= 1.25;
        }
        $draw[$r] = $line; $hull[$r] = $hr;
    }
    $id = 'ps' . substr(md5(json_encode($pts)), 0, 6);
    $s = '<svg class="pseudosvg" viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Pseudosection tahanan semu sepanjang lintasan"><defs><filter id="' . $id . 'b" x="-3%" y="-3%" width="106%" height="106%"><feGaussianBlur stdDeviation="3.2"/></filter><clipPath id="' . $id . 'c">';
    for ($r = 0; $r < $rows; $r++) { $c = 0; while ($c < $cols) { if (!$hull[$r][$c]) { $c++; continue; } $st = $c; while ($c < $cols && $hull[$r][$c]) $c++; $s .= '<rect x="' . round($ml + $st * $cw, 1) . '" y="' . round($mt + $r * $chh, 1) . '" width="' . round(($c - $st) * $cw + .6, 1) . '" height="' . round($chh + .6, 1) . '"/>'; } }
    $s .= '</clipPath></defs><g clip-path="url(#' . $id . 'c)"><g filter="url(#' . $id . 'b)">';
    for ($r = 0; $r < $rows; $r++) { $c = 0; while ($c < $cols) { $b = $draw[$r][$c]; if ($b === null) { $c++; continue; } $st = $c; while ($c < $cols && $draw[$r][$c] === $b) $c++; $s .= '<rect x="' . round($ml + $st * $cw, 1) . '" y="' . round($mt + $r * $chh, 1) . '" width="' . round(($c - $st) * $cw + .6, 1) . '" height="' . round($chh + .6, 1) . '" fill="' . aq_res_color(pow(10, $b / 14)) . '"/>'; } }
    $s .= '</g></g><g fill="#fff" fill-opacity=".75">';
    foreach ($P as $p) $s .= '<circle cx="' . round($ml + ($p['x'] - $x0) / ($x1 - $x0) * $pw, 1) . '" cy="' . round($mt + $p['y'] / $dmax * $ph, 1) . '" r="1.6"/>';
    $s .= '</g><g class="sv-ax">';
    $stx = aq_nice_step($x1 - $x0, 6); for ($v = ceil($x0 / $stx) * $stx; $v <= $x1 + 1e-6; $v += $stx) $s .= '<text x="' . round($ml + ($v - $x0) / ($x1 - $x0) * $pw, 1) . '" y="' . ($mt - 8) . '" text-anchor="middle">' . rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . '</text>';
    $sty = aq_nice_step($dmax, 4); for ($v = 0; $v <= $dmax + 1e-6; $v += $sty) $s .= '<text x="' . ($ml - 6) . '" y="' . round($mt + $v / $dmax * $ph + 4, 1) . '" text-anchor="end">' . rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . '</text>';
    $s .= '<text x="' . ($ml - 6) . '" y="' . ($mt - 8) . '" text-anchor="end" class="sv-t">m</text><text x="' . ($ml + $pw / 2) . '" y="' . ($H - 6) . '" text-anchor="middle" class="sv-t">Posisi lintasan (m) di atas · kedalaman semu (m) di kiri</text></g></svg>';
    return $s;
}

/* ===== Penampang 2D bergaya RES2DINV ===== */
const AQ_BAND_R = 1.7234; // rasio antar-pita warna (2,97 per dua pita, seperti legenda RES2DINV)
function aq_band_index(float $rho): int { return max(0, min(16, (int)floor(log(max($rho, 1e-6) / 1.35) / log(AQ_BAND_R)))); }
function aq_band_color(int $k): string { return aq_res_color(1.35 * pow(AQ_BAND_R, $k + 0.5)); }
function aq_fmt3(float $v): string { return $v < 100 ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') : (string)round($v); }

/** Bentuk kontur halus dari sel-sel terhubung menggunakan marching squares. */
function aq_mask_contour_path(array $cells, int $rows, int $cols, float $x, float $y, float $cw, float $ch): string {
    if (!$cells || $rows < 1 || $cols < 1) return '';
    $inside = [];
    foreach ($cells as [$r, $c]) $inside[$r . ':' . $c] = true;
    $has = static fn(int $r, int $c): bool => isset($inside[$r . ':' . $c]);
    $segments = []; $adj = []; $coords = [];
    $edgePoint = static fn(string $edge, int $r, int $c): array => match ($edge) {
        't' => [$c + 1, $r + 0.5],
        'r' => [$c + 1.5, $r + 1],
        'b' => [$c + 1, $r + 1.5],
        default => [$c + 0.5, $r + 1],
    };
    $pairs = [
        1 => [['t', 'l']], 2 => [['t', 'r']], 3 => [['l', 'r']],
        4 => [['r', 'b']], 5 => [['t', 'r'], ['b', 'l']], 6 => [['t', 'b']],
        7 => [['l', 'b']], 8 => [['b', 'l']], 9 => [['t', 'b']],
        10 => [['t', 'l'], ['r', 'b']], 11 => [['r', 'b']],
        12 => [['l', 'r']], 13 => [['t', 'r']], 14 => [['t', 'l']],
    ];
    $keyOf = static fn(array $p): string => (string)round($p[0] * 2) . ':' . (string)round($p[1] * 2);
    for ($r = -1; $r < $rows; $r++) for ($c = -1; $c < $cols; $c++) {
        $case = ($has($r, $c) ? 1 : 0) | ($has($r, $c + 1) ? 2 : 0)
            | ($has($r + 1, $c + 1) ? 4 : 0) | ($has($r + 1, $c) ? 8 : 0);
        foreach ($pairs[$case] ?? [] as [$ea, $eb]) {
            $pa = $edgePoint($ea, $r, $c); $pb = $edgePoint($eb, $r, $c);
            $ka = $keyOf($pa); $kb = $keyOf($pb); $index = count($segments);
            $segments[] = [$ka, $kb];
            $adj[$ka][] = $index; $adj[$kb][] = $index;
            $coords[$ka] = $pa; $coords[$kb] = $pb;
        }
    }
    $used = []; $paths = [];
    foreach ($segments as $startIndex => [$start, $next]) {
        if (isset($used[$startIndex])) continue;
        $used[$startIndex] = true; $keys = [$start, $next]; $prev = $startIndex; $current = $next;
        while ($current !== $start) {
            $following = null;
            foreach ($adj[$current] ?? [] as $candidate) {
                if ($candidate !== $prev && !isset($used[$candidate])) { $following = $candidate; break; }
            }
            if ($following === null) break;
            $used[$following] = true;
            [$a, $b] = $segments[$following];
            $current = $a === $current ? $b : $a;
            $keys[] = $current; $prev = $following;
        }
        if ($current !== $start || count($keys) < 5) continue;
        array_pop($keys);
        $points = array_map(static fn(string $key): array => [
            $x + $coords[$key][0] * $cw,
            $y + $coords[$key][1] * $ch,
        ], $keys);
        for ($pass = 0; $pass < 2; $pass++) {
            $smooth = []; $n = count($points);
            for ($i = 0; $i < $n; $i++) {
                $prev = $points[($i - 1 + $n) % $n]; $cur = $points[$i]; $next = $points[($i + 1) % $n];
                $smooth[] = [
                    ($prev[0] + 2 * $cur[0] + $next[0]) / 4,
                    ($prev[1] + 2 * $cur[1] + $next[1]) / 4,
                ];
            }
            $points = $smooth;
        }
        $last = $points[count($points) - 1];
        $first = $points[0];
        $d = 'M' . round(($last[0] + $first[0]) / 2, 2) . ' ' . round(($last[1] + $first[1]) / 2, 2);
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            $a = $points[$i]; $b = $points[($i + 1) % $n];
            $d .= 'Q' . round($a[0], 2) . ' ' . round($a[1], 2) . ' '
                . round(($a[0] + $b[0]) / 2, 2) . ' ' . round(($a[1] + $b[1]) / 2, 2);
        }
        $paths[] = $d . 'Z';
    }
    return implode('', $paths);
}

/**
 * Penampang resistivitas 2D dari data ukur ber-posisi (interpolasi, bukan inversi).
 * $pts: [['x','a','rho'],...]. $o: depth_factor, electrodes, length, drill_max
 */
function aq_section2d_svg(array $pts, array $o = []): string {
    if (!$pts) return '';
    $df = (float)($o['depth_factor'] ?? 0.5);
    $xs = array_values(array_unique(array_column($pts, 'x'))); sort($xs);
    $as = array_values(array_unique(array_column($pts, 'a'))); sort($as);
    $dx = PHP_FLOAT_MAX; for ($i = 1; $i < count($xs); $i++) $dx = min($dx, $xs[$i] - $xs[$i - 1]); if ($dx === PHP_FLOAT_MAX || $dx <= 0) $dx = 1;
    $da = PHP_FLOAT_MAX; for ($i = 1; $i < count($as); $i++) $da = min($da, $as[$i] - $as[$i - 1]); if ($da === PHP_FLOAT_MAX || $da <= 0) $da = $as[0];
    $sy = max(0.01, $df * $da);
    $P = array_map(fn($p) => ['x' => $p['x'], 'y' => $df * $p['a'], 'v' => log10($p['rho']), 'nx' => $p['x'] / $dx, 'ny' => $df * $p['a'] / $sy], $pts);
    $L = (float)($o['length'] ?? 0); $minx = min($xs); $maxx = max($xs);
    if ($L > 0 && $L >= $maxx) { $x0 = 0.0; $x1 = $L; } else { $x0 = $minx - $dx / 2; $x1 = $maxx + $dx / 2; }
    $dmax = $df * max($as) * 1.12;
    $W = 600; $ml = 46; $mr = 12; $mt = 46; $pw = $W - $ml - $mr;
    $ph = (int)max(170, min(300, $pw * ($dmax / max(1, $x1 - $x0)) * 3.2)); $H = $mt + $ph + 74;
    $cols = 300; $rows = 112; $cw = $pw / $cols; $chh = $ph / $rows;
    $colorCount = 512; $colorMin = 1.35; $colorMax = 2726;
    $colorLogMin = log10($colorMin); $colorLogRange = log10($colorMax) - $colorLogMin;
    $colors = [];
    for ($i = 0; $i < $colorCount; $i++) {
        $colors[$i] = aq_res_color(pow(10, $colorLogMin + $colorLogRange * $i / ($colorCount - 1)));
    }
    $val = []; $hull = []; $in = [];
    for ($r = 0; $r < $rows; $r++) {
        $cy = ($r + .5) / $rows * $dmax;
        for ($c = 0; $c < $cols; $c++) {
            $cx = $x0 + ($c + .5) / $cols * ($x1 - $x0); $nxx = $cx / $dx; $nyy = $cy / $sy; $ds = [];
            foreach ($P as $i => $p) $ds[$i] = ($nxx - $p['nx']) ** 2 + ($nyy - $p['ny']) ** 2;
            asort($ds); $near = sqrt(reset($ds));
            if ($near > 2.2) { $val[$r][$c] = null; $hull[$r][$c] = false; $in[$r][$c] = false; continue; }
            $sw = 0; $sv = 0; $k = 0;
            foreach ($ds as $i => $d2) { if ($k++ >= 6) break; $w = 1 / ($d2 + 0.06); $sw += $w; $sv += $w * $P[$i]['v']; }
            $rho = pow(10, $sv / $sw);
            $colorIndex = (int)round((log10(max($colorMin, min($colorMax, $rho))) - $colorLogMin) / $colorLogRange * ($colorCount - 1));
            $val[$r][$c] = max(0, min($colorCount - 1, $colorIndex));
            $hull[$r][$c] = $near <= 1.3; $in[$r][$c] = $near <= 1.3 && $rho >= 25 && $rho <= 150;
        }
    }
    // haluskan topeng zona (mayoritas 3x3 dua kali) agar tepinya rapi
    for ($pass = 0; $pass < 2; $pass++) {
        $nm = [];
        for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < $cols; $c++) {
            if (!$hull[$r][$c]) { $nm[$r][$c] = false; continue; }
            $cnt = 0; for ($a = -1; $a <= 1; $a++) for ($b = -1; $b <= 1; $b++) { $rr = $r + $a; $cc = $c + $b; if ($rr >= 0 && $cc >= 0 && $rr < $rows && $cc < $cols && $in[$rr][$cc]) $cnt++; }
            $nm[$r][$c] = $cnt >= 5;
        }
        $in = $nm;
    }
    // komponen terhubung terbesar dari zona 25-150 Ω·m (berpotensi air)
    $seen = []; $best = []; 
    for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < $cols; $c++) {
        if (!$in[$r][$c] || isset($seen[$r * 1000 + $c])) continue;
        $q = [[$r, $c]]; $seen[$r * 1000 + $c] = 1; $comp = [];
        while ($q) { [$rr, $cc] = array_pop($q); $comp[] = [$rr, $cc];
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$a, $b]) { $nr = $rr + $a; $nc = $cc + $b; if ($nr < 0 || $nc < 0 || $nr >= $rows || $nc >= $cols || !$in[$nr][$nc] || isset($seen[$nr * 1000 + $nc])) continue; $seen[$nr * 1000 + $nc] = 1; $q[] = [$nr, $nc]; } }
        if (count($comp) > count($best)) $best = $comp;
    }
    $zone = count($best) >= 24 ? $best : [];
    if ($zone) { // isi lubang di dalam zona
        $cm = []; foreach ($zone as [$r, $c]) $cm[$r * 1000 + $c] = 1; $out = []; $q = [];
        for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < $cols; $c++) if (($r === 0 || $c === 0 || $r === $rows - 1 || $c === $cols - 1) && !isset($cm[$r * 1000 + $c])) { $out[$r * 1000 + $c] = 1; $q[] = [$r, $c]; }
        while ($q) { [$rr, $cc] = array_pop($q); foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$a, $b]) { $nr = $rr + $a; $nc = $cc + $b; if ($nr < 0 || $nc < 0 || $nr >= $rows || $nc >= $cols || isset($cm[$nr * 1000 + $nc]) || isset($out[$nr * 1000 + $nc])) continue; $out[$nr * 1000 + $nc] = 1; $q[] = [$nr, $nc]; } }
        $zone = []; for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < $cols; $c++) if (!isset($out[$r * 1000 + $c]) && ($hull[$r][$c] || isset($cm[$r * 1000 + $c]))) $zone[] = [$r, $c];
    }
    $id = 's2' . substr(md5(json_encode($pts)), 0, 6);
    $s = '<svg class="sec2d" viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Penampang resistivitas 2D sepanjang lintasan, hasil interpolasi data ukur"><defs><filter id="' . $id . 'b" x="-3%" y="-3%" width="106%" height="106%"><feGaussianBlur stdDeviation="2.2"/></filter><filter id="' . $id . 'm" x="-5%" y="-5%" width="110%" height="110%"><feGaussianBlur stdDeviation="1.15"/></filter><mask id="' . $id . 'c" x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" maskUnits="userSpaceOnUse"><g filter="url(#' . $id . 'm)">';
    for ($r = 0; $r < $rows; $r++) { $c = 0; while ($c < $cols) { if (!$hull[$r][$c]) { $c++; continue; } $st = $c; while ($c < $cols && $hull[$r][$c]) $c++; $s .= '<rect x="' . round($ml + $st * $cw, 2) . '" y="' . round($mt + $r * $chh, 2) . '" width="' . round(($c - $st) * $cw + .6, 2) . '" height="' . round($chh + .6, 2) . '" fill="#fff"/>'; } }
    $s .= '</g></mask></defs><rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" rx="10" fill="#EEF4F4"/><g mask="url(#' . $id . 'c)"><g filter="url(#' . $id . 'b)">';
    for ($r = 0; $r < $rows; $r++) { $c = 0; while ($c < $cols) { $b = $val[$r][$c]; if ($b === null) { $c++; continue; } $st = $c; while ($c < $cols && $val[$r][$c] === $b) $c++; $s .= '<rect x="' . round($ml + $st * $cw, 1) . '" y="' . round($mt + $r * $chh, 1) . '" width="' . round(($c - $st) * $cw + .6, 1) . '" height="' . round($chh + .6, 1) . '" fill="' . $colors[$b] . '"/>'; } }
    $s .= '</g></g>';
    // garis tepi zona berpotensi air
    if ($zone) {
        $d = aq_mask_contour_path($zone, $rows, $cols, $ml, $mt, $cw, $chh);
        if ($d !== '') $s .= '<path d="' . $d . '" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" opacity=".92"/>';
    }
    // sumbu
    $s .= '<g class="sv-ax">'; $stx = aq_nice_step($x1 - $x0, 8);
    for ($v = ceil($x0 / $stx) * $stx; $v <= $x1 + 1e-6; $v += $stx) { $px = $ml + ($v - $x0) / ($x1 - $x0) * $pw; $s .= '<text x="' . round($px, 1) . '" y="15" text-anchor="middle">' . aq_fmt3($v) . '</text><line x1="' . round($px, 1) . '" x2="' . round($px, 1) . '" y1="21" y2="' . ($mt - 8) . '" stroke="#B4C6C6" stroke-width="1"/>'; }
    $sty = aq_nice_step($dmax, 5); for ($v = 0; $v <= $dmax + 1e-6; $v += $sty) $s .= '<text x="' . ($ml - 7) . '" y="' . round($mt + $v / $dmax * $ph + 4, 1) . '" text-anchor="end">' . aq_fmt3($v) . '</text>';
    $s .= '<text x="' . ($ml - 7) . '" y="15" text-anchor="end" class="sv-t">m</text></g>';
    $ne = (int)($o['electrodes'] ?? 0); $es = (float)($o['spacing'] ?? 0);
    if ($ne >= 4 && $ne <= 60 && $es > 0) { $s .= '<g fill="#145C84">'; for ($i = 0; $i < $ne; $i++) { $v = $i * $es; if ($v < $x0 - 1e-6 || $v > $x1 + 1e-6) continue; $px = $ml + ($v - $x0) / ($x1 - $x0) * $pw; $s .= '<path d="M' . round($px - 3.4, 1) . ' ' . ($mt - 9) . 'h6.8l-3.4 7z"/>'; } $s .= '</g>'; }
    // titik bor di pusat zona berpotensi air
    if ($zone) {
        $sx = 0; $sr = 0; foreach ($zone as [$r, $c]) { $sx += $c; $sr += $r; } $cc = (int)round($sx / count($zone)); $cr = $sr / count($zone);
        $colRows = array_values(array_filter(array_map(fn($z) => $z[1] === $cc ? $z[0] : null, $zone), fn($v) => $v !== null)); if (!$colRows) { $colRows = [(int)$cr]; }
        $r0 = min($colRows); $r1 = max($colRows) + 1; $bx = $ml + ($cc + .5) * $cw;
        $dd = isset($o['drill_max']) && (float)$o['drill_max'] > 0 ? min((float)$o['drill_max'], $dmax) : $r1 / $rows * $dmax; $by = $mt + $dd / $dmax * $ph;
        $px0 = $ml + $cc * $cw;
        $s .= '<line x1="' . round($bx, 1) . '" x2="' . round($bx, 1) . '" y1="' . ($mt - 2) . '" y2="' . round($by, 1) . '" stroke="#fff" stroke-width="3" stroke-dasharray="7 5" stroke-linecap="round"/><circle cx="' . round($bx, 1) . '" cy="' . round($by, 1) . '" r="7" fill="#fff" stroke="#0F2A33" stroke-width="2.5"/>';
        $lx = min($bx + 12, $ml + $pw - 92); $ly = max($mt + 4, min($by - 11, $mt + $ph - 26));
        $s .= '<g><rect x="' . round($lx, 1) . '" y="' . round($ly, 1) . '" width="86" height="22" rx="11" fill="#fff"/><text x="' . round($lx + 43, 1) . '" y="' . round($ly + 15, 1) . '" text-anchor="middle" class="sv-bor">Bor ±' . round($dd) . ' m</text></g>';
        $zy = $mt + ($r0 / $rows * $dmax) / $dmax * $ph; $zx = $ml + (min(array_column($zone, 1))) * $cw;
        $s .= '<g><rect x="' . round(max($ml + 4, $zx + 6), 1) . '" y="' . round($zy + 6, 1) . '" width="156" height="22" rx="11" fill="#0F2A33" fill-opacity=".78"/><text x="' . round(max($ml + 4, $zx + 6) + 78, 1) . '" y="' . round($zy + 21, 1) . '" text-anchor="middle" class="sv-pill">Zona berpotensi air</text></g>';
    }
    // legenda pita warna
    $ly = $mt + $ph + 16; $sw = $pw / 17;
    for ($k = 0; $k < 17; $k++) $s .= '<rect x="' . round($ml + $k * $sw, 1) . '" y="' . $ly . '" width="' . round($sw + .4, 1) . '" height="14" fill="' . aq_band_color($k) . '"' . ($k === 0 ? ' rx="4"' : '') . '/>';
    $s .= '<g class="sv-ax">';
    for ($k = 0; $k < 17; $k += 2) $s .= '<text x="' . round($ml + $k * $sw + $sw / 2, 1) . '" y="' . ($ly + 31) . '" text-anchor="middle">' . aq_fmt3(1.35 * pow(AQ_BAND_R, $k)) . '</text>';
    return $s . '<text x="' . ($ml + $pw / 2) . '" y="' . ($H - 6) . '" text-anchor="middle" class="sv-t">Tahanan (Ω·m) · biru basah/lempung · hijau berpotensi air · kuning-merah kering/batuan</text></g></svg>';
}

/** Semua titik ukur yang direncanakan untuk susunan Wenner: [x, a]. */
function aq_plan_cells(array $plan): array {
    $n = (int)$plan['electrodes']; $s = (float)$plan['spacing']; $cells = [];
    if ($n < 4 || $s <= 0) return [];
    for ($k = 1; 3 * $k <= $n - 1; $k++) { $a = $k * $s; for ($i = 0; $i + 3 * $k <= $n - 1; $i++) $cells[] = [round(1.5 * $a + $i * $s, 2), round($a, 2)]; }
    return $cells;
}
function aq_measured_cells(int $pid): array {
    if (!aq_has_col('measurements', 'x_m')) return [];
    $set = [];
    foreach (q_all('SELECT DISTINCT ROUND(x_m,1) x, ROUND(spacing_m,1) a FROM measurements WHERE point_id=? AND x_m IS NOT NULL', [$pid]) as $r) $set[sprintf('%.1f|%.1f', $r['x'], $r['a'])] = 1;
    return $set;
}
function aq_next_cell(array $plan, array $set): ?array {
    foreach (aq_plan_cells($plan) as [$x, $a]) if (!isset($set[sprintf('%.1f|%.1f', $x, $a)])) return ['x' => $x, 'a' => $a];
    return null;
}
/** Peta kemajuan pengukuran lintasan 2D: titik terisi = sudah diukur. */
function aq_grid_plan_svg(array $plan, array $set, float $df = 0.5): string {
    $cells = aq_plan_cells($plan);
    if (!$cells) return '';
    $L = ((int)$plan['electrodes'] - 1) * (float)$plan['spacing']; $maxA = max(array_column($cells, 1)); $dmax = $df * $maxA * 1.1;
    $W = 600; $ml = 46; $mr = 12; $mt = 30; $pw = $W - $ml - $mr; $ph = (int)max(110, min(210, $pw * $dmax / max(1, $L) * 2.2)); $H = $mt + $ph + 12;
    $done = 0; $r = max(2.2, min(6.5, $pw / max(1, $L / (float)$plan['spacing']) * 0.36));
    $s = '<svg class="gridsvg" viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Peta titik pengukuran lintasan 2D"><rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" rx="10" fill="#F1F6F6"/>';
    $m = ''; $u = '';
    foreach ($cells as [$x, $a]) {
        $px = round($ml + $x / $L * $pw, 1); $py = round($mt + ($df * $a) / $dmax * $ph, 1);
        if (isset($set[sprintf('%.1f|%.1f', $x, $a)])) { $done++; $m .= '<circle cx="' . $px . '" cy="' . $py . '" r="' . round($r, 1) . '"/>'; }
        else $u .= '<circle cx="' . $px . '" cy="' . $py . '" r="' . round($r * 0.8, 1) . '"/>';
    }
    $s .= '<g fill="#fff" stroke="#B4C6C6" stroke-width="1.2">' . $u . '</g><g fill="#2CB4A4" stroke="#fff" stroke-width="1.5">' . $m . '</g><g class="sv-ax">';
    $stx = aq_nice_step($L, 8); for ($v = 0; $v <= $L + 1e-6; $v += $stx) $s .= '<text x="' . round($ml + $v / $L * $pw, 1) . '" y="15" text-anchor="middle">' . aq_fmt3($v) . '</text>';
    $sty = aq_nice_step($dmax, 4); for ($v = 0; $v <= $dmax + 1e-6; $v += $sty) $s .= '<text x="' . ($ml - 7) . '" y="' . round($mt + $v / $dmax * $ph + 4, 1) . '" text-anchor="end">' . aq_fmt3($v) . '</text>';
    return $s . '<text x="' . ($ml - 7) . '" y="' . ($mt - 10) . '" text-anchor="end" class="sv-t">m</text></g></svg>' . '<!--done:' . $done . '/' . count($cells) . '-->';
}
