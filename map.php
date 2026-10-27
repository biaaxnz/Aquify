<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$pts = array_values(array_filter(aq_points_overview(), fn($p) => $p['lat'] !== null && $p['lng'] !== null));
$data = array_map(fn($p) => [
    'id' => (int)$p['id'], 'code' => $p['code'], 'name' => $p['name'], 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'],
    'verdict' => $p['verdict'], 'label' => verdict_tone($p['verdict'], true)[0], 'tone' => verdict_tone($p['verdict'])[1], 'color' => verdict_pin_color($p['verdict']),
    'depth' => $p['best_depth'] !== null ? round((float)$p['best_depth'], 1) : null, 'rho' => $p['best_rho'] !== null ? round((float)$p['best_rho']) : null, 'score' => $p['score'] !== null ? (int)$p['score'] : null,
], $pts);
page_head('Peta Potensi Air', 'map', '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">', ['wide' => true]); ?>
<div class="search">
  <div class="inp"><?= icon('search', 18) ?><input id="mapSearch" type="search" placeholder="Cari lokasi atau kode titik…" aria-label="Cari titik"></div>
  <a class="iconbtn" href="history.php" aria-label="Riwayat survey" style="width:48px;height:48px;flex-basis:48px"><?= icon('sliders', 20) ?></a>
</div>
<div class="chips" id="mapChips" role="group" aria-label="Filter peta">
  <button class="chip-b on" type="button" data-f="all">Semua</button>
  <button class="chip-b" type="button" data-f="high">Potensi Tinggi</button>
  <button class="chip-b" type="button" data-f="shallow">Kedalaman &lt;20 m</button>
</div>
<div class="mapbox tall"><div id="map" data-points="<?= e(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>" role="application" aria-label="Peta titik survey"></div></div>
<div class="maplegend"><span><i style="background:#2CB4A4"></i>Tinggi</span><span><i style="background:#145C84"></i>Sedang</span><span><i style="background:#D98B3A"></i>Rendah</span><span><i style="background:#C4432F"></i>Berisiko</span></div>
<?php if (!$data): ?>
  <div class="empty" style="margin-top:14px"><b>Belum ada titik berkoordinat</b>Buat survey baru dan tentukan lokasinya di peta.<p><a class="btn sm" href="survey-new.php">Survey Baru</a></p></div>
<?php endif; ?>
<div class="selcard" id="selCard" hidden>
  <div class="selcard-top"><div><h3 id="scTitle"></h3><small id="scSub"></small></div><span id="scBadge"></span></div>
  <div class="stats3"><div><b id="scDepth">–</b><span>Est. Kedalaman</span></div><div><b id="scRho">–</b><span>Resistivitas</span></div><div><b id="scScore">–</b><span>Skor Potensi</span></div></div>
  <div class="btns"><a class="btn sm" id="scLink" href="#">Lihat Detail</a><a class="btn sm outline" id="scAi" href="#">Analisa AI</a></div>
</div>
<?php page_foot('<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script><script src="assets/map.js?v=' . (@filemtime(AQ_ROOT . '/assets/map.js') ?: 1) . '" defer></script>');
