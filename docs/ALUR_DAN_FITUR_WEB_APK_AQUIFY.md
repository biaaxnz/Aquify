# Alur dan Fitur Website serta APK Aquify

## 1. Hubungan website dan APK

Website adalah antarmuka utama Aquify. APK Android saat ini membuka website tersebut di dalam WebView, sehingga sebagian besar halaman dan fitur yang terlihat sama. APK menambahkan beberapa kemampuan Android, seperti tarik layar untuk memuat ulang dan penanganan unduhan PDF ke penyimpanan perangkat.

Ringkasnya:

- **Website:** dibuka melalui browser di komputer atau ponsel.
- **APK:** website Aquify yang dibuka melalui aplikasi Android, dengan beberapa jembatan fitur native.
- **Data akun dan survei:** pada versi terbaru disimpan melalui Supabase.
- **Versi PHP/MySQL di folder utama:** implementasi lama yang terpisah dari website Next.js/Supabase.

APK bukan aplikasi native dengan semua halaman dibuat ulang khusus Android. Perubahan pada website dapat memengaruhi isi yang tampil di APK, sedangkan perubahan native Android memerlukan APK baru untuk dipasang.

## 2. Alur utama pengguna

### A. Masuk dan membuka ruang kerja

1. Pengguna membuka website atau APK.
2. Jika belum memiliki sesi, pengguna masuk atau mendaftar.
   Jika lupa kata sandi, pilih **Lupa kata sandi?** di halaman masuk untuk meminta tautan pemulihan melalui email.
3. Setelah berhasil masuk, pengguna diarahkan ke **Beranda**.
4. Beranda menampilkan ringkasan titik survei, pengukuran geolistrik, dan sampel air milik akun tersebut, serta pintasan ke fitur utama.

### B. Membuat titik survei

1. Pilih **Survey Baru**.
2. Isi nama lokasi atau proyek.
3. Pilih jenis survei: **Geolistrik** atau **Kualitas air**.
4. Jika tersedia, pilih atau masukkan koordinat lokasi.
5. Simpan titik survei.

Titik survei menjadi pengelompokan agar data geolistrik, sampel air, analisis, dan laporan dapat dikaitkan ke lokasi yang benar.

### C. Mencatat pengukuran geolistrik

1. Buka **Pengukuran Tahanan Tanah**.
2. Pilih titik survei.
3. Pilih konfigurasi **Wenner** atau **Schlumberger**.
4. Masukkan spasi elektroda, tegangan, dan arus; untuk konfigurasi Schlumberger, masukkan pula jarak MN.
5. Posisi sepanjang lintasan (x) diperlukan untuk memetakan setiap pengukuran pada penampang 2D. Isi dengan jarak posisi yang konsisten, misalnya 0 m, 5 m, dan 10 m.
6. Simpan pengukuran. Sistem menghitung resistivitas semu dari parameter tersebut.

Pada formulir web yang tersedia, data pengukuran dapat dimasukkan secara manual. Pengiriman otomatis dari ESP32 hanya berlaku jika perangkat, firmware, token, koneksi, dan endpoint sudah disiapkan serta diuji.

**Batas analisis:** sounding memerlukan minimal tiga jarak elektroda yang berbeda. Tiga pengulangan pada satu jarak tidak menggantikan tiga jarak berbeda. Kerangka penampang 2D tetap terlihat, tetapi interpolasinya baru terbentuk setelah tersedia sekurangnya tiga posisi lintasan dan dua jarak elektroda berbeda, disertai posisi x untuk pengukuran. Sebelum itu, aplikasi hanya menampilkan kerangka dan titik ukur berposisi yang tersedia, tanpa mengarang interpolasi. Penampang yang terbentuk adalah visualisasi indikatif, bukan inversi geofisika.

### D. Mencatat sampel kualitas air

1. Buka **Cek Kualitas Air**.
2. Pilih titik survei.
3. Isi satu atau lebih parameter yang tersedia: pH, TDS, EC, suhu, dan kekeruhan.
4. Simpan sampel.
5. Tinjau hasil dan indikator skrining yang ditampilkan.

Arti dan kegunaan parameternya:

- **pH** menunjukkan tingkat asam atau basa air pada skala 0–14. Nilai ini membantu melihat perubahan sifat kimia air; pH saja tidak menunjukkan apakah air aman diminum. Biasanya diukur dengan pH meter atau kertas indikator.
- **TDS (Total Dissolved Solids)** memperkirakan jumlah total zat yang terlarut, misalnya mineral dan garam, biasanya dalam ppm. Nilai tinggi dapat menunjukkan banyaknya zat terlarut atau rasa air yang berubah, tetapi TDS tidak mengidentifikasi zat apa yang ada dan tidak mendeteksi semua kontaminan. Umumnya dibaca dengan TDS meter.
- **EC (Electrical Conductivity / daya hantar listrik)** menunjukkan kemampuan air menghantarkan listrik, biasanya dalam µS/cm. Air dengan lebih banyak ion terlarut umumnya memiliki EC lebih tinggi. EC sering dipakai sebagai petunjuk jumlah ion terlarut, tetapi bukan pengukuran langsung semua zat berbahaya. Biasanya diukur dengan EC meter.
- **Suhu** menunjukkan tingkat panas atau dingin sampel, dalam °C. Suhu berguna untuk mencatat kondisi sampel dan menafsirkan pengukuran lain karena suhu dapat memengaruhi pembacaan EC. Biasanya diukur dengan termometer atau sensor suhu.
- **Kekeruhan (turbidity)** menunjukkan seberapa banyak partikel halus yang membuat air tampak keruh, biasanya dalam NTU. Nilai ini berguna untuk memantau perubahan kejernihan, tetapi air yang tampak jernih tetap bisa mengandung mikroorganisme atau zat kimia. Pengukuran yang tepat memakai turbidimeter.

TDS dan EC berhubungan tetapi bukan parameter yang sama. Sebagian TDS meter memperkirakan TDS dari EC dengan faktor konversi, sehingga hasilnya dapat berbeda antar alat dan jenis air. Jika alat uji tidak tersedia, jangan mengarang nilai; catat hanya parameter yang benar-benar diukur.

Parameter yang dimasukkan lewat formulir merupakan input manual, kecuali pengguna memang menggunakan dan telah menguji perangkat sensor yang mengirimkan data. Penilaian di aplikasi bersifat indikatif dan bukan sertifikasi air layak minum; hasil penting perlu dikonfirmasi melalui laboratorium.

### E. Melihat peta dan riwayat

- **Peta Potensi Air:** menampilkan titik survei yang memiliki koordinat, beserta ringkasan hasil analisis jika ada.
- **Riwayat Survey:** membantu menemukan kembali titik dan data survei.
- **Detail Survey:** menampilkan pengukuran geolistrik, sampel air, analisis yang tersimpan, visualisasi, dan tombol unduh laporan untuk titik tersebut.

### F. Menjalankan dan memahami analisis

1. Buka **Analisa AI** dan pilih titik geolistrik.
2. Jalankan **analisis aturan** untuk memperoleh ringkasan indikatif berdasarkan data yang tersimpan.
3. Tinjau skor, kesimpulan, perkiraan target, kurva sounding, indikasi lapisan, dan peringatan jika tersedia.
4. Jika Gemini sudah dikonfigurasi, jalankan atau tinjau interpretasi Gemini sebagai penjelasan tambahan.

Analisis aturan dan Gemini berbeda:

- **Analisis aturan** menerapkan perhitungan dan aturan yang ditulis dalam aplikasi.
- **Gemini** menghasilkan interpretasi dalam bentuk teks menggunakan layanan AI eksternal.

Keduanya bukan jaminan keberadaan air atau rekomendasi pengeboran final. Interpretasi harus dilihat bersama kelengkapan data dan kondisi lapangan.

### G. Mengunduh laporan PDF

1. Buka **Detail Survey**.
2. Pilih **Unduh laporan PDF**.
3. Laporan berisi ringkasan survei, data yang tersedia, analisis/kesimpulan, interpretasi Gemini jika ada, dan visualisasi yang memenuhi syarat data.

Di browser, file mengikuti mekanisme unduhan browser. Pada APK terbaru, aplikasi Android menangani unduhan dan berusaha menyimpan PDF ke **Download/Aquify**; notifikasi berhasil seharusnya muncul setelah file dipublikasikan dan diperiksa sebagai PDF. Fitur ini bergantung pada APK terbaru sudah dipasang. Jika APK lama masih terpasang, perilakunya belum mencakup perubahan tersebut.

### H. Notifikasi dan pengaturan

- **Notifikasi:** menyimpan pemberitahuan di dalam aplikasi, misalnya status laporan yang disiapkan atau berhasil diunduh. Status unduhan browser dan unduhan native APK dapat berbeda karena konfirmasi penyimpanannya berbeda.
- **Pengaturan:** menampilkan informasi akun dan status konfigurasi layanan yang digunakan.
- **Status Perangkat** dan **Sinkron Firebase:** menampilkan atau mengelola alur data perangkat/integrasi apabila sudah dikonfigurasi.
- **Simulasi Integrasi:** menu admin berupa pratinjau simulasi; bukan bukti bahwa ESP32, Firebase, atau Gemini sedang benar-benar terhubung.

## 3. Fitur yang sama di website dan APK

Karena APK memuat website Aquify, fitur berikut pada dasarnya memakai halaman web yang sama:

- Masuk dan pendaftaran akun.
- Beranda dan pintasan fitur.
- Membuat titik survei serta memilih koordinat jika dibutuhkan.
- Input manual pengukuran geolistrik.
- Input manual sampel kualitas air.
- Peta, riwayat, dan detail survei.
- Analisis aturan, visualisasi, dan interpretasi Gemini jika layanan tersedia.
- Laporan PDF dan notifikasi dalam aplikasi web.
- Pengaturan akun serta halaman status integrasi.

Ketersediaan layanan eksternal tetap bergantung pada konfigurasi server dan koneksi internet.

## 4. Fitur khusus atau perilaku tambahan APK

- Membuka website Aquify di dalam aplikasi Android.
- Tarik layar ke bawah untuk memuat ulang halaman.
- Menggunakan jembatan Android untuk mengunduh PDF melalui DownloadManager.
- Pada APK Android terbaru, menyimpan salinan PDF yang telah diverifikasi ke **Download/Aquify** dan baru menyatakan berhasil sesudah penyimpanan tersebut sukses.

Jika APK dibagikan atau dipasang ulang, gunakan file APK hasil build terbaru. Mengubah source code Android Studio saja tidak memperbarui aplikasi yang sudah terpasang di ponsel.

## 5. Hal yang jangan disalahartikan saat demo

1. **Jangan menyebut website dan APK sebagai dua sistem data terpisah.** APK memuat website yang sama; tambahan tertentu ditangani Android.
2. **Jangan menyebut input manual sebagai pembacaan otomatis ESP32.** Tunjukkan perangkat nyata dan bukti pengiriman hanya jika integrasinya benar-benar telah diuji.
3. **Jangan menyebut tampilan simulasi sebagai koneksi aktif.** Simulasi Integrasi adalah pratinjau.
4. **Jangan menyebut kurva atau penampang sebagai hasil inversi geofisika.** Penampang merupakan interpolasi indikatif.
5. **Jangan mengatakan satu pengukuran cukup untuk analisis sounding.** Data perlu mencakup sedikitnya tiga jarak elektroda berbeda.
6. **Jangan menyebut skor sebagai peluang pasti menemukan air.** Skor bukan probabilitas.
7. **Jangan menyebut hasil skrining kualitas air sebagai jaminan aman diminum.** Gunakan uji laboratorium untuk konfirmasi.
8. **Jangan menyebut Supabase sebagai tempat source code atau GitHub sebagai tempat database operasional.** GitHub menyimpan kode; Supabase menyimpan autentikasi dan data aplikasi versi terbaru.
9. **Jangan menyebut versi PHP/MySQL dan Next.js/Supabase sebagai satu backend yang sama.** Keduanya masih merupakan implementasi terpisah.

## 6. Naskah demo singkat

> “Aquify dapat dibuka melalui website maupun APK Android. APK saat ini memuat website yang sama. Akan tetapi, pada APK dapat diakses melalui android sedangkan website dapat diakses baik dari browser, laptop maupun perangkat lainnya.
>
> Saya mulai dari Beranda, yang merangkum titik survei, pengukuran, dan sampel air. Berikutnya saya membuat atau membuka titik survei, karena titik tersebut digunakan untuk mengelompokkan data pada lokasi yang sama.
>
> Pada menu geolistrik, pengguna dapat memasukkan konfigurasi, spasi elektroda, tegangan, dan arus. Untuk saat ini, alur yang saya tunjukkan menggunakan input manual. Analisis sounding memerlukan setidaknya tiga jarak elektroda yang berbeda supaya bisa melihat pola awal resistivitas terhadap perubahan jarak. Dengan hanya satu atau dua jarak, datanya terlalu sedikit untuk menggambarkan kurva sounding dan membandingkan kecenderungan lapisan.
>
> Pengguna juga dapat mencatat parameter sampel air, melihat peta dan riwayat, menjalankan analisis aturan, serta meninjau interpretasi Gemini bila layanan sudah dikonfigurasi. Hasil visualisasi dan analisis bersifat indikatif dan perlu validasi lapangan.
>
> Terakhir, laporan PDF dapat diunduh dari detail survei. Pada APK versi terbaru, file disimpan ke folder Download/Aquify setelah penyimpanan berhasil diverifikasi. Aquify membantu merapikan pencatatan dan peninjauan data, bukan menggantikan tenaga ahli maupun uji laboratorium.”
