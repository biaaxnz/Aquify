-- Aquify: skema database (MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4)
-- Dijalankan otomatis oleh install.php. Aman dijalankan ulang (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NULL,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_points_owner (owner_id),
  CONSTRAINT fk_points_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS measurements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fb_key VARCHAR(120) NOT NULL UNIQUE,
  point_id INT UNSIGNED NULL,
  device_id VARCHAR(60) NULL,
  config ENUM('wenner','schlumberger') NOT NULL DEFAULT 'wenner',
  spacing_m DECIMAL(8,2) NOT NULL,
  mn_m DECIMAL(8,3) NULL,
  dv_mv DOUBLE NOT NULL DEFAULT 0,
  i_ma DOUBLE NOT NULL DEFAULT 0,
  rho_a DOUBLE NOT NULL,
  measured_at DATETIME NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'firebase',
  raw_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_point_spacing (point_id, spacing_m),
  KEY idx_measured (measured_at),
  CONSTRAINT fk_meas_point FOREIGN KEY (point_id) REFERENCES points(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS water_samples (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NULL,
  fb_key VARCHAR(120) NOT NULL UNIQUE,
  point_id INT UNSIGNED NULL,
  device_id VARCHAR(60) NULL,
  tds_ppm DOUBLE NULL,
  ec_us DOUBLE NULL,
  ph DOUBLE NULL,
  temp_c DOUBLE NULL,
  turbidity_ntu DOUBLE NULL,
  measured_at DATETIME NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'firebase',
  raw_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wpoint (point_id, measured_at),
  KEY idx_water_owner (owner_id),
  CONSTRAINT fk_water_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_water_point FOREIGN KEY (point_id) REFERENCES points(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analyses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  point_id INT UNSIGNED NOT NULL,
  mode ENUM('rule','ai') NOT NULL DEFAULT 'rule',
  score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  verdict VARCHAR(40) NOT NULL DEFAULT '',
  best_depth DOUBLE NULL,
  best_rho DOUBLE NULL,
  result_json MEDIUMTEXT NULL,
  ai_text MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_apoint (point_id, id),
  CONSTRAINT fk_an_point FOREIGN KEY (point_id) REFERENCES points(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_state (
  state_key VARCHAR(60) NOT NULL PRIMARY KEY,
  state_value VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  source VARCHAR(20) NOT NULL,
  started_at DATETIME NOT NULL,
  fetched INT NOT NULL DEFAULT 0,
  inserted INT NOT NULL DEFAULT 0,
  duplicate INT NOT NULL DEFAULT 0,
  invalid INT NOT NULL DEFAULT 0,
  error VARCHAR(500) NULL,
  KEY idx_sync_user (user_id, id),
  CONSTRAINT fk_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data mentah yang diterima dari Firebase; menyimpan payload asli untuk audit/reproses.
CREATE TABLE IF NOT EXISTS firebase_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(190) NOT NULL,
  data_path VARCHAR(255) NOT NULL,
  device_id VARCHAR(100) NULL,
  event_type VARCHAR(40) NULL,
  event_timestamp DATETIME NULL,
  payload_json LONGTEXT NOT NULL,
  sync_status ENUM('received','processed','failed') NOT NULL DEFAULT 'received',
  error_message VARCHAR(1000) NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_firebase_event_path (data_path, event_key),
  KEY idx_fb_received (received_at),
  KEY idx_fb_device_time (device_id, event_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pembacaan serial langsung dari ESP32 melalui aplikasi Python/Streamlit.
CREATE TABLE IF NOT EXISTS sensor_readings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reading_uuid CHAR(36) NULL UNIQUE,
  device_id VARCHAR(100) NULL,
  point_id INT UNSIGNED NULL,
  reading_type ENUM('water','geoelectric','other') NOT NULL DEFAULT 'other',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  voltage_mv DOUBLE NULL,
  current_ma DOUBLE NULL,
  electrode_spacing_m DOUBLE NULL,
  mn_m DOUBLE NULL,
  k_factor_m DOUBLE NULL,
  apparent_resistivity_ohm_m DOUBLE NULL,
  ph DOUBLE NULL,
  tds_ppm DOUBLE NULL,
  ec_us DOUBLE NULL,
  temp_c DOUBLE NULL,
  turbidity_ntu DOUBLE NULL,
  measured_at DATETIME NULL,
  source ENUM('esp32_serial','firebase','manual','import') NOT NULL DEFAULT 'esp32_serial',
  raw_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sr_point_time (point_id, measured_at),
  KEY idx_sr_device_time (device_id, measured_at),
  CONSTRAINT fk_sensor_reading_point FOREIGN KEY (point_id) REFERENCES points(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Riwayat aktivitas penting dari dashboard, sinkronisasi, dan proses analisis.
CREATE TABLE IF NOT EXISTS activity_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id VARCHAR(100) NULL,
  status ENUM('success','warning','failed','info') NOT NULL DEFAULT 'info',
  details_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activity_created (created_at),
  KEY idx_activity_entity (entity_type, entity_id),
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Menyimpan permintaan dan jawaban AI secara terpisah agar prompt/hasil dapat diaudit.
CREATE TABLE IF NOT EXISTS ai_analysis_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  analysis_id INT UNSIGNED NULL,
  point_id INT UNSIGNED NULL,
  provider VARCHAR(60) NOT NULL DEFAULT 'gemini',
  model VARCHAR(120) NULL,
  prompt_text MEDIUMTEXT NULL,
  response_text MEDIUMTEXT NULL,
  input_json LONGTEXT NULL,
  output_json LONGTEXT NULL,
  status ENUM('success','failed','skipped') NOT NULL DEFAULT 'success',
  error_message VARCHAR(1000) NULL,
  duration_ms INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_point_created (point_id, created_at),
  KEY idx_ai_analysis (analysis_id),
  CONSTRAINT fk_ai_log_analysis FOREIGN KEY (analysis_id) REFERENCES analyses(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_log_point FOREIGN KEY (point_id) REFERENCES points(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
