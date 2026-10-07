<?php
/**
 * Lightweight, idempotent schema upgrades for installs that imported an older
 * database.sql. Runs at most once per version (tracked in settings.system.schema_version).
 * cPanel DB users have ALTER privileges on their own database, so this works on shared hosting.
 */

require_once __DIR__ . '/functions.php';

class Schema {
    const VERSION = 1;

    public static function migrate() {
        $current = (int)getSetting('system', 'schema_version', 0);
        if ($current >= self::VERSION) {
            return;
        }
        $db = db();
        try {
            if ($current < 1) {
                if (!$db->fetchOne("SHOW COLUMNS FROM payments LIKE 'plan_id'")) {
                    $db->execute("ALTER TABLE payments ADD COLUMN plan_id INT UNSIGNED NULL AFTER user_id, ADD KEY plan_id (plan_id)");
                }
            }
            saveSetting('system', 'schema_version', self::VERSION, 'INTEGER');
        } catch (Exception $e) {
            error_log('Schema migration failed: ' . $e->getMessage());
        }
    }
}
