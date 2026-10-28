<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$configPath = $root . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.php Aquify lama tidak ditemukan.\n");
    exit(1);
}

$config = require $configPath;
$db = $config['db'] ?? [];
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'] ?? 'localhost',
        (int)($db['port'] ?? 3306),
        $db['name'] ?? 'aquify',
    );
    $pdo = new PDO($dsn, $db['user'] ?? 'root', $db['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $users = $pdo->query('SELECT id, email, full_name FROM users')->fetchAll();
    $userById = [];
    foreach ($users as $user) {
        $userById[(string)$user['id']] = $user;
    }

    $points = $pdo->query(
        'SELECT id, owner_id, code, name, survey_type, lat, lng, notes, team, plan_json, created_at FROM points ORDER BY id',
    )->fetchAll();
    $owners = [];
    foreach ($points as $point) {
        $ownerId = (string)($point['owner_id'] ?? '');
        $owner = $userById[$ownerId] ?? null;
        if (!$owner || !filter_var($owner['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ada titik tanpa akun pemilik dengan email valid. Pemindahan dibatalkan.');
        }
        $owners[$ownerId] = [
            'legacy_id' => $ownerId,
            'email' => strtolower(trim($owner['email'])),
            'full_name' => $owner['full_name'] ?? '',
        ];
    }

    foreach ($pdo->query('SELECT owner_id, point_id FROM water_samples')->fetchAll() as $sample) {
        $ownerId = (string)($sample['owner_id'] ?? '');
        if ($ownerId === '' && $sample['point_id'] !== null) {
            $pointOwner = $pdo->prepare('SELECT owner_id FROM points WHERE id = ?');
            $pointOwner->execute([(int)$sample['point_id']]);
            $ownerId = (string)$pointOwner->fetchColumn();
        }
        $owner = $userById[$ownerId] ?? null;
        if (!$owner || !filter_var($owner['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ada sampel air tanpa akun pemilik dengan email valid. Pemindahan dibatalkan.');
        }
        $owners[$ownerId] = [
            'legacy_id' => $ownerId,
            'email' => strtolower(trim($owner['email'])),
            'full_name' => $owner['full_name'] ?? '',
        ];
    }

    $measurements = $pdo->query(
        'SELECT id, fb_key, point_id, device_id, config, spacing_m, mn_m, dv_mv, i_ma, rho_a, x_m, measured_at, created_at, source, raw_json FROM measurements ORDER BY id',
    )->fetchAll();
    $water = $pdo->query(
        'SELECT id, fb_key, point_id, owner_id, device_id, tds_ppm, ec_us, ph, temp_c, turbidity_ntu, measured_at, created_at, source, raw_json, notes FROM water_samples ORDER BY id',
    )->fetchAll();
    $analyses = $pdo->query(
        'SELECT id, point_id, mode, score, verdict, best_depth, best_rho, result_json, ai_text, created_at FROM analyses ORDER BY id',
    )->fetchAll();
    $sensorReadings = $pdo->query(
        'SELECT id, reading_uuid, device_id, point_id, reading_type, latitude, longitude, voltage_mv, current_ma, electrode_spacing_m, mn_m, k_factor_m, apparent_resistivity_ohm_m, ph, tds_ppm, ec_us, temp_c, turbidity_ntu, measured_at, source, raw_json FROM sensor_readings ORDER BY id',
    )->fetchAll();

    echo json_encode([
        'owners' => array_values($owners),
        'points' => $points,
        'measurements' => $measurements,
        'water_samples' => $water,
        'analyses' => $analyses,
        'sensor_readings' => $sensorReadings,
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    fwrite(STDERR, "Gagal membaca database MySQL. Periksa MySQL XAMPP dan konfigurasi database Aquify lama.\n");
    exit(1);
}
