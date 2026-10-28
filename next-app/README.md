# Aquify Next.js (tanpa PHP)

Versi baru ini berjalan sebagai aplikasi Next.js + TypeScript di Vercel dan memakai Supabase untuk autentikasi serta PostgreSQL. Folder PHP di root belum dihapus; database XAMPP lama tetap menjadi cadangan sampai migrasi selesai dan diverifikasi.

## Status

Sudah tersedia: peta Leaflet dengan filter dan pemilih koordinat, kurva sounding, penampang 2D hasil interpolasi untuk pengukuran yang memiliki posisi lintasan, analisis aturan indikatif, integrasi Gemini opsional, sinkron Firebase Realtime Database opsional, dan API ingest ESP32 opsional. Fitur yang membutuhkan layanan eksternal baru aktif setelah konfigurasi server dan migrasi SQL dijalankan.

Penampang 2D adalah pseudosection interpolasi data ukur, bukan inversi resistivitas 2D. Analisis aturan dan Gemini hanya menghasilkan interpretasi indikatif, bukan jaminan air atau rekomendasi pengeboran. Notifikasi push, serial USB/Web Serial, OTP khusus, dan migrasi data MySQL belum dipindahkan. Jangan hapus versi PHP/database lama sebelum migrasi dan pengujian operasional selesai.

## Prasyarat dan konfigurasi

1. Pasang Node.js LTS.
2. Buat proyek Supabase.
3. Di Supabase SQL Editor jalankan `supabase/schema.sql`. Jika schema dasar sudah pernah dijalankan, jalankan `supabase/migrations/20261028_feature_support.sql` sekali.
4. Salin `.env.example` menjadi `.env.local`, lalu isi URL proyek dan publishable key dari Supabase. Nama variabel `NEXT_PUBLIC_SUPABASE_ANON_KEY` lama juga didukung. Jangan pernah menaruh secret/service-role key di variabel `NEXT_PUBLIC_*`.
5. Jalankan:

   ```powershell
   npm install
   npm run dev
   ```

6. Buka `http://localhost:3000`. Untuk akun baru, ikuti pengaturan konfirmasi email di Supabase Auth.

Untuk menjadikan akun pertama sebagai admin, daftar dan konfirmasi akun terlebih dahulu. Lalu, sebagai pemilik proyek, jalankan di Supabase SQL Editor:

```sql
update public.profiles
set role = 'admin'
where id = (select id from auth.users where email = 'email-admin-kamu@example.com');
```

Role tidak dapat diubah lewat sesi pengguna biasa.

Admin memiliki menu **Simulasi Integrasi** di `/admin/simulation` untuk melihat pratinjau tampilan ESP32, Firebase, Gemini, dan sensor kualitas air. Simulasi ini hanya memakai angka buatan di browser; tidak menghubungi layanan eksternal dan tidak menyimpan data ke riwayat survey. Status konfigurasi yang terlihat hanya menunjukkan apakah variabel server tersedia, bukan apakah koneksi eksternal berhasil diuji.

## Deploy ke Vercel

Impor repo GitHub ke Vercel dan set **Root Directory** menjadi `next-app`. Tambahkan `NEXT_PUBLIC_SUPABASE_URL` dan `NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY` di Environment Variables, lalu deploy. URL callback/redirect Auth harus mencakup domain lokal dan domain Vercel.

Endpoint pemeriksaan awal: `/api/mobile/ping`.

### Integrasi opsional

- **Gemini:** isi `GEMINI_API_KEY` dan `GEMINI_MODEL` di environment server (Vercel Environment Variables untuk deployment). Jangan mengirim kunci melalui chat atau browser.
- **Firebase Realtime Database:** isi `FIREBASE_DATABASE_URL`, opsional `FIREBASE_DATABASE_TOKEN`, `FIREBASE_MEASUREMENTS_PATH`, dan `FIREBASE_WATER_PATH`. Sinkron manual tersedia di menu Sinkron. Baris Firebase harus merujuk ke kode titik Aquify milik akun yang sedang masuk; baris tanpa titik yang cocok dihitung tidak valid.
- **ESP32:** isi `SUPABASE_SERVICE_ROLE_KEY` dan `ESP32_INGEST_TOKEN` hanya di environment server. Kirim HTTPS POST ke `/api/device/ingest` dengan header `Authorization: Bearer <token>` dan objek `{"point":"KODE-TITIK","device":"ESP32-01","measurements":[{"spacing_m":1,"dv_mv":22.4,"i_ma":2.1,"x_m":5}]}`. Payload menerima hingga 200 baris; kirim titik yang sudah terdaftar. Respons JSON berisi jumlah diterima, disimpan, duplikat, dan tidak valid.
- Setelah mengubah `.env.local`, hentikan lalu jalankan ulang `npm run dev`. Untuk Vercel, tambahkan variabel yang sama pada pengaturan proyek lalu redeploy.

## Data lama

Untuk memindahkan data MySQL XAMPP secara aman:

1. Pastikan MySQL XAMPP menyala, `config.php` versi PHP lama masih terhubung, dan akun Supabase sudah dibuat dengan email yang sama dengan pemilik data lama.
2. Di Supabase, buka **Project Settings → API Keys** dan salin service-role/secret key ke `SUPABASE_SERVICE_ROLE_KEY` pada `next-app/.env.local`. Jangan mengirim atau membagikan kunci ini; script hanya membacanya secara lokal.
3. Dari folder `next-app`, jalankan dulu pemeriksaan yang tidak menulis data:

   ```powershell
   npm.cmd run migrate:mysql -- --dry-run
   ```

4. Jika jumlahnya benar, jalankan pemindahan:

   ```powershell
   npm.cmd run migrate:mysql
   ```

Script memetakan pemilik lama ke akun Supabase berdasarkan email, mengimpor titik, pengukuran, sampel air, analisis, dan pembacaan sensor, serta aman dijalankan ulang untuk melewati data yang sudah terimpor. Password lama tidak dipindahkan. Database MySQL XAMPP tidak dihapus atau diubah; verifikasi hasil di dashboard sebelum mempertimbangkan pengarsipan database lama.
