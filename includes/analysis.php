<?php
/**
 * Mesin analisis resistivitas (fungsi murni, tanpa database).
 * Hasilnya INDIKATIF: bukan pengganti inversi (IPI2Win/pyGIMLi) maupun uji sumur.
 */

function aq_median(array $a): float {
    sort($a);
    $n = count($a);
    if (!$n) return NAN;
    $m = intdiv($n, 2);
    return $n % 2 ? (float)$a[$m] : ($a[$m - 1] + $a[$m]) / 2;
}

/** Faktor geometri k. Wenner: s = a. Schlumberger: s = AB/2, mn = MN (panjang penuh). */
function geom_k(string $config, float $s, ?float $mn): ?float {
    if ($s <= 0) return null;
    if ($config === 'schlumberger') {
        if ($mn === null || $mn <= 0 || $mn / 2 >= $s) return null;
        return M_PI * (($s * $s) - ($mn / 2) ** 2) / $mn;
    }
    return 2 * M_PI * $s;
}

function rho_class(float $r): array {
    if ($r < 10)  return ['key' => 'saline',   'label' => 'Lempung / air asin',     'color' => '#A8432F'];
    if ($r < 25)  return ['key' => 'brackish', 'label' => 'Air payau / lempung basah', 'color' => '#C98A2B'];
    if ($r < 150) return ['key' => 'aquifer',  'label' => 'Akuifer potensial',      'color' => '#0E7C86'];
    if ($r < 500) return ['key' => 'dry',      'label' => 'Kering / padat',         'color' => '#8C9079'];
    return            ['key' => 'rock',        'label' => 'Batuan keras',           'color' => '#4B4F57'];
}

/** Kelompokkan baris ukur per jarak, ambil median. */
function build_sounding(array $rows, float $depthFactor = 0.5): array {
    $g = [];
    foreach ($rows as $r) {
        $rho = (float)$r['rho_a'];
        if (!is_finite($rho) || $rho <= 0) continue;
        $g[sprintf('%.2f', (float)$r['spacing_m'])][] = $rho;
    }
    ksort($g, SORT_NUMERIC);
    $out = [];
    foreach ($g as $s => $v) {
        $med = aq_median($v);
        $out[] = [
            's' => (float)$s,
            'rho' => $med,
            'n' => count($v),
            'spread' => $med > 0 ? (max($v) - min($v)) / $med : 0.0,
            'depth' => $depthFactor * (float)$s,
        ];
    }
    return $out;
}

/** Gabungkan titik bersebelahan yang kelasnya sama menjadi lapisan. */
function aq_layers(array $S): array {
    $layers = [];
    $n = count($S);
    for ($i = 0; $i < $n; $i++) {
        $c = rho_class($S[$i]['rho']);
        $from = $i === 0 ? 0.0 : ($S[$i - 1]['depth'] + $S[$i]['depth']) / 2;
        $to = $i === $n - 1 ? $S[$i]['depth'] * 1.15 : ($S[$i]['depth'] + $S[$i + 1]['depth']) / 2;
        $last = count($layers) - 1;
        if ($last >= 0 && $layers[$last]['key'] === $c['key']) {
            $layers[$last]['to'] = $to;
            $layers[$last]['rhos'][] = $S[$i]['rho'];
            $layers[$last]['np']++;
        } else {
            $layers[] = ['from' => $from, 'to' => $to, 'key' => $c['key'], 'label' => $c['label'],
                         'color' => $c['color'], 'rhos' => [$S[$i]['rho']], 'np' => 1];
        }
    }
    foreach ($layers as &$L) { $L['rho'] = aq_median($L['rhos']); unset($L['rhos']); }
    unset($L);
    return $layers;
}

/** Penilaian kualitas air (acuan umum WHO untuk rasa/TDS; pH 6,5–8,5 acuan umum air bersih). */
function water_assess(array $w): array {
    $tds = isset($w['tds_ppm']) && $w['tds_ppm'] !== null ? (float)$w['tds_ppm'] : null;
    $ec  = isset($w['ec_us']) && $w['ec_us'] !== null ? (float)$w['ec_us'] : null;
    $ph  = isset($w['ph']) && $w['ph'] !== null ? (float)$w['ph'] : null;
    if ($ec === null && $tds !== null) $ec = $tds / 0.64;      // perkiraan kasar
    $notes = [];
    $tdsClass = null;
    if ($tds !== null) {
        if ($tds < 300)       $tdsClass = 'sangat baik';
        elseif ($tds < 600)   $tdsClass = 'baik';
        elseif ($tds < 900)   $tdsClass = 'cukup';
        elseif ($tds < 1200)  $tdsClass = 'buruk';
        else                  $tdsClass = 'tidak layak minum (payau/asin)';
        $notes[] = 'TDS ' . round($tds) . ' ppm: ' . $tdsClass . '.';
    }
    if ($ph !== null) {
        if ($ph < 6.5 || $ph > 8.5) $notes[] = 'pH ' . round($ph, 1) . ' di luar kisaran 6,5–8,5.';
        else $notes[] = 'pH ' . round($ph, 1) . ' dalam kisaran wajar.';
    }
    $rhoW = ($ec !== null && $ec > 0) ? 10000 / $ec : null;   // Ω·m
    return [
        'tds' => $tds, 'ec' => $ec, 'ph' => $ph,
        'tds_class' => $tdsClass,
        'rho_w' => $rhoW,
        'expected' => $rhoW !== null ? [3 * $rhoW, 9 * $rhoW] : null, // faktor formasi ~3–9 (kasar)
        'saline' => $tds !== null && $tds >= 1200,
        'notes' => $notes,
    ];
}

function analyze_sounding(array $S, ?array $water = null): array {
    $n = count($S);
    $res = [
        'n' => $n, 'layers' => [], 'minima' => [], 'best' => null, 'score' => 0,
        'verdict' => 'Data kurang', 'drill_depth' => null, 'warnings' => [], 'notes' => [],
        'max_depth' => $n ? $S[$n - 1]['depth'] : 0,
    ];
    if ($n < 3) {
        $res['warnings'][] = 'Butuh minimal 3 jarak elektroda berbeda untuk membaca kurva sounding (saat ini ' . $n . ').';
        return $res;
    }
    $res['layers'] = aq_layers($S);

    // Minimum lokal: titik yang jauh lebih konduktif dari tetangganya.
    for ($i = 1; $i < $n - 1; $i++) {
        if ($S[$i]['rho'] < 0.85 * min($S[$i - 1]['rho'], $S[$i + 1]['rho'])) $res['minima'][] = $i;
    }
    $deepDrop = $S[$n - 1]['rho'] < 0.8 * $S[$n - 2]['rho'];
    if ($deepDrop) $res['notes'][] = 'Resistivitas masih turun di jarak terbesar: ada lapisan konduktif lebih dalam yang belum tertutup kurva. Tambah jarak elektroda.';

    // Kandidat akuifer.
    $cand = [];
    foreach ($S as $i => $p) if (rho_class($p['rho'])['key'] === 'aquifer') $cand[] = $i;
    $brackishOnly = false;
    if (!$cand) {
        foreach ($S as $i => $p) if (rho_class($p['rho'])['key'] === 'brackish') $cand[] = $i;
        $brackishOnly = (bool)$cand;
    }
    $bestIdx = null;
    if ($cand) {
        usort($cand, function ($a, $b) use ($S, $res) {
            $ma = in_array($a, $res['minima'], true) ? 0 : 1;
            $mb = in_array($b, $res['minima'], true) ? 0 : 1;
            return $ma <=> $mb ?: $S[$a]['rho'] <=> $S[$b]['rho'];
        });
        $bestIdx = $cand[0];
    }

    $score = 0;
    $spreadMed = aq_median(array_column($S, 'spread'));
    if ($bestIdx !== null) {
        $b = $S[$bestIdx];
        $c = rho_class($b['rho']);
        $res['best'] = ['depth' => $b['depth'], 'rho' => $b['rho'], 's' => $b['s'], 'class' => $c['label'], 'key' => $c['key']];
        $score = $brackishOnly ? 25 : 50;
        if (in_array($bestIdx, $res['minima'], true)) $score += 15;
        foreach ($res['layers'] as $L) {
            if ($L['key'] === $c['key'] && $L['np'] >= 2 && $b['depth'] >= $L['from'] && $b['depth'] <= $L['to']) { $score += 10; break; }
        }
        if ($bestIdx > 0 && $S[0]['rho'] > 1.5 * $b['rho']) $score += 5;
        $res['drill_depth'] = max(5, ceil(($b['depth'] * 1.3) / 5) * 5);
        if ($brackishOnly) $res['warnings'][] = 'Tidak ada lapisan 25–150 Ω·m; lapisan konduktif yang ada kemungkinan air payau atau lempung basah.';
        if ($b['rho'] < 10) $res['warnings'][] = 'Resistivitas sangat rendah (<10 Ω·m): bisa lempung jenuh atau air asin, bukan air tawar yang mudah dipompa.';
    } else {
        $score = 10;
        $res['notes'][] = 'Seluruh kurva berada di atas 150 Ω·m (kering/padat/batuan) atau di bawah 10 Ω·m (lempung/asin). Tidak ada indikasi akuifer air tawar pada kedalaman terjangkau.';
    }

    if ($n >= 6) $score += 10; elseif ($n >= 4) $score += 5;
    if ($spreadMed < 0.25) $score += 10; elseif ($spreadMed > 0.6) { $score -= 10; $res['warnings'][] = 'Pengulangan ukur di jarak yang sama beda jauh (median sebaran ' . round($spreadMed * 100) . '%). Periksa kontak elektroda.'; }
    if ($n >= 2) {
        // lonjakan antar-titik >5x biasanya noise/kontak buruk
        for ($i = 1; $i < $n; $i++) {
            $r = $S[$i]['rho'] / $S[$i - 1]['rho'];
            if ($r > 5 || $r < 0.2) { $res['warnings'][] = 'Lonjakan >5× antara jarak ' . $S[$i - 1]['s'] . ' m dan ' . $S[$i]['s'] . ' m. Cek ulang titik tersebut.'; break; }
        }
    }

    if ($water) {
        foreach ($water['notes'] as $nt) $res['notes'][] = 'Air: ' . $nt;
        if ($water['saline']) { $score -= 15; $res['warnings'][] = 'Sampel air ber-TDS sangat tinggi: air kemungkinan payau/asin walau resistivitas tampak "akuifer".'; }
        if ($water['expected'] && $res['best']) {
            [$lo, $hi] = $water['expected'];
            $r = $res['best']['rho'];
            if ($r >= $lo * 0.7 && $r <= $hi * 1.3) { $score += 5; $res['notes'][] = 'Resistivitas lapisan target (' . round($r) . ' Ω·m) sesuai perkiraan dari daya hantar air (' . round($lo) . '–' . round($hi) . ' Ω·m).'; }
            elseif ($r < $lo * 0.7) $res['notes'][] = 'Lapisan target lebih konduktif dari perkiraan air sampel: curigai lempung atau air lebih asin di kedalaman.';
            else $res['notes'][] = 'Lapisan target lebih resistif dari perkiraan air sampel: formasi mungkin kurang jenuh atau berpasir kasar/kerikil.';
        }
    }

    // Lapisan terdalam sangat konduktif di bawah target: risiko air asin/lempung di bawah akuifer.
    $deepSaline = $bestIdx !== null && !$brackishOnly && $S[$n - 1]['rho'] < 10 && $res['best']['rho'] >= 10;
    if ($deepSaline) {
        $score -= 20;
        $res['warnings'][] = 'Lapisan terdalam sangat konduktif (<10 Ω·m): ada risiko lempung atau air asin di bawah target. Jangan bor terlalu dalam.';
    }
    $score = max(0, min(100, (int)round($score)));
    $res['score'] = $score;
    if ($bestIdx === null) $verdict = 'Rendah';
    elseif ($brackishOnly || $deepSaline || ($res['best']['rho'] < 10)) $verdict = 'Berisiko asin/lempung';
    elseif ($score >= 70) $verdict = 'Tinggi';
    elseif ($score >= 45) $verdict = 'Sedang';
    else $verdict = 'Rendah';
    $res['verdict'] = $verdict;
    $res['notes'][] = 'Kedalaman diperkirakan dari jarak elektroda × ' . round($S[0]['depth'] / $S[0]['s'], 2) . ' (aturan praktis). Untuk keputusan pengeboran, validasi dengan inversi dan sumur sekitar.';
    return $res;
}

/** Kurva sounding log-log sebagai SVG (tanpa JS). */
function svg_sounding(array $S): string {
    if (!$S) return '';
    $W = 640; $H = 380; $ml = 60; $mr = 16; $mt = 16; $mb = 46;
    $xs = array_column($S, 's'); $ys = array_column($S, 'rho');
    $x0 = floor(log10(min($xs))); $x1 = ceil(log10(max($xs))); if ($x1 <= $x0) $x1 = $x0 + 1;
    $y0 = floor(log10(min($ys))); $y1 = ceil(log10(max($ys))); if ($y1 <= $y0) $y1 = $y0 + 1;
    $px = fn($v) => $ml + (log10($v) - $x0) / ($x1 - $x0) * ($W - $ml - $mr);
    $py = fn($v) => $mt + ($y1 - log10($v)) / ($y1 - $y0) * ($H - $mt - $mb);
    $o = '<svg class="sv" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Kurva sounding resistivitas semu terhadap jarak elektroda (skala log)">';
    $bands = [[0, 10, '#A8432F'], [10, 25, '#C98A2B'], [25, 150, '#0E7C86'], [150, 500, '#8C9079'], [500, INF, '#4B4F57']];
    foreach ($bands as [$lo, $hi, $col]) {
        $lo = max($lo, 10 ** $y0); $hi = min($hi, 10 ** $y1);
        if ($lo >= $hi) continue;
        $o .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" opacity="0.14"/>', $ml, $py($hi), $W - $ml - $mr, $py($lo) - $py($hi), $col);
    }
    for ($e = $x0; $e <= $x1; $e++) {
        $x = $px(10 ** $e);
        $o .= sprintf('<line class="sv-grid" x1="%.1f" y1="%d" x2="%.1f" y2="%d"/>', $x, $mt, $x, $H - $mb);
        $o .= sprintf('<text class="sv-txt" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x, $H - $mb + 16, e(rtrim(rtrim(number_format(10 ** $e, 2, '.', ''), '0'), '.')));
    }
    for ($e = $y0; $e <= $y1; $e++) {
        $y = $py(10 ** $e);
        $o .= sprintf('<line class="sv-grid" x1="%d" y1="%.1f" x2="%d" y2="%.1f"/>', $ml, $y, $W - $mr, $y);
        $o .= sprintf('<text class="sv-txt" x="%d" y="%.1f" text-anchor="end">%s</text>', $ml - 6, $y + 4, e(number_format(10 ** $e, 0, '.', '')));
    }
    $o .= sprintf('<text class="sv-txt" x="%.1f" y="%d" text-anchor="middle">Jarak elektroda (m)</text>', ($ml + $W - $mr) / 2, $H - 6);
    $o .= sprintf('<text class="sv-txt" transform="translate(14 %.1f) rotate(-90)" text-anchor="middle">ρa (Ω·m)</text>', ($mt + $H - $mb) / 2);
    $pts = [];
    foreach ($S as $p) $pts[] = sprintf('%.1f,%.1f', $px($p['s']), $py($p['rho']));
    $o .= '<polyline class="sv-line" points="' . implode(' ', $pts) . '"/>';
    foreach ($S as $p) {
        $o .= sprintf('<circle class="sv-dot" cx="%.1f" cy="%.1f" r="4.5" fill="%s"><title>%s m: %s Ω·m (n=%d)</title></circle>',
            $px($p['s']), $py($p['rho']), rho_class($p['rho'])['color'], e($p['s']), e(round($p['rho'], 1)), $p['n']);
    }
    return $o . '</svg>';
}

/** Kolom log bor: lapisan per kedalaman dengan warna kelas resistivitas. */
function svg_log(array $layers): string {
    if (!$layers) return '';
    $W = 170; $H = 380; $mt = 8; $mb = 8; $x = 44; $w = 70;
    $maxD = max(array_column($layers, 'to'));
    if ($maxD <= 0) return '';
    $y = fn($d) => $mt + $d / $maxD * ($H - $mt - $mb);
    $o = '<svg class="sv sv-log" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Kolom lapisan bawah permukaan menurut kedalaman">';
    foreach ($layers as $L) {
        $y1 = $y($L['from']); $y2 = $y($L['to']);
        $o .= sprintf('<rect x="%d" y="%.1f" width="%d" height="%.1f" fill="%s"><title>%s, %s–%s m, ±%s Ω·m</title></rect>',
            $x, $y1, $w, max(1, $y2 - $y1), $L['color'], e($L['label']), e(round($L['from'], 1)), e(round($L['to'], 1)), e(round($L['rho'])));
        $o .= sprintf('<text class="sv-txt" x="%d" y="%.1f" text-anchor="end">%s</text>', $x - 5, $y1 + 4, e(round($L['from'], 1)));
        if ($y2 - $y1 >= 16) $o .= sprintf('<text class="sv-lay" x="%d" y="%.1f">%s</text>', $x + $w + 6, ($y1 + $y2) / 2 + 4, e(round($L['rho'])) . ' Ω·m');
    }
    $o .= sprintf('<text class="sv-txt" x="%d" y="%.1f" text-anchor="end">%s m</text>', $x - 5, $H - $mb, e(round($maxD, 1)));
    return $o . '</svg>';
}
