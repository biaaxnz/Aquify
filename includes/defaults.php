<?php
// Nilai bawaan. Nilai di config.php menimpa ini.
function aq_defaults(): array {
    return [
        'app' => ['name' => 'Aquify'],
        'db' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''],
        'firebase' => [
            'db_url' => '',            // contoh: https://nama-proyek-default-rtdb.firebaseio.com
            'secret' => '',            // opsional: database secret / token akses baca
            'paths' => ['measurements' => 'measurements', 'water' => 'water'],
            'batch' => 200,
            'max_batches' => 10,
        ],
        'sync' => ['auto_interval_sec' => 60, 'token' => ''],
        'ingest' => ['token' => ''],
        'gemini' => ['api_key' => '', 'model' => 'gemini-3.6-flash'],
        'analysis' => ['depth_factor' => 0.5, 'auto_create_points' => true],
    ];
}
