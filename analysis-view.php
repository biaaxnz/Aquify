<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$list = q_all('SELECT p.id,p.code,p.name,(SELECT MAX(a.id) FROM analyses a WHERE a.point_id=p.id) AS aid FROM points p WHERE p.owner_id=? AND EXISTS (SELECT 1 FROM analyses a WHERE a.point_id=p.id) ORDER BY aid DESC LIMIT 20', [current_user_id()]);
if (!$id && $list) $id = (int)$list[0]['id'];
$p = owned_point($id);
$an = $p ? q_one('SELECT * FROM analyses WHERE point_id=? ORDER BY id DESC LIMIT 1', [$id]) : null;
$aiRow = $p ? q_one("SELECT * FROM analyses WHERE point_id=? AND mode='ai' AND ai_text IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]) : null;
$aiDec = $aiRow ? aq_ai_decode((string)$aiRow['ai_text']) : null;
$ai = $aiDec['data'] ?? null;
$res = $an ? json_decode((string)$an['result_json'], true) : null;

page_head('Analisa AI', 'analysis', '', ['wide' => true]);
app_bar('Analisa AI', 'index.php');
if (!$p || !$an || !$res): ?>
  <div class="empty"><b>Belum ada hasil analisa</b>Jalankan analisis dari halaman detail survey setelah minimal 3 jarak elektroda terukur.<p><a class="btn sm" href="<?= $p ? 'point.php?id=' . (int)$p['id'] : 'history.php' ?>"><?= $p ? 'Buka detail survey' : 'Lihat riwayat survey' ?></a></p></div>
<?php page_foot(); exit; endif;

$score = (int)$an['score']; $C = 2 * M_PI * 58; $vt = verdict_tone($an['verdict'], true);
$best = $res['best'] ?? null;
$layers = $res['layers'] ?? [];
$cands = array_values(array_filter($layers, fn($l) => $l['key'] === 'aquifer'));
$canAnalyze = (int)($res['n'] ?? 0) >= 3;
$plan = aq_plan($p); $df = (float)cfg('analysis.depth_factor', 0.5);
$pseudo = aq_pseudo_data($id);
$rb0 = $ai['rekomendasi_bor'] ?? null; $drillMax = null; $drillMin = null;
if ($rb0 && $rb0['kedalaman_maks_m'] !== null && $rb0['keputusan'] !== 'belum_cukup_data') { $drillMax = $rb0['kedalaman_maks_m']; $drillMin = $rb0['kedalaman_min_m']; }
elseif (!empty($res['drill_depth'])) $drillMax = $res['drill_depth'];
$secOpt = ['electrodes' => (int)$plan['electrodes'], 'length' => aq_plan_length($plan)];
if ($drillMax !== null) $secOpt += ['drill_min' => $drillMin, 'drill_max' => $drillMax];
$s2dOpt = ['depth_factor' => $df, 'electrodes' => (int)$plan['electrodes'], 'spacing' => (float)$plan['spacing'], 'length' => aq_plan_length($plan)] + ($drillMax !== null ? ['drill_max' => $drillMax] : []);
$aiForm = function (string $label, string $cls = 'btn') use ($id) { ?>
  <form method="post" action="analyze.php" data-overlay="AI sedang menganalisis data titik ini. Biasanya 15–60 detik, jangan tutup halaman…">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="ai">
    <button class="<?= $cls ?>" type="submit"><?= icon('sparkles', 18) ?> <?= e($label) ?></button>
  </form>
<?php };
$set = aq_measured_cells($id); $cells = aq_plan_cells($plan); $nextCell = aq_next_cell($plan, $set);
?>
<?php if (count($list) > 1): ?><div class="chips" role="group" aria-label="Pilih titik"><?php foreach ($list as $r): ?><a class="chip-b"<?= (int)$r['id'] === $id ? ' aria-current="page"' : '' ?> href="analysis-view.php?id=<?= (int)$r['id'] ?>"><?= e($r['code']) ?></a><?php endforeach; ?></div><?php endif; ?>

<section class="hero2">
  <div class="hero2-l">
    <div class="tl"><?= icon('sparkles', 16) ?>Titik <?= e($p['code']) ?> · <?= e($p['name']) ?></div>
    <div class="gauge sm" role="img" aria-label="Skor potensi <?= $score ?> dari 100">
      <svg viewBox="0 0 136 136"><circle cx="68" cy="68" r="58" fill="none" stroke="rgba(255,255,255,.16)" stroke-width="12"/><circle cx="68" cy="68" r="58" fill="none" stroke="#34E0B4" stroke-width="12" stroke-linecap="round" stroke-dasharray="<?= round($C * $score / 100, 1) ?> <?= round($C, 1) ?>"/></svg>
      <div class="t"><b><?= $score ?></b><span>Skor potensi</span></div>
    </div>
  </div>
  <div class="kpis">
    <div class="kpi"><small>Potensi air</small><b><?= e($vt[0]) ?></b></div>
    <div class="kpi"><small>Target kedalaman</small><b><?= $best ? '±' . fnum($best['depth'], 1) . ' m' : '–' ?></b></div>
    <div class="kpi"><small>Tahanan target</small><b><?= $best ? fnum($best['rho'], 0) . ' Ω·m' : '–' ?></b></div>
    <div class="kpi"><small>Keyakinan AI</small><b><?= $ai ? (int)$ai['keyakinan_ai'] . '%' : '–' ?></b></div>
  </div>
</section>

<div id="aiTabs">
<nav class="tabs3" aria-label="Bagian analisis">
  <button type="button" data-tab="ringkasan" class="on">Ringkasan</button>
  <button type="button" data-tab="penampang">Penampang</button>
  <button type="button" data-tab="detail">Detail AI</button>
  <button type="button" data-tab="tanya">Tanya AI</button>
</nav>

<div class="tabpanel on" data-panel="ringkasan">
  <section class="card viz-card<?= $pseudo ? ' viz-2d-card' : '' ?>"><div class="viz-h"><h3><?= $pseudo ? 'Penampang resistivitas 2D' : 'Penampang tahanan tanah' ?></h3><span class="bdg bdg-<?= $pseudo ? 'info' : 'mute' ?>"><?= $pseudo ? 'Interpolasi data ukur 2D' : 'Model lapisan · seragam ke samping' ?></span></div>
    <?php if ($pseudo): ?><div class="plot-stage"><?= aq_section2d_svg($pseudo, $s2dOpt) ?></div><?php else: ?><div class="secwrap"><?= aq_section_svg($layers, $secOpt) ?></div><?php endif; ?>
    <p class="hint"><?= $pseudo ? 'Warna menunjukkan perkiraan tahanan di tiap titik lintasan. Garis putih melingkari zona berpotensi air (25–150 Ω·m). Ini interpolasi data ukur, bukan inversi 2D.' : 'Titik bertanda "Bor" adalah kedalaman yang disarankan. Model dari satu titik ukur sehingga dianggap sama ke samping. Untuk penampang 2D, ukur di beberapa posisi lintasan (lihat tab Penampang).' ?></p></section>

  <section class="card"><h3>Kesimpulan</h3>
    <?php if ($ai && $ai['ringkasan'] !== ''): ?>
      <p><?= e($ai['ringkasan']) ?></p><p class="hint"><?= e($ai['alasan_keyakinan']) ?></p>
    <?php elseif ($best): ?>
      <p>Potensi air tanah <b><?= e(mb_strtolower($vt[0])) ?></b> terdeteksi pada kedalaman sekitar <b><?= fnum($best['depth'], 1) ?> m</b>, berasosiasi dengan <b><?= e(mb_strtolower($best['class'])) ?></b> (±<?= fnum($best['rho'], 0) ?> Ω·m).<?= !empty($res['drill_depth']) ? ' Saran kedalaman bor indikatif <b>' . fnum($res['drill_depth'], 0) . ' m</b>.' : '' ?></p>
    <?php else: ?>
      <p>Belum ditemukan lapisan yang cocok sebagai akuifer pada kedalaman terjangkau.</p>
    <?php endif; ?>
    <?php foreach (($res['warnings'] ?? []) as $w): ?><div class="alert warn"><?= icon('alert', 18) ?><span><?= e($w) ?></span></div><?php endforeach; ?>
  </section>

  <?php if (!$ai): ?>
  <section class="card ai-cta"><h3><?= icon('sparkles', 18) ?> Analisis AI mendalam</h3>
    <p class="muted">AI membaca kurva sounding, data mentah, kualitas data, hasil air, dan titik di sekitar, lalu menulis interpretasi lapisan, rekomendasi bor, risiko, dan saran pengukuran tambahan.</p>
    <?php if (!gemini_ready()): ?><div class="alert info"><?= icon('info', 18) ?><span>Belum aktif. Isi <code>gemini.api_key</code> di <code>config.php</code> (lihat Pengaturan).</span></div>
    <?php elseif (!$canAnalyze): ?><div class="alert warn"><?= icon('alert', 18) ?><span>Butuh minimal 3 jarak elektroda berbeda. Saat ini <?= (int)$res['n'] ?>.</span></div>
    <?php else: $aiForm('Jalankan analisis AI'); endif; ?>
  </section>
  <?php else: $rb = $ai['rekomendasi_bor']; [$kl, $kt] = aq_ai_label_keputusan($rb['keputusan']); $pa = $ai['potensi_air_tanah']; [$pl, $pt] = verdict_tone(['tinggi' => 'Tinggi', 'sedang' => 'Sedang', 'rendah' => 'Rendah', 'berisiko' => 'Berisiko asin/lempung'][$pa['tingkat']] ?? null, true); ?>
    <?php if ($ai['pemeriksaan_otomatis']): ?><div class="alert warn"><?= icon('alert', 18) ?><div><b>Pemeriksaan otomatis</b><ul class="plain"><?php foreach ($ai['pemeriksaan_otomatis'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
    <div class="grid2c">
    <section class="card rec"><div class="rec-h" style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0">Rekomendasi bor</h3><?= badge($kl, $kt) ?></div>
      <?php if ($rb['kedalaman_min_m'] !== null || $rb['kedalaman_maks_m'] !== null): ?><div class="depthrange"><b><?= $rb['kedalaman_min_m'] !== null ? fnum($rb['kedalaman_min_m'], 0) : '?' ?>–<?= $rb['kedalaman_maks_m'] !== null ? fnum($rb['kedalaman_maks_m'], 0) : '?' ?> m</b><span>perkiraan kedalaman bor</span></div><?php endif; ?>
      <?php if ($rb['zona_saringan_dari_m'] !== null && $rb['zona_saringan_sampai_m'] !== null): ?><p class="hint" style="margin:0 0 8px">Zona saringan (screen): <b class="mono"><?= fnum($rb['zona_saringan_dari_m'], 0) ?>–<?= fnum($rb['zona_saringan_sampai_m'], 0) ?> m</b></p><?php endif; ?>
      <p><?= e($rb['alasan']) ?></p>
      <?php if ($rb['syarat_atau_peringatan'] !== ''): ?><div class="alert warn"><?= icon('alert', 18) ?><span><?= e($rb['syarat_atau_peringatan']) ?></span></div><?php endif; ?></section>
    <section class="card"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:6px"><h3 style="margin:0">Potensi &amp; kualitas air</h3><?= badge($pl, $pt) ?></div>
      <p><?= e($pa['penjelasan']) ?></p>
      <?php if ($pa['perkiraan_kualitas_air'] !== ''): ?><p><b>Perkiraan kualitas:</b> <?= e($pa['perkiraan_kualitas_air']) ?></p><?php endif; ?>
      <?php if ($pa['kesesuaian_dengan_data_air'] !== ''): ?><p><b>Dengan data air:</b> <?= e($pa['kesesuaian_dengan_data_air']) ?></p><?php endif; ?></section>
    </div>
  <?php endif; ?>
</div>

<div class="tabpanel" data-panel="penampang">
  <section class="card viz-card"><div class="viz-h"><h3>Rincian lapisan</h3></div>
    <?php if ($pseudo): ?><div class="secwrap" style="margin-bottom:10px"><?= aq_section_svg($layers, $secOpt) ?></div><?php endif; ?>
    <?= $layers ? aq_section_extras($layers) : '<p class="muted" style="margin:0">Belum ada profil lapisan.</p>' ?></section>
  <section class="card viz-card"><div class="viz-h"><h3>Kurva sounding</h3></div><?= aq_curve_svg($res['sounding'] ?? []) ?>
    <details class="acc"><summary>Cara membaca grafik ini <?= icon('chev-down', 18) ?></summary><div class="in">
      <p>Sumbu mendatar adalah <b>jarak elektroda</b>: makin ke kanan, makin dalam lapisan yang terbaca. Sumbu tegak adalah <b>tahanan</b> tanah.</p>
      <p>Latar berwarna menunjukkan arti nilainya. Kurva yang <b>turun lalu naik</b> biasanya berarti ada lapisan basah di tengah. Kurva yang terus naik berarti makin dalam makin kering atau keras.</p></div></details></section>
  <?php if ($cells): ?>
  <section class="card viz-card"><div class="viz-h"><h3>Mau penampang 2D seperti RES2DINV?</h3><span class="bdg bdg-<?= count($set) ? 'info' : 'mute' ?>"><?= count($set) ?> / <?= count($cells) ?> titik terukur</span></div>
    <p class="muted" style="margin-top:0">Satu titik ukur hanya menghasilkan model lapisan. Penampang 2D butuh pengukuran di banyak posisi sepanjang lintasan. Setiap lingkaran di bawah adalah satu pengukuran Wenner (posisi di sumbu mendatar, kedalaman semu di sumbu tegak). Lingkaran hijau sudah terukur.</p>
    <div class="secwrap"><?= aq_grid_plan_svg($plan, $set, $df) ?></div>
    <p class="hint"><?= $nextCell ? 'Pengukuran berikutnya: jarak a = <b>' . fnum($nextCell['a'], 1) . ' m</b> di posisi x = <b>' . fnum($nextCell['x'], 1) . ' m</b>. Kirim lewat ESP32 (<code>SPACING</code> dan <code>POS</code>) atau input manual di halaman Data Live.' : 'Semua titik rencana sudah terukur.' ?></p>
    <a class="btn outline sm" href="survey-run.php?id=<?= $id ?>#manual">Input pengukuran manual</a></section>
  <?php endif; ?>
</div>

<div class="tabpanel" data-panel="detail">
<?php if ($ai): $kd = $ai['kualitas_data']; $kt2 = ['baik' => 'ok', 'cukup' => 'warn', 'buruk' => 'bad'][$kd['penilaian']] ?? 'mute'; ?>
  <?php if ($ai['interpretasi_lapisan']): ?><div class="sec" style="margin-top:0"><h2>Interpretasi lapisan</h2></div>
  <?php foreach ($ai['interpretasi_lapisan'] as $L): $col = $L['resistivitas_ohm_m'] !== null ? aq_res_color((float)$L['resistivitas_ohm_m']) : '#B8C8A4'; ?>
    <div class="lyr" style="--c:<?= e($col) ?>"><div class="lyr-h"><b class="mono"><?= $L['dari_m'] !== null ? fnum($L['dari_m'], 1) : '?' ?>–<?= $L['sampai_m'] !== null ? fnum($L['sampai_m'], 1) : '?' ?> m</b><span><?= e($L['material_dugaan']) ?></span><?php if ($L['resistivitas_ohm_m'] !== null): ?><em class="mono"><?= fnum($L['resistivitas_ohm_m'], 0) ?> Ω·m</em><?php endif; ?></div><p><?= e($L['alasan']) ?></p></div>
  <?php endforeach; endif; ?>
  <div class="grid2c">
  <section class="card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px"><h3 style="margin:0">Kualitas data</h3><?= badge(ucfirst($kd['penilaian']), $kt2) ?></div>
    <?php if ($kd['masalah']): ?><h4>Masalah terdeteksi</h4><ul class="plain"><?php foreach ($kd['masalah'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($kd['saran_perbaikan']): ?><h4>Saran perbaikan</h4><ul class="plain"><?php foreach ($kd['saran_perbaikan'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (!$kd['masalah'] && !$kd['saran_perbaikan']): ?><p class="muted" style="margin:0">Tidak ada catatan khusus.</p><?php endif; ?></section>
  <?php if ($ai['perbedaan_dengan_hasil_aturan'] !== '' || $ai['perbandingan_titik_sekitar'] !== ''): ?>
  <section class="card"><?php if ($ai['perbedaan_dengan_hasil_aturan'] !== ''): ?><h4 style="margin-top:0">Perbedaan dengan hasil aturan</h4><p><?= e($ai['perbedaan_dengan_hasil_aturan']) ?></p><?php endif; ?>
    <?php if ($ai['perbandingan_titik_sekitar'] !== ''): ?><h4>Dibanding titik sekitar</h4><p><?= e($ai['perbandingan_titik_sekitar']) ?></p><?php endif; ?></section>
  <?php endif; ?>
  </div>
  <?php foreach ([['risiko_dan_ketidakpastian', 'Risiko & ketidakpastian', 'alert'], ['pengukuran_tambahan', 'Pengukuran tambahan yang disarankan', 'target'], ['langkah_selanjutnya', 'Langkah selanjutnya', 'check-circle']] as [$k, $t, $ic]): if (!$ai[$k]) continue; ?>
    <div class="sec"><h2><?= e($t) ?></h2></div>
    <section class="card"><ul class="iconlist"><?php foreach ($ai[$k] as $x): ?><li><?= icon($ic, 18) ?><span><?= e($x) ?></span></li><?php endforeach; ?></ul></section>
  <?php endforeach; ?>
  <p class="hint">Dibuat oleh <?= e($aiDec['model'] ?? 'Gemini') ?> · <?= round(($aiDec['ms'] ?? 0) / 1000, 1) ?> dtk<?= !empty($aiDec['tokens']) ? ' · ' . (int)$aiDec['tokens'] . ' token' : '' ?> · <?= e(ago($aiRow['created_at'])) ?>. AI bisa keliru; validasi dengan inversi, data sumur sekitar, dan ahli sebelum mengebor.</p>
  <?php if (gemini_ready() && $canAnalyze) $aiForm('Jalankan ulang analisis AI', 'btn outline'); ?>
<?php else: ?>
  <div class="empty"><b>Belum ada analisis AI</b>Detail interpretasi, kualitas data, risiko, dan langkah selanjutnya muncul setelah analisis AI dijalankan.<?php if (gemini_ready() && $canAnalyze): ?><p><?php $aiForm('Jalankan analisis AI'); ?></p><?php endif; ?></div>
<?php endif; ?>
</div>

<div class="tabpanel" data-panel="tanya">
<?php if ($ai || gemini_ready()): ?>
<section class="card ask" id="askBox" data-point="<?= $id ?>"><h3>Tanya AI tentang titik ini</h3>
  <div class="asklog" id="askLog" aria-live="polite"></div>
  <div class="chips-wrap" id="askSug"><?php foreach (['Kapan dan seberapa dalam sebaiknya mengebor?', 'Apakah air di sini berisiko asin atau payau?', 'Pengukuran apa yang perlu ditambah?', 'Jelaskan kurva ini dengan bahasa sederhana'] as $s): ?><button type="button" class="chip-b"><?= e($s) ?></button><?php endforeach; ?></div>
  <form id="askForm" class="askform"><input id="askQ" name="q" maxlength="600" placeholder="Tulis pertanyaan…" autocomplete="off" aria-label="Pertanyaan untuk AI"><button class="btn teal" type="submit" aria-label="Kirim"><?= icon('chev-right', 20) ?></button></form>
  <p class="hint">Jawaban memakai data titik ini saja. Maksimal 20 pertanyaan per jam.</p></section>
<?php else: ?><div class="empty"><b>AI belum aktif</b>Isi <code>gemini.api_key</code> di <code>config.php</code> untuk bertanya jawab dengan AI.</div><?php endif; ?>
<?php if ($cands): ?><div class="sec"><h2>Kandidat lapisan akuifer (aturan)</h2></div>
<?php foreach ($cands as $i => $L): ?>
  <div class="reco"><span class="li-ic <?= $i === 0 ? 'g' : 'o' ?>"><?= icon('target', 22) ?></span><span class="li-b"><b>Lapisan <?= $i === 0 ? 'prioritas' : 'alternatif' ?></b><small><?= fnum($L['from'], 1) ?>–<?= fnum($L['to'], 1) ?> m · ±<?= fnum($L['rho'], 0) ?> Ω·m</small></span><?= badge($i === 0 ? 'Utama' : 'Alternatif', $i === 0 ? 'ok' : 'warn') ?></div>
<?php endforeach; endif; ?>
<details class="acc"><summary>Bagaimana analisis ini dihitung? <?= icon('chev-down', 18) ?></summary><div class="in">
  <p><b>Tahap 1, aturan:</b> nilai tengah tahanan semu tiap jarak elektroda dikelompokkan ke kelas lapisan (&lt;10 lempung/asin, 10–25 payau, 25–150 akuifer, 150–500 kering, ≥500 batuan). Kedalaman ≈ jarak × 0,5 (aturan praktis, bukan inversi).</p>
  <p><b>Tahap 2, AI (Gemini):</b> AI menerima kurva, data mentah per jarak, rencana survei, hasil aturan, sampel air, dan titik sekitar 3 km, lalu menjawab terstruktur dengan menyebut nilai data sebagai dasar.</p>
  <p><b>Tahap 3, pemeriksaan otomatis:</b> jawaban AI dicek terhadap data dan keyakinan dibatasi 85%. Hasil bersifat indikatif; validasi sebelum mengebor.</p></div></details>
<details class="acc"><summary>Arti istilah <?= icon('chev-down', 18) ?></summary><div class="in">
  <p><b>Tahanan (Ω·m):</b> seberapa sulit batuan menghantar arus listrik. Pasir jenuh air tawar cukup tinggi, lempung dan air asin rendah.</p>
  <p><b>Resistivitas semu:</b> nilai rata-rata terukur, belum tentu sama dengan lapisan sebenarnya. Perlu inversi untuk angka yang lebih pasti.</p>
  <p><b>Akuifer:</b> lapisan penyimpan dan pengalir air tanah, biasanya pasir atau kerikil. <b>Zona saringan:</b> bagian pipa sumur berlubang pada akuifer.</p></div></details>
</div>
</div>
<div class="btns"><a class="btn outline" href="point.php?id=<?= $id ?>">Detail Survey</a><a class="btn" href="map.php">Lihat di Peta</a></div>
<?php page_foot();
