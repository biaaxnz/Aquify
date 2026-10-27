<?php
// Jalankan: php tests/analysis_test.php   (tanpa database)
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/analysis.php';
require __DIR__ . '/../includes/viz.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "PASS " : "FAIL ") . $name . "\n"; if (!$ok) $fail++; }

// Geometri
check('wenner k = 2πa', abs(geom_k('wenner', 2, null) - 4 * M_PI) < 1e-9);
check('schlumberger k', abs(geom_k('schlumberger', 10, 2) - M_PI * (100 - 1) / 2) < 1e-9);
check('schlumberger tanpa MN -> null', geom_k('schlumberger', 10, null) === null);
check('schlumberger MN/2 >= AB/2 -> null', geom_k('schlumberger', 1, 3) === null);

// Kelas
check('kelas asin', rho_class(5)['key'] === 'saline');
check('kelas akuifer', rho_class(60)['key'] === 'aquifer');
check('kelas batuan', rho_class(900)['key'] === 'rock');

// Median & sounding
check('median genap', aq_median([1, 3, 2, 10]) === 2.5);
$rows = [['spacing_m' => 2, 'rho_a' => 100], ['spacing_m' => 2, 'rho_a' => 120], ['spacing_m' => 1, 'rho_a' => 90]];
$S = build_sounding($rows, 0.5);
check('sounding terurut & median', count($S) === 2 && $S[0]['s'] == 1 && $S[1]['rho'] == 110 && $S[1]['n'] == 2);

// Profil akuifer: tinggi -> rendah(akuifer) -> tinggi
$aq = [];
foreach ([[1, 130], [2, 110], [3, 70], [5, 40], [8, 35], [12, 80], [18, 220], [27, 380]] as [$s, $r]) $aq[] = ['spacing_m' => $s, 'rho_a' => $r];
$res = analyze_sounding(build_sounding($aq), null);
check('akuifer terdeteksi', $res['best'] !== null && $res['best']['key'] === 'aquifer');
check('target di sekitar 4 m', abs($res['best']['depth'] - 4.0) <= 2.0);
check('skor >= 45', $res['score'] >= 45);
check('ada saran kedalaman bor', $res['drill_depth'] !== null && $res['drill_depth'] >= 5);

// Profil kering: tidak ada akuifer
$dry = [];
foreach ([[1, 300], [2, 420], [3, 600], [5, 800], [8, 900]] as [$s, $r]) $dry[] = ['spacing_m' => $s, 'rho_a' => $r];
$r2 = analyze_sounding(build_sounding($dry), null);
check('kering -> Rendah', $r2['best'] === null && $r2['verdict'] === 'Rendah');

// Profil lempung/asin
$sal = [];
foreach ([[1, 40], [2, 15], [3, 8], [5, 6], [8, 5]] as [$s, $r]) $sal[] = ['spacing_m' => $s, 'rho_a' => $r];
$r3 = analyze_sounding(build_sounding($sal), null);
check('asin -> berisiko', $r3['verdict'] === 'Berisiko asin/lempung');

// Data kurang
$r4 = analyze_sounding(build_sounding(array_slice($aq, 0, 2)), null);
check('data kurang', $r4['verdict'] === 'Data kurang' && $r4['warnings']);

// Air
$w = water_assess(['tds_ppm' => 320, 'ec_us' => null, 'ph' => 7.1]);
check('EC dari TDS', abs($w['ec'] - 500) < 1e-6 && abs($w['rho_w'] - 20) < 1e-6);
$w2 = water_assess(['tds_ppm' => 2400, 'ec_us' => null, 'ph' => 7.0]);
check('TDS tinggi -> saline', $w2['saline'] === true);
$r5 = analyze_sounding(build_sounding($aq), $w2);
check('air asin menurunkan skor', $r5['score'] < $res['score']);

// SVG
$svg = svg_sounding($S) . svg_log($res['layers']);
check('svg dihasilkan', str_contains($svg, '<svg') && str_contains($svg, 'polyline'));
$contour = aq_mask_contour_path([[2, 2], [2, 3], [3, 2], [3, 3]], 6, 6, 10, 10, 4, 3);
check('kontur 2D memakai kurva halus', str_starts_with($contour, 'M') && str_contains($contour, 'Q') && str_contains($contour, 'Z'));

echo $fail ? "\n$fail GAGAL\n" : "\nSemua lulus\n";
exit($fail ? 1 : 0);
