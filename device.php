<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$devs = aq_devices();
$recent = q_all('SELECT id,device_id,reading_type,measured_at,source FROM sensor_readings ORDER BY id DESC LIMIT 12');
$main = $devs[0] ?? null; $others = array_slice($devs, 1);
$prefill = mb_substr((string)($_GET['point'] ?? ''), 0, 40);
$sigTxt = function (?float $r): array { if ($r === null) return ['–', 0]; return $r >= -60 ? ['Kuat', 90] : ($r >= -75 ? ['Sedang', 60] : ['Lemah', 30]); };
page_head('Status Perangkat', 'device');
app_bar('Status Perangkat', 'index.php'); ?>
<?php if ($main): [$sg, $sp] = $sigTxt($main['rssi']); ?>
<section class="devcard">
  <div class="devhero"><?= icon('cpu', 96) ?></div>
  <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start"><div><h3><?= e($main['id']) ?></h3><div class="sub"><?= e($main['kind']) ?></div></div><?= badge($main['active'] ? 'Terhubung' : 'Tidak aktif', $main['active'] ? 'ok' : 'mute') ?></div>
  <?php if ($main['battery'] !== null || $main['rssi'] !== null): ?>
  <div class="meters">
    <?php if ($main['battery'] !== null): ?><div><div class="pb-h">Baterai<span><b><?= round($main['battery']) ?>%</b></span></div><div class="bar"><i style="width:<?= max(0, min(100, $main['battery'])) ?>%"></i></div></div><?php endif; ?>
    <?php if ($main['rssi'] !== null): ?><div class="sig"><div class="pb-h">Sinyal<span><b><?= e($sg) ?></b></span></div><div class="bar"><i style="width:<?= $sp ?>%"></i></div></div><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="kv"><span>Terakhir mengirim data</span><b><?= e(ago($main['last'])) ?></b></div>
  <div class="kv"><span>Data tersimpan</span><b class="mono"><?= (int)$main['n'] ?></b></div>
</section>
<?php if ($others): ?><div class="sec"><h2>Perangkat Lain</h2></div>
<?php foreach ($others as $d): $low = $d['battery'] !== null && $d['battery'] <= 25; ?>
  <div class="li"<?= $d['active'] ? '' : ' style="opacity:.75"' ?>><span class="li-ic <?= $d['water'] && !$d['geo'] ? 'g' : 'o' ?>"><?= icon($d['water'] && !$d['geo'] ? 'droplet' : 'cpu', 22) ?></span><span class="li-b"><b><?= e($d['id']) ?></b><small><?= $d['battery'] !== null ? 'Baterai ' . round($d['battery']) . '% · ' : '' ?><?= $d['active'] ? 'Terhubung' : 'Terakhir ' . e(ago($d['last'])) ?></small></span><span class="sdot <?= $d['active'] ? ($low ? 'low' : 'on') : 'off' ?>" title="<?= $d['active'] ? 'Aktif' : 'Tidak aktif' ?>"></span></div>
<?php endforeach; endif; ?>
<?php else: ?>
  <div class="empty"><b>Belum ada perangkat</b>Sambungkan ESP32 lewat USB atau sinkronkan data Firebase. Perangkat muncul di sini setelah mengirim data.</div>
<?php endif; ?>
<a class="btn block" href="#serial" style="margin-top:16px"><?= icon('plus', 18) ?> Sambungkan Perangkat Baru</a>

<section class="card" id="serial" style="margin-top:18px">
  <h2>Koneksi USB / Serial</h2>
  <p class="hint">Gunakan Chrome atau Microsoft Edge terbaru melalui localhost/HTTPS. ESP32 mengirim satu objek JSON per baris (diakhiri newline).</p>
  <div class="form-grid" style="margin-top:12px">
    <div><label for="baudRate">Baud rate</label><select id="baudRate"><option>115200</option><option>9600</option><option>57600</option><option>38400</option><option>19200</option></select></div>
    <div><label for="pointCode">Kode titik survey (opsional)</label><input id="pointCode" maxlength="40" placeholder="GL-001" value="<?= e($prefill) ?>"></div>
  </div>
  <div class="btns"><button class="btn" id="serialConnect" type="button"><?= icon('usb', 18) ?> Pilih port &amp; hubungkan</button><button class="btn outline" id="serialDisconnect" type="button" disabled>Putuskan</button></div>
  <p id="serialStatus" class="chip" role="status" style="margin-top:12px">Belum terhubung.</p>
  <div class="tbl-wrap" style="margin-top:8px"><table><thead><tr><th>Waktu</th><th>Jenis</th><th>Data JSON</th><th>Status simpan</th></tr></thead><tbody id="serialRows"><tr><td colspan="4" class="muted">Belum ada data masuk.</td></tr></tbody></table></div>
</section>
<details class="fold"><summary>Format data yang didukung <?= icon('chev-down', 18) ?></summary><div class="in">
  <p><b>Geolistrik:</b> <code>{"type":"geoelectric","point":"GL-001","device":"AQ-01","spacing":2,"dv_mv":12.4,"i_ma":9.8,"config":"wenner","ts":1760000000}</code></p>
  <p><b>Kualitas air:</b> <code>{"type":"water","point":"KA-001","device":"AQ-01","ph":7.2,"tds":310,"ec":480,"temp":28.4,"turbidity_ntu":1.2,"ts":1760000000}</code></p>
  <p class="hint">Opsional: sertakan <code>battery</code> (persen) dan <code>rssi</code> (dBm) agar baterai &amp; sinyal tampil di kartu perangkat. Contoh di atas hanya format pesan, bukan hasil pengukuran.</p>
</div></details>
<?php if ($recent): ?><details class="fold"><summary>Data sensor terakhir tersimpan <?= icon('chev-down', 18) ?></summary><div class="in"><div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Perangkat</th><th>Jenis</th><th>Waktu ukur</th><th>Sumber</th></tr></thead><tbody><?php foreach ($recent as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= e($r['device_id'] ?: '—') ?></td><td><?= e($r['reading_type']) ?></td><td><?= fdt($r['measured_at']) ?></td><td><?= e($r['source']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></details><?php endif; ?>
<?php page_foot('<script src="assets/serial.js?v=' . (@filemtime(AQ_ROOT . '/assets/serial.js') ?: 1) . '" defer></script>');
