<?php
/** Migrasi ringan: menambah kolom baru bila belum ada. Aman dijalankan berulang. */
function aq_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!is_file(AQ_ROOT . '/installed.lock')) return;
    try {
        if (state_get('schema_v') === '4') return;
        $cols = [
            ['users', 'full_name', 'VARCHAR(120) NULL'],
            ['users', 'email', 'VARCHAR(160) NULL'],
            ['users', 'phone', 'VARCHAR(30) NULL'],
            ['points', 'survey_type', "VARCHAR(20) NOT NULL DEFAULT 'geovolt'"],
            ['points', 'team', 'VARCHAR(160) NULL'],
            ['points', 'plan_json', 'TEXT NULL'],
            ['water_samples', 'notes', 'VARCHAR(500) NULL'],
            ['measurements', 'x_m', 'DECIMAL(10,2) NULL'],
            ['points', 'owner_id', 'INT UNSIGNED NULL'],
            ['water_samples', 'owner_id', 'INT UNSIGNED NULL'],
            ['sync_log', 'user_id', 'INT UNSIGNED NULL'],
        ];
        foreach ($cols as [$t, $c, $def]) {
            $has = q_val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$t, $c]);
            if (!(int)$has) pdo()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
        $idx = q_val("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uq_users_email'");
        if (!(int)$idx) pdo()->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
        foreach ([
            ['points', 'idx_points_owner', 'KEY idx_points_owner (owner_id)'],
            ['water_samples', 'idx_water_owner', 'KEY idx_water_owner (owner_id)'],
            ['sync_log', 'idx_sync_user', 'KEY idx_sync_user (user_id, id)'],
        ] as [$table, $name, $definition]) {
            $exists = q_val('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$table, $name]);
            if (!(int)$exists) pdo()->exec("ALTER TABLE `$table` ADD $definition");
        }
        $adminId = q_val("SELECT id FROM users ORDER BY (role='admin') DESC, id ASC LIMIT 1");
        if ($adminId !== null) {
            q('UPDATE points SET owner_id=? WHERE owner_id IS NULL', [(int)$adminId]);
            q('UPDATE water_samples w JOIN points p ON p.id=w.point_id SET w.owner_id=p.owner_id WHERE w.owner_id IS NULL AND p.owner_id IS NOT NULL');
            q('UPDATE water_samples SET owner_id=? WHERE owner_id IS NULL', [(int)$adminId]);
            q('UPDATE sync_log SET user_id=? WHERE user_id IS NULL', [(int)$adminId]);
        }
        state_set('schema_v', '4');
    } catch (Throwable $e) {
        error_log('Aquify schema migration failed: ' . $e->getMessage());
    }
}
