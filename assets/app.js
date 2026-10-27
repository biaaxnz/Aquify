(function () {
  'use strict';
  var body = document.body;
  var csrf = body.getAttribute('data-csrf') || '';

  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute('data-confirm');
    if (m && !window.confirm(m)) e.preventDefault();
  });

  /* tampil/sembunyi kata sandi */
  document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.getAttribute('data-toggle-pw'));
      if (!i) return;
      i.type = i.type === 'password' ? 'text' : 'password';
      b.setAttribute('aria-label', i.type === 'password' ? 'Tampilkan kata sandi' : 'Sembunyikan kata sandi');
    });
  });

  /* stepper +/- */
  document.querySelectorAll('.stepper').forEach(function (s) {
    var inp = s.querySelector('input');
    s.querySelectorAll('button[data-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        var st = parseFloat(inp.step) || 1, mn = parseFloat(inp.min), mx = parseFloat(inp.max);
        var v = (parseFloat(inp.value) || 0) + st * parseFloat(b.getAttribute('data-step'));
        if (!isNaN(mn)) v = Math.max(mn, v); if (!isNaN(mx)) v = Math.min(mx, v);
        inp.value = (Math.round(v * 100) / 100).toString();
        inp.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
  });

  /* panjang lintasan (Konfigurasi survey) */
  var plan = document.getElementById('planForm');
  if (plan) {
    var out = document.getElementById('trackLen');
    var upd = function () {
      var n = parseFloat(plan.elements.electrodes.value) || 0, sp = parseFloat(plan.elements.spacing.value) || 0;
      out.textContent = (Math.max(0, n - 1) * sp).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' m';
    };
    plan.addEventListener('input', upd); upd();
  }

  /* OTP 6 kotak */
  var otp = document.querySelector('.otp');
  if (otp) {
    var boxes = [].slice.call(otp.querySelectorAll('input')), hid = document.getElementById('otpCode');
    var sync = function () { hid.value = boxes.map(function (b) { return b.value; }).join(''); boxes.forEach(function (b) { b.classList.toggle('filled', !!b.value); }); };
    boxes.forEach(function (b, i) {
      b.addEventListener('input', function () { b.value = b.value.replace(/\D/g, '').slice(-1); if (b.value && boxes[i + 1]) boxes[i + 1].focus(); sync(); });
      b.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].focus(); } });
      b.addEventListener('paste', function (e) {
        var t = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        if (!t) return; e.preventDefault();
        t.split('').forEach(function (c, k) { if (boxes[k]) boxes[k].value = c; }); sync(); (boxes[Math.min(t.length, 5)]).focus();
      });
    });
    boxes[0].focus();
    var rt = document.getElementById('resendTxt');
    if (rt) {
      var left = parseInt(rt.getAttribute('data-wait'), 10) || 0, tEl = document.getElementById('resendT'), rb = document.getElementById('resendBtn');
      var tick = function () {
        if (left <= 0) { rt.hidden = true; rb.hidden = false; return; }
        tEl.textContent = '0:' + (left < 10 ? '0' : '') + left; left--; setTimeout(tick, 1000);
      };
      tick();
    }
  }

  /* bagikan hasil */
  document.querySelectorAll('[data-share]').forEach(function (b) {
    b.addEventListener('click', function () {
      var data = { title: document.title, url: location.href };
      if (navigator.share) { navigator.share(data).catch(function () {}); }
      else if (navigator.clipboard) { navigator.clipboard.writeText(location.href).then(function () { var o = b.innerHTML; b.textContent = 'Tautan disalin'; setTimeout(function () { b.innerHTML = o; }, 1800); }); }
    });
  });

  /* ---------- Data langsung (polling) ---------- */
  var live = document.querySelector('[data-live]');
  if (live) {
    var kind = live.getAttribute('data-live'), pid = live.getAttribute('data-point') || '', paused = false, timer = null;
    var $ = function (id) { return document.getElementById(id); };
    var fmt = function (v, d) { return v === null || v === undefined ? '–' : Number(v).toLocaleString('id-ID', { minimumFractionDigits: d, maximumFractionDigits: d }); };
    var render = function (d) {
      var c = $('conn');
      if (c) {
        c.className = 'conn' + (d.device && d.device.active ? '' : ' off');
        c.querySelector('.ct').textContent = d.device && d.device.active ? 'Terhubung — ' + d.device.id : (d.device ? 'Terakhir aktif ' + d.device.ago + ' — ' + d.device.id : 'Menunggu perangkat…');
      }
      if (kind === 'geo') {
        if (d.latest) { $('lvI').textContent = fmt(d.latest.i_ma, 1); $('lvV').textContent = fmt(d.latest.dv_mv, 0); $('lvR').textContent = fmt(d.latest.rho_a, 1); }
        $('lvProg').textContent = 'Titik ' + d.done + ' / ' + d.total;
        var nd = $('newData'), od = live.getAttribute('data-done'); if (nd && od !== null && parseInt(od, 10) !== d.done) nd.hidden = false;
        $('lvBar').style.width = Math.min(100, d.done / d.total * 100) + '%';
        if (d.strata_html !== undefined) $('lvStrata').innerHTML = d.strata_html;
        var sv = $('saveBtn'); if (sv) sv.disabled = d.done < 3;
      } else if (d.sample) {
        Object.keys(d.sample.params).forEach(function (k) {
          var el = document.querySelector('[data-p="' + k + '"]'); if (!el) return;
          var p = d.sample.params[k];
          el.querySelector('.pv').textContent = fmt(p.val, p.dec);
          var b = el.querySelector('.pb-badge'); b.className = 'pb-badge bdg bdg-' + p.tone; b.textContent = p.st; b.hidden = !p.st;
        });
        var t = $('lvTime'); if (t) t.textContent = d.sample.ago;
      }
    };
    var poll = function () {
      if (paused || document.hidden) return;
      fetch('api/live.php?kind=' + kind + '&point=' + encodeURIComponent(pid), { credentials: 'same-origin', headers: { 'X-CSRF': csrf } })
        .then(function (r) { return r.json(); }).then(function (d) { if (d && !d.error) render(d); }).catch(function () {});
    };
    timer = setInterval(poll, 4000); setTimeout(poll, 600);
    var stop = $('stopBtn');
    if (stop) stop.addEventListener('click', function () {
      paused = !paused; stop.textContent = paused ? 'Lanjutkan' : 'Hentikan';
      stop.classList.toggle('soft-red', !paused); stop.classList.toggle('outline', paused);
    });
  }

  /* ---------- Sinkron otomatis Firebase ---------- */
  var secs = parseInt(body.getAttribute('data-autosync') || '0', 10);
  var chip = document.getElementById('syncChip');
  if (secs > 0 && chip) {
    var busy = false;
    var run = function () {
      if (document.hidden || busy) return;
      busy = true;
      fetch('api/sync.php', { method: 'POST', headers: { 'X-CSRF': csrf }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var t = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
          if (d.error) { chip.className = 'chip bad'; chip.textContent = 'Sinkron gagal: ' + String(d.error).slice(0, 60); }
          else if (d.inserted > 0) { chip.className = 'chip live'; chip.textContent = d.inserted + ' data baru, ' + t + ' – muat ulang'; }
          else { chip.className = 'chip live'; chip.textContent = 'Sinkron ' + t + (d.skipped ? '' : ', tidak ada data baru'); }
        })
        .catch(function () { chip.className = 'chip bad'; chip.textContent = 'Sinkron tak terjangkau'; })
        .then(function () { busy = false; });
    };
    setTimeout(run, 1500);
    setInterval(run, Math.max(30, secs) * 1000);
  }
})();

/* ===== Tambahan UX: toast, loading, tanya AI, login, kekuatan sandi ===== */
(function () {
  'use strict';
  // Toast: hilang otomatis (galat lebih lama), bisa ditutup
  document.querySelectorAll('.toast').forEach(function (t) {
    var close = function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 260); };
    t.querySelector('.toast-x').addEventListener('click', close);
    setTimeout(close, t.classList.contains('err') ? 9000 : 4500);
  });

  // Status loading saat formulir dikirim; overlay penuh untuk proses panjang (data-overlay)
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target; if (!(f instanceof HTMLFormElement) || f.id === 'askForm') return;
    var b = f.querySelector('button[type=submit]:not([disabled])'); if (!b) return;
    var ov = f.getAttribute('data-overlay');
    setTimeout(function () {
      b.classList.add('loading'); b.setAttribute('aria-busy', 'true');
      if (!b.querySelector('.spin')) { var s = document.createElement('span'); s.className = 'spin'; b.insertBefore(s, b.firstChild); }
      if (ov) {
        var o = document.createElement('div'); o.className = 'overlay'; o.setAttribute('role', 'alert');
        o.innerHTML = '<div class="box"><span class="spin big"></span><b>Mohon tunggu</b><p></p></div>'; o.querySelector('p').textContent = ov; document.body.appendChild(o);
      }
    }, 0);
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) { document.querySelectorAll('.overlay').forEach(function (o) { o.remove(); }); document.querySelectorAll('.btn.loading').forEach(function (b) { b.classList.remove('loading'); var s = b.querySelector('.spin'); if (s) s.remove(); }); } });

  // Peringatan Caps Lock
  document.querySelectorAll('input[type=password]').forEach(function (i) {
    var hint = document.getElementById('capsHint'); if (!hint) return;
    var chk = function (ev) { if (ev.getModifierState) hint.classList.toggle('on', ev.getModifierState('CapsLock')); };
    i.addEventListener('keydown', chk); i.addEventListener('keyup', chk); i.addEventListener('blur', function () { hint.classList.remove('on'); });
  });

  // Kekuatan kata sandi (daftar)
  var pw = document.getElementById('password'), meter = document.getElementById('pwMeter');
  if (pw && meter) {
    var txt = document.getElementById('pwText');
    pw.addEventListener('input', function () {
      var v = pw.value, sc = 0;
      if (v.length >= 8) sc++; if (v.length >= 12) sc++; if (/[a-z]/.test(v) && /[A-Z]/.test(v)) sc++; if (/\d/.test(v)) sc++; if (/[^A-Za-z0-9]/.test(v)) sc++;
      var lv = !v ? 0 : Math.min(4, Math.max(1, sc - (v.length < 8 ? 1 : 0)));
      var col = ['#E3ECEC', '#C4432F', '#D98B3A', '#2CB4A4', '#17806B'][lv], lab = ['', 'Lemah', 'Cukup', 'Baik', 'Kuat'][lv];
      meter.firstElementChild.style.width = (lv * 25) + '%'; meter.firstElementChild.style.background = col; txt.textContent = v ? 'Kekuatan: ' + lab + (v.length < 8 ? ' (min. 8 karakter)' : '') : '';
    });
  }

  // Tanya AI
  var box = document.getElementById('askBox');
  if (box) {
    var form = document.getElementById('askForm'), q = document.getElementById('askQ'), log = document.getElementById('askLog'), csrf = document.body.getAttribute('data-csrf') || '';
    var add = function (cls, html, text) { var d = document.createElement('div'); d.className = 'bub ' + cls; if (html) d.innerHTML = html; else d.textContent = text; log.appendChild(d); d.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); return d; };
    var ask = function (text) {
      text = text.trim(); if (text.length < 3) return;
      add('me', null, text); q.value = ''; q.disabled = true;
      var wait = add('ai', '<span class="typing"><i></i><i></i><i></i></span>');
      var fd = new FormData(); fd.append('id', box.getAttribute('data-point')); fd.append('q', text);
      fetch('api/ai-ask.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': csrf } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) { wait.remove(); if (x.ok && x.j.answer_html) add('ai', x.j.answer_html); else add('err', null, (x.j && x.j.error) || 'Gagal mendapat jawaban.'); })
        .catch(function () { wait.remove(); add('err', null, 'Koneksi bermasalah. Coba lagi.'); })
        .then(function () { q.disabled = false; q.focus(); });
    };
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(q.value); });
    document.getElementById('askSug').addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) ask(b.textContent); });
  }
})();

/* ===== Menu samping tersembunyi: buka lewat tombol titik tiga ===== */
(function () {
  'use strict';
  var rail = document.getElementById('rail'), tg = document.getElementById('railToggle'), bd = document.getElementById('railBackdrop'), cl = document.getElementById('railClose');
  if (!rail || !tg) return;
  var open = function (on) {
    rail.classList.toggle('open', on); tg.setAttribute('aria-expanded', on ? 'true' : 'false'); tg.setAttribute('aria-label', on ? 'Tutup menu' : 'Buka menu');
    bd.hidden = !on; document.body.classList.toggle('rail-open', on);
    if (on) { var f = rail.querySelector('.rail-nav a[aria-current]') || rail.querySelector('.rail-nav a'); if (f) f.focus(); } else { tg.focus(); }
  };
  tg.addEventListener('click', function () { open(!rail.classList.contains('open')); });
  bd.addEventListener('click', function () { open(false); });
  if (cl) cl.addEventListener('click', function () { open(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && rail.classList.contains('open')) open(false); });
})();

/* ===== Tab Analisa AI ===== */
(function () {
  'use strict';
  var box = document.getElementById('aiTabs'); if (!box) return;
  var btns = [].slice.call(box.querySelectorAll('.tabs3 button')), panels = [].slice.call(box.querySelectorAll('.tabpanel'));
  var show = function (n, push) {
    if (!panels.some(function (p) { return p.getAttribute('data-panel') === n; })) n = 'ringkasan';
    btns.forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === n); });
    panels.forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-panel') === n); });
    if (push) history.replaceState(null, '', '#' + n);
  };
  box.classList.add('tabs-on');
  btns.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-tab'), true); window.scrollTo({ top: Math.max(0, box.getBoundingClientRect().top + window.scrollY - 70), behavior: 'smooth' }); }); });
  show((location.hash || '').replace('#', '') || 'ringkasan', false);
})();
