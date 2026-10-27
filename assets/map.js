(function () {
  'use strict';
  if (!window.L) return;
  function baseLayers(map) {
    var key = (document.body.getAttribute('data-maptiler') || '').trim();
    var sat, street;
    if (key) {
      var k = '?key=' + encodeURIComponent(key), at = '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
      // {r} = '@2x' di layar retina -> tile 512 px dalam kotak 256 px (tajam). maxNativeZoom mencegah tile kosong/buram saat zoom terlalu dekat.
      sat = L.tileLayer('https://api.maptiler.com/maps/satellite/256/{z}/{x}/{y}{r}.jpg' + k, { maxZoom: 20, maxNativeZoom: 18, attribution: at });
      street = L.tileLayer('https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}{r}.png' + k, { maxZoom: 20, maxNativeZoom: 19, attribution: at });
    } else {
      sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Tiles &copy; Esri' });
      street = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
    }
    sat.addTo(map);
    return { 'Satelit': sat, 'Jalan': street };
  }
  function pin(color, sel) {
    return L.divIcon({ className: '', html: '<div class="pin' + (sel ? ' sel' : '') + '" style="background:' + color + '"></div>', iconSize: [26, 26], iconAnchor: [13, 26] });
  }
  function badge(label, tone) { var s = document.createElement('span'); s.className = 'bdg bdg-' + tone; s.textContent = label; return s; }

  /* ---------- Peta potensi air ---------- */
  var mapEl = document.getElementById('map');
  if (mapEl) {
    var pts = [];
    try { pts = JSON.parse(mapEl.getAttribute('data-points') || '[]'); } catch (e) {}
    var map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: true }).setView([-2.5, 118], 5);
    L.control.layers(baseLayers(map), null, { position: 'topright', collapsed: true }).addTo(map);
    var markers = [], selId = null, filter = 'all', q = '';
    var card = document.getElementById('selCard');
    function select(p) {
      selId = p.id;
      markers.forEach(function (m) { m.marker.setIcon(pin(m.p.color, m.p.id === selId)); });
      if (!card) return;
      card.hidden = false;
      document.getElementById('scTitle').textContent = 'Titik ' + p.code;
      document.getElementById('scSub').textContent = p.lat.toFixed(4) + ', ' + p.lng.toFixed(4) + ' · ' + p.name;
      var b = document.getElementById('scBadge'); b.innerHTML = ''; b.appendChild(badge(p.label, p.tone));
      document.getElementById('scDepth').textContent = p.depth !== null ? p.depth + ' m' : '–';
      document.getElementById('scRho').textContent = p.rho !== null ? p.rho + ' Ω·m' : '–';
      document.getElementById('scScore').textContent = p.score !== null ? p.score : '–';
      document.getElementById('scLink').href = 'point.php?id=' + p.id;
      document.getElementById('scAi').href = 'analysis-view.php?id=' + p.id;
    }
    pts.forEach(function (p) {
      var m = L.marker([p.lat, p.lng], { icon: pin(p.color, false), title: p.code + ' – ' + p.name, keyboard: true });
      m.on('click', function () { select(p); });
      markers.push({ p: p, marker: m });
    });
    function matches(p) {
      if (filter === 'high' && p.verdict !== 'Tinggi') return false;
      if (filter === 'shallow' && !(p.depth !== null && p.depth < 20)) return false;
      if (q && (p.code + ' ' + p.name).toLowerCase().indexOf(q) < 0) return false;
      return true;
    }
    function apply(fit) {
      var b = [], first = null;
      markers.forEach(function (m) {
        if (matches(m.p)) { m.marker.addTo(map); b.push([m.p.lat, m.p.lng]); if (!first) first = m.p; }
        else map.removeLayer(m.marker);
      });
      if (b.length && fit) map.fitBounds(b, { padding: [40, 40], maxZoom: 16 });
      if (first && selId === null) { var hi = markers.filter(function (m) { return matches(m.p) && m.p.verdict === 'Tinggi'; })[0]; select(hi ? hi.p : first); }
      else if (first && (selId === null || !matches(markers.filter(function (m) { return m.p.id === selId; })[0].p))) select(first);
      if (!first && card) card.hidden = true;
    }
    apply(true);
    var chips = document.getElementById('mapChips');
    if (chips) chips.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-f]'); if (!b) return;
      filter = b.getAttribute('data-f');
      chips.querySelectorAll('.chip-b').forEach(function (c) { c.classList.toggle('on', c === b); });
      apply(true);
    });
    var s = document.getElementById('mapSearch');
    if (s) s.addEventListener('input', function () { q = s.value.trim().toLowerCase(); apply(true); });
  }

  /* ---------- Pemilih lokasi (Survey Baru) ---------- */
  var pick = document.getElementById('pickmap');
  if (pick) {
    var input = document.getElementById('coord');
    var pm = L.map(pick, { scrollWheelZoom: false }).setView([-2.5, 118], 5);
    L.control.layers(baseLayers(pm), null, { position: 'topright', collapsed: true }).addTo(pm);
    var mk = null;
    function setPoint(lat, lng, move) {
      if (!mk) mk = L.marker([lat, lng], { icon: pin('#2CB4A4', false), draggable: true }).addTo(pm).on('dragend', function () { var c = mk.getLatLng(); input.value = c.lat.toFixed(6) + ', ' + c.lng.toFixed(6); });
      else mk.setLatLng([lat, lng]);
      if (move) pm.setView([lat, lng], Math.max(pm.getZoom(), 16));
    }
    function fromInput() {
      var m = (input.value || '').match(/(-?\d+(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d+(?:[.,]\d+)?)/);
      if (m) setPoint(parseFloat(m[1].replace(',', '.')), parseFloat(m[2].replace(',', '.')), true);
    }
    pm.on('click', function (e) { input.value = e.latlng.lat.toFixed(6) + ', ' + e.latlng.lng.toFixed(6); setPoint(e.latlng.lat, e.latlng.lng, false); });
    input.addEventListener('change', fromInput);
    if (input.value) fromInput();
    var gps = document.getElementById('useGps');
    if (gps) gps.addEventListener('click', function () {
      if (!navigator.geolocation) { alert('Browser ini tidak mendukung geolokasi.'); return; }
      gps.textContent = 'mencari lokasi…';
      navigator.geolocation.getCurrentPosition(function (pos) {
        input.value = pos.coords.latitude.toFixed(6) + ', ' + pos.coords.longitude.toFixed(6);
        setPoint(pos.coords.latitude, pos.coords.longitude, true); gps.textContent = 'gunakan lokasi saya';
      }, function () { gps.textContent = 'gunakan lokasi saya'; alert('Lokasi tidak bisa dibaca. Izinkan akses lokasi atau ketuk peta.'); }, { enableHighAccuracy: true, timeout: 10000 });
    });
  }

  /* ---------- Peta mini (detail survey) ---------- */
  var mini = document.getElementById('miniMap');
  if (mini) {
    var lat = parseFloat(mini.getAttribute('data-lat')), lng = parseFloat(mini.getAttribute('data-lng'));
    if (!isNaN(lat) && !isNaN(lng)) {
      var mm = L.map(mini, { dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false, zoomControl: false }).setView([lat, lng], 17);
      baseLayers(mm);
      L.marker([lat, lng], { icon: pin(mini.getAttribute('data-color') || '#2CB4A4', false), interactive: false }).addTo(mm);
    }
  }
})();
