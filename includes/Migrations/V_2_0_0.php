<?php
/**
 * 2.0.0 — adds notification context + message + response columns to
 * `wp_texty_sms_stat` and the indexes the SMS Logs UI queries against.
 *
 * @package Texty\Migrations
 */

namespace Texty\Migrations;

/**
 * Schema migration for the SMS Logs feature.
 *
 * Each public static method below is auto-discovered and executed by
 * BaseMigration::run() in declaration order. dbDelta is idempotent — adding
 * columns/indexes that already exist is a no-op, so re-runs are safe.
 */
class V_2_0_0 extends TextyMigration {

    /**
     * Add the four new columns and three indexes to `wp_texty_sms_stat`.
     *
     * - notification_id    — which notification class fired the SMS
     * - notification_group — `wp` / `wc` / `dokan` / custom
     * - message            — final, token-replaced body (for "View Log")
     * - response           — JSON-encoded gateway result / WP_Error payload
     *
     * @return void
     */
    public static function add_logs_columns(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'texty_sms_stat';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receiver VARCHAR(20) NOT NULL,
            gateway VARCHAR(50) NOT NULL,
            status VARCHAR(20) DEFAULT NULL,
            notification_id VARCHAR(64) DEFAULT NULL,
            notification_group VARCHAR(32) DEFAULT NULL,
            message TEXT NULL,
            response LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            reference_id VARCHAR(100) DEFAULT NULL,
            KEY created_at_idx (created_at),
            KEY notification_id_idx (notification_id),
            KEY status_idx (status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }
}
