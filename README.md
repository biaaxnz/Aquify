# Aquify — Smart Groundwater Exploration System

Aquify berjalan dengan **PHP, CSS, JavaScript, dan MySQL/MariaDB** di XAMPP. Aplikasi tidak lagi membutuhkan Python atau Streamlit.

## Versi baru tanpa PHP

Aplikasi Next.js + TypeScript dan Supabase di `next-app/` kini mencakup peta interaktif, grafik sounding dan penampang 2D interpolasi, analisis aturan, integrasi Gemini opsional, sinkron Firebase opsional, serta endpoint ingest ESP32. Ini proyek terpisah; versi PHP dan database MySQL tetap utuh sebagai cadangan. Notifikasi push, serial USB/Web Serial, OTP khusus, dan migrasi data lama belum selesai. Baca `next-app/README.md` untuk instalasi, konfigurasi layanan, dan migrasi SQL sebelum menguji.

## Fitur
- Dashboard, titik survei, peta Leaflet/OpenStreetMap, dan riwayat pengukuran.
- Sinkronisasi Firebase Realtime Database ke MySQL dengan pencatatan payload mentah dan deduplikasi.
- Analisis resistivitas geolistrik serta opsi analisis AI Gemini melalui backend PHP.
- Halaman **Perangkat ESP32** untuk membaca JSON dari USB/Serial melalui Web Serial API pada Chrome/Edge, lalu menyimpan data ke MySQL.
- Penyimpanan pembacaan mentah di `sensor_readings`; data yang valid juga dinormalisasi ke `measurements` atau `water_samples`.
- Catatan aktivitas dan status kegagalan penyimpanan.

## Instalasi XAMPP
1. Ekstrak folder `aquify-web` ke `C:\xampp\htdocs\aquify-web`.
2. Jalankan Apache dan MySQL di XAMPP.
3. Buka `http://localhost/aquify-web/install.php` dan ikuti wizard. Jika database belum dibuat, impor `database/aquify.sql` melalui phpMyAdmin.
4. Masuk melalui `http://localhost/aquify-web/login.php`. Konfigurasi rahasia disimpan di `config.php` dan tidak disertakan dalam ZIP.
5. Setelah instalasi selesai, hapus atau nonaktifkan `install.php` pada server produksi.

## Membaca data ESP32
1. Buka halaman **Perangkat ESP32** dari menu.
2. Gunakan Chrome atau Microsoft Edge terbaru pada `localhost` atau HTTPS.
3. Pilih baud rate yang sama dengan firmware, pilih port serial, lalu hubungkan.
4. Firmware ESP32 harus mengirim satu objek JSON per baris, diakhiri newline (`\n`).

Contoh format (nilai di bawah hanya ilustrasi struktur, bukan hasil sensor):
```json
{"type":"geoelectric","point":"P01","device":"AQ-01","config":"wenner","spacing":2,"dv_mv":12.4,"i_ma":9.8,"ts":1760000000}
{"type":"water","point":"P01","device":"AQ-01","ph":7.2,"tds":310,"ec":480,"temp":28.4,"turbidity_ntu":1.2,"ts":1760000000}
```

Browser akan mengirim pesan JSON ke endpoint PHP yang memerlukan sesi login dan token CSRF. Jika penyimpanan gagal, UI menampilkan kegagalan dan tidak menyatakan data tersimpan. Web Serial tidak tersedia pada semua browser; gunakan Chrome/Edge dan izinkan akses port.

## Firebase dan AI
- Konfigurasikan URL/credential Firebase di wizard/config server. Sinkronisasi Firebase berjalan saat dipanggil dari halaman sinkron atau endpoint yang dikonfigurasi; bukan listener server realtime permanen.
- Analisis Gemini membutuhkan API key yang valid di konfigurasi server. Hasil analisis dan log disimpan di MySQL.
- Analisis berbasis aturan bukan AI generatif. Evaluasi kualitas air hanya indikatif dan bukan sertifikasi kelayakan air minum. Hasil geolistrik perlu divalidasi dengan kondisi lapangan dan penilaian ahli.

## Pengujian
- `php tests/analysis_test.php` untuk pengujian logika analisis PHP.
- `php -l device.php` dan `php -l api/serial-save.php` untuk pemeriksaan sintaks.
- Uji koneksi Firebase, MySQL, Gemini, dan ESP32 secara nyata di komputer yang menjalankan XAMPP.

## Struktur penting
- `*.php`, `api/`, `includes/` — backend dan halaman aplikasi.
- `assets/style.css` — tampilan responsif.
- `assets/app.js`, `assets/serial.js` — interaksi dashboard dan serial ESP32.
- `database/aquify.sql` — skema database.


## Tampilan mengikuti mockup Aquify
UI diperbarui dengan palet navy-teal, kartu putih membulat, ringkasan metrik dua kolom di ponsel, navigasi bawah di layar kecil, dan sidebar di desktop. Halaman yang tersedia memakai kerangka visual yang sama; beberapa detail seperti OTP, notifikasi push, dan login Google tetap memerlukan integrasi backend/provider khusus jika ingin berfungsi persis seperti mockup.


## Pembaruan tampilan (mengikuti mockup aplikasi)

- Desain ulang seluruh antarmuka: navy–teal, kartu putih membulat, Space Grotesk + Inter + JetBrains Mono, bilah tab bawah dengan tombol + di tengah (mobile) dan sidebar (desktop).
- Halaman baru/diperbarui: `welcome`, `login`, `register`, `otp`, `index` (beranda), `map`, `survey-new`, `survey-config`, `survey-run` (Data Live), `water` (Cek Kualitas Air), `water-detail`, `history`, `point` (Detail Survey), `analysis-view` (Analisa AI), `device` (Status Perangkat), `notifications`, `profile`, `settings`.
- Semua data berasal dari database; tidak ada angka contoh. Bagian yang belum punya data sumber (mis. debit L/detik, kalibrasi perangkat) sengaja tidak ditampilkan. Baterai & sinyal perangkat tampil bila JSON sensor memuat `battery` / `rssi`.
- Migrasi otomatis (sekali) menambah kolom: `users.full_name/email/phone`, `points.survey_type/team/plan_json`, `water_samples.notes`. Butuh hak ALTER pada database.
- Login menerima email atau username. Pengguna terdaftar berperan `viewer` namun boleh membuat survey, menyimpan rencana & catatan, dan menjalankan analisis; menghapus/mengubah data dan kelola pengguna tetap khusus admin.
- Verifikasi OTP email aktif bila `mail.otp = true` di `config.php` (memakai `mail()` PHP; butuh SMTP/sendmail di server). Bila nonaktif, akun langsung dibuat. Tombol "Masuk dengan Google" masih nonaktif (belum ada OAuth).
- Peta memakai Leaflet + citra Esri World Imagery (dengan atribusi).
- `assets/aquify_badge_hd.png` adalah versi 1024 px dengan latar luar transparan untuk logo dan favicon.

## Data otomatis dari ESP32 (Firebase)

Panduan lengkap: `docs/ESP32_FIREBASE.md`. Sketsa: `firmware/aquify_esp32/`, aturan Firebase: `firmware/firebase_rules.json`.
Perubahan sisi web: halaman Data Live dan Cek Kualitas Air menarik data Firebase tiap ~8 detik (`sync.live_interval_sec`); baterai/sinyal perangkat dibaca dari JSON kiriman ESP32; perbaikan galat pembersihan `sync_log` yang membuat API sinkron membalas HTTP 500; titik yang dibuat otomatis dari data air bertipe `water`.

## Pembaruan: AI mendalam, login, dan kemudahan pakai

- **Analisa AI (Gemini) mendalam:** AI menerima kurva sounding, data mentah per jarak (arus, tegangan, sebaran), rencana survei, hasil aturan, sampel air, dan titik sekitar 3 km. Jawaban terstruktur (JSON): ringkasan, keyakinan, interpretasi lapisan, potensi & kualitas air, rekomendasi bor (rentang kedalaman, zona saringan, syarat), kualitas data, risiko, pengukuran tambahan, langkah selanjutnya.
- **Pemeriksaan otomatis atas jawaban AI:** keyakinan dibatasi 85%; kedalaman bor yang melebihi kedalaman investigasi data ditandai; keputusan dipaksa "belum cukup data" bila <3 jarak; perbedaan dengan hasil aturan diperingatkan.
- **Tanya AI** tentang satu titik (maks. 20 pertanyaan/jam per sesi), tercatat di `ai_analysis_logs`.
- **Login:** tata letak dua panel di desktop, "Ingat saya 30 hari", peringatan Caps Lock, pembatasan 5 percobaan gagal (kunci 5 menit), meter kekuatan sandi saat daftar.
- **Kemudahan pakai:** notifikasi toast, layar tunggu saat AI bekerja, panduan 4 langkah survey, kartu "Mulai cepat" di beranda, input pengukuran manual di halaman Data Live, glosarium istilah.
- Konfigurasi AI: `gemini.api_key`, `gemini.model` (bawaan `gemini-3.6-flash`), `gemini.timeout`, opsional `gemini.thinking_budget`.

## Pembaruan: menu tersembunyi dan panel login

- Menu samping di desktop tersembunyi; tekan tombol titik tiga di pojok kiri atas untuk membukanya (tutup dengan tombol X, Esc, atau klik area gelap). Titik merah pada tombol berarti ada notifikasi belum dibaca.
- Status sinkronisasi di menu kini berupa baris berlampu (hijau aktif, merah gagal, abu belum diatur) dan bisa diklik menuju Sinkron Firebase / Pengaturan.
- Panel kiri halaman login/daftar: logo, tagline "Smart Groundwater Exploration", tiga kartu fitur, dan ilustrasi lapisan tanah beranimasi (mati otomatis bila sistem meminta mengurangi animasi).

## Menu utama: Pengukuran Tahanan Tanah (`geolistrik.php`)

Menu kedua di sidebar (setelah Beranda) dan kartu hijau di Beranda. Halaman ini menampilkan alur Firebase → Database → Olah data → Analisa AI, status perangkat, tahanan semu terbaru, kurva sounding, profil lapisan, dan tabel data. Tombol **Tarik data & analisis potensi air** menjalankan: tarik data baru dari Firebase ke MySQL, olah menjadi profil lapisan, lalu analisis AI (bila `gemini.api_key` terisi; jika tidak, hanya analisis aturan), kemudian membuka halaman Analisa AI. Butuh minimal 3 jarak elektroda berbeda pada titik tersebut. **Tarik data saja** hanya menyinkronkan. Data masuk ke titik yang kodenya sama dengan kode titik yang dikirim ESP32 (`POINT GL-xxx`).

## Visual penampang tahanan tanah (mudah dibaca orang awam)

- Skala warna logaritmik ala RES2DINV (biru = tahanan rendah, hijau = lapisan berpotensi air, kuning–merah = kering/batuan) dengan legenda berbahasa awam.
- **Penampang tahanan tanah**: warna halus dari model lapisan, elektroda di permukaan, titik bor yang disarankan, kalimat penjelas, dan rincian tiap lapisan. Ini model 1D (seragam ke samping), bukan inversi 2D.
- **Kurva sounding**: grafik log-log dengan pita arti nilai dan panduan "Cara membaca grafik ini".
- **Pseudosection 2D** (seperti *measured apparent resistivity pseudosection*) muncul otomatis bila pengukuran memuat posisi sepanjang lintasan (`x`, `pos`, atau `position` dalam meter; kolom baru `measurements.x_m`) pada ≥3 posisi dan ≥2 jarak. Ini interpolasi data ukur, bukan hasil inversi. ESP32: perintah Serial `POS 12` / `POS OFF`; input manual: kolom "Posisi x". Inversi 2D sungguhan (seperti RES2DINV) belum tersedia.

## Kunci Gemini: format "AIza…" dan "AQ."

- Kunci klasik berawalan `AIza` dipakai lewat Gemini Developer API.
- Kunci baru berawalan `AQ.` diterima Google di beberapa layanan saja. Aquify mencobanya lebih dulu lewat Vertex AI Express, lalu Gemini Developer API, dan mengingat layanan yang berhasil. Bila keduanya menolak, pesan galat menjelaskan layanan mana yang menolak dan sarannya.
- `Pengaturan > Tes koneksi Gemini` (admin) mengirim satu pesan uji dan menampilkan layanan yang dipakai, kode HTTP, serta pesan galat apa adanya.
- Opsi `gemini.provider`: `auto` (bawaan), `studio`, atau `vertex`.
- Jangan menaruh kunci di tempat publik. Bila kunci pernah terkirim lewat chat atau email, hapus lalu buat yang baru.

## Penampang 2D bergaya RES2DINV dan Analisa AI baru

- **Penampang resistivitas 2D** (`aq_section2d_svg`): dari data ukur ber-posisi (`x`), ditampilkan dengan pita warna logaritmik 17 tingkat seperti RES2DINV, legenda angka, elektroda di atas, zona berpotensi air (25–150 Ω·m) berpagar putih, dan titik bor. Ini interpolasi data ukur, bukan inversi 2D.
- **Peta pengukuran lintasan 2D**: menampilkan semua titik Wenner yang direncanakan (dari jumlah elektroda dan spasi di Konfigurasi), menandai yang sudah terukur, dan menyarankan pengukuran berikutnya (jarak a dan posisi x).
- **Data simulasi (admin)**: menu **Simulasi** membuat contoh `DEMO-01` sampai `DEMO-2D`, lalu membuka penampang resistivitas 2D yang dihaluskan dari interpolasi data. Semua data adalah simulasi (bukan hasil ukur atau inversi lapangan) dan dapat dihapus sekaligus dari detail titik DEMO.
- **Analisa AI**: hero dengan skor dan empat KPI, tab Ringkasan / Penampang / Detail AI / Tanya AI.
