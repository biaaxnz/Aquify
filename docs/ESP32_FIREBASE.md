# ESP32 → Firebase → Aquify (otomatis)

```
ESP32 --(HTTPS, REST)--> Firebase Realtime Database --(PHP menarik)--> MySQL Aquify --> tampilan web
        push ke /measurements dan /water                api/sync.php
```

Aquify **menarik** data dari Firebase; ESP32 tidak mengirim langsung ke web (kecuali memakai `api/ingest.php`, lihat bagian akhir).

## 1. Siapkan Firebase (sekali)
1. Buat proyek di console.firebase.google.com, lalu **Build → Realtime Database → Create database**. Catat URL-nya. Untuk proyek ini: `https://smart-groundwater-exploration-default-rtdb.asia-southeast1.firebasedatabase.app` (database region Asia Tenggara memakai domain `firebasedatabase.app`, bukan `firebaseio.com`).
2. **Build → Authentication → Sign-in method → Email/Password → Enable**. Di tab **Users**, tambahkan satu akun khusus perangkat (mis. `esp32@aquify.local` + sandi kuat).
3. **Project settings → General**: salin **Web API Key**.
4. **Realtime Database → Rules**: tempel isi `firmware/firebase_rules.json`, lalu Publish. Aturan ini melarang baca publik, dan hanya akun yang login boleh menulis ke `/measurements` dan `/water`.
5. Agar server Aquify boleh membaca: **Project settings → Service accounts → Database secrets** → salin secret, isi `firebase.secret` di `config.php`. (Secret ini mem-bypass rules; simpan hanya di server, jangan di sketsa ESP32 atau repo publik. Fitur ini dinyatakan legacy oleh Google; alternatifnya membuka `.read` pada dua path tersebut, yang berarti datanya terbaca publik.)

## 2. Atur `config.php`
```php
'firebase' => [
    'db_url' => 'https://smart-groundwater-exploration-default-rtdb.asia-southeast1.firebasedatabase.app',
    'secret' => 'DATABASE_SECRET',
    'paths'  => ['measurements' => 'measurements', 'water' => 'water'],
],
'sync' => ['auto_interval_sec' => 60, 'live_interval_sec' => 8, 'token' => 'token-acak-panjang'],
```
Server PHP perlu ekstensi **cURL** aktif.

### Peta (MapTiler)
Tambahkan kunci MapTiler agar peta satelit dan jalan memakai MapTiler (tanpa kunci, Aquify memakai Esri + OpenStreetMap):
```php
'maps' => ['maptiler_key' => 'KUNCI_MAPTILER'],
```
Kunci ini terlihat di browser, jadi di dashboard MapTiler batasi **Allowed HTTP origins** ke domain web Anda (dan `localhost` untuk uji). Bila domain belum diizinkan, tile peta tidak muncul (HTTP 403).

## 3. Isi dan unggah sketsa
Buka `firmware/aquify_esp32/aquify_esp32.ino` di Arduino IDE (board ESP32). Isi bagian KONFIGURASI: Wi-Fi, `FIREBASE_API_KEY`, `FIREBASE_DB_URL`, email/sandi akun perangkat, `DEVICE_ID`, dan `pointCode`.

- **`TEST_MODE = 1` (bawaan)**: hanya mengirim data UJI berlabel (`AQ-TEST`, titik `TEST-001` / `TEST-KA`) untuk memeriksa jalur. Itu bukan hasil pengukuran.
- Isi fungsi `readGeo()` dan `readWater()` sesuai rangkaian sensor Anda (ADC tegangan/arus, pH, TDS/EC, suhu, kekeruhan, dengan kalibrasi). Lalu set `TEST_MODE = 0`. Data tidak dikirim bila pembacaan tidak valid.
- Geolistrik: tekan tombol BOOT (atau ketik `GEO`) pada tiap jarak elektroda. Air: terkirim otomatis tiap 30 detik.
- Perintah Serial Monitor (115200): `POINT GL-024`, `SPACING 5`, `POS 12` (posisi x di lintasan, meter; `POS OFF` untuk mematikan), `GEO`, `WATER`, `STATUS`. Bila `POS` diisi pada banyak posisi, Aquify menampilkan pseudosection 2D.
- Bila internet putus, hingga 16 data ditahan dan dikirim ulang.

Buat titik survey di web (Survey Baru) lebih dulu, lalu pakai kodenya (`GL-0xx`) pada `POINT`. Kode yang belum ada akan dibuat otomatis oleh server (`analysis.auto_create_points`).

## 4. Format data yang dikirim ESP32
Geolistrik → `/measurements`:
```json
{"point":"GL-024","config":"wenner","spacing":5,"dv_mv":12.4,"i_ma":9.8,"device":"AQ-ESP32-01","ts":1760000000,"rssi":-58,"battery":62}
```
Air → `/water`:
```json
{"point":"KA-001","ph":7.2,"tds":184,"ec":312,"temp":26.4,"turbidity_ntu":4.8,"device":"AQ-ESP32-01","ts":1760000000,"rssi":-58,"battery":62}
```
`battery` (persen) dan `rssi` (dBm) bersifat opsional; bila ada, tampil di **Status Perangkat**, dan baterai ≤25% memunculkan notifikasi. Contoh di atas hanya bentuk pesan, bukan hasil pengukuran. `ts` adalah epoch detik UTC.

## 5. Dari Firebase ke potensi air tanah (menu Pengukuran Tahanan Tanah)
Buka menu **Pengukuran Tahanan Tanah**, pilih titik, lalu tekan **Tarik data & analisis potensi air**. Aquify menarik data baru dari Firebase ke database, mengolahnya menjadi profil lapisan, lalu AI menganalisis dan menampilkan potensi air tanah serta rekomendasi bor. Titik harus punya minimal 3 jarak elektroda berbeda.

## 6. Kapan web menarik data otomatis?
| Kondisi | Frekuensi |
|---|---|
| Ada halaman Aquify terbuka (Beranda, dll.) | tiap `auto_interval_sec` (60 dtk) |
| Halaman **Data Live** atau **Cek Kualitas Air** terbuka | tiap `live_interval_sec` (8 dtk) |
| Tidak ada yang membuka web | **tidak menarik**; data tetap aman di Firebase dan ditarik saat web dibuka |

Untuk menarik meski web tertutup, jadwalkan pemanggilan `https://DOMAINMU/api/sync.php?token=TOKEN` tiap 1–5 menit lewat cron-job.org atau cron hosting. Sebagian hosting gratis memblokir cron/bot.

## 7. Pemecahan masalah
- **Login Firebase gagal (HTTP 400)**: email/sandi akun perangkat atau API Key salah; pastikan Email/Password aktif.
- **POST HTTP 401**: token kedaluwarsa (sketsa login ulang otomatis) atau rules belum dipublikasikan.
- **Web menampilkan "Firebase menolak akses"**: `firebase.secret` kosong/salah.
- **Data masuk Firebase tapi tidak ke web**: cek **Sinkron Firebase** (riwayat & galat). Payload tidak valid (mis. `spacing` hilang) dicatat sebagai *failed* di tabel `firebase_events`.
- **Waktu data salah**: ESP32 belum sinkron NTP; sketsa lalu tidak mengirim `ts`, dan server memakai waktu terima.

## Alternatif: kirim langsung tanpa Firebase
`POST https://DOMAINMU/api/ingest.php` dengan header `X-Api-Token: <ingest.token>` dan body JSON yang sama. Sketsa ini tidak memakai jalur tersebut.

## Catatan keamanan
Sketsa memakai `setInsecure()` (TLS tanpa verifikasi sertifikat) agar mudah dijalankan. Untuk produksi, pasang sertifikat root dengan `WiFiClientSecure::setCACert()`.
