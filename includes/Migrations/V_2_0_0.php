<?php
/**
 * 2.0.0 — adds notification context + message + response columns to
 * `wp_texty_sms_stat` plus the indexes the SMS Logs UI queries against.
 *
 * @package Texty\Migrations
 */

namespace Texty\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Schema migration for the SMS Logs feature.
 *
 * Public static methods are auto-discovered and executed by
 * `BaseMigration::run()` in declaration order. Schema introspection helpers
 * live on `Schema` (sibling class, not in the migration hierarchy); each
 * step guards on existence so re-runs are safe.
 */
class V_2_0_0 extends TextyMigration {

    /**
     * Add the four new columns to `wp_texty_sms_stat` if missing.
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

        $table = $wpdb->prefix . 'texty_sms_stat';

        if ( ! Schema::table_exists( $table ) ) {
            return;
        }

        $columns = [
            'notification_id'    => 'VARCHAR(64) DEFAULT NULL',
            'notification_group' => 'VARCHAR(32) DEFAULT NULL',
            'message'            => 'TEXT NULL',
            'response'           => 'LONGTEXT NULL',
        ];

        foreach ( $columns as $column => $definition ) {
            if ( Schema::column_exists( $table, $column ) ) {
                continue;
            }

            // Column / type names are hardcoded above; safe to interpolate.
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore
        }
    }

    /**
     * Add the indexes the SMS Logs UI relies on if missing.
     *
     * @return void
     */
    public static function add_logs_indexes(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'texty_sms_stat';

        if ( ! Schema::table_exists( $table ) ) {
            return;
        }

        $indexes = [
            'created_at_idx'      => '(created_at)',
            'notification_id_idx' => '(notification_id)',
            'status_idx'          => '(status)',
        ];

        foreach ( $indexes as $index => $columns ) {
            if ( Schema::index_exists( $table, $index ) ) {
                continue;
            }

            $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `{$index}` {$columns}" ); // phpcs:ignore
        }
    }
}
