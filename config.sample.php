<?php
// Salin menjadi config.php lalu isi. (Atau cukup jalankan install.php, file ini dibuat otomatis.)
return [
    'db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'aquify', 'user' => 'root', 'pass' => ''],
    'firebase' => [
        'db_url' => 'https://NAMA-PROYEK-default-rtdb.firebaseio.com',
        'secret' => '',
        'paths' => ['measurements' => 'measurements', 'water' => 'water'],
    ],
    'maps' => ['maptiler_key' => ''],                         // kunci MapTiler (peta jalan & satelit); kosong = pakai Esri + OpenStreetMap
    'sync' => ['auto_interval_sec' => 60, 'live_interval_sec' => 8, 'token' => 'ganti-dengan-token-acak'],
    'ingest' => ['token' => 'ganti-dengan-token-acak-lain'],
    'app' => ['name' => 'Aquify', 'region' => ''],            // region: nama wilayah kerja di kartu beranda
    'mail' => ['otp' => false, 'from' => 'no-reply@domainmu.com'], // otp=true: verifikasi email 6 digit via mail() PHP
    'gemini' => ['api_key' => '', 'model' => 'gemini-3.6-flash', 'timeout' => 90, 'provider' => 'auto'], // model lebih kuat: gemini-2.5-pro (lebih lambat). Opsional: 'thinking_budget' => 2048
];
