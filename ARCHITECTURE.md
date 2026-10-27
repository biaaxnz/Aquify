# Arsitektur Aquify

- **UI:** PHP + HTML, CSS, JavaScript.
- **Database utama:** MySQL/MariaDB database `aquify`.
- **Firebase:** sinkronisasi PHP mengambil data dan menyimpan payload mentah serta rekaman terstruktur.
- **ESP32 USB/Serial:** browser Chrome/Edge Web Serial API → endpoint `api/serial-save.php` → MySQL.
- **AI:** backend PHP memanggil Gemini jika dikonfigurasi; hasil dan log disimpan di MySQL.

Pembacaan serial mentah disimpan ke `sensor_readings`. Payload geolistrik/kualitas air yang valid juga disimpan ke `measurements`/`water_samples`. Firebase sync tidak dijalankan sebagai listener permanen di server; halaman sinkronisasi atau pemanggilan terjadwal diperlukan.
