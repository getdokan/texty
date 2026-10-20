<?php
/**
 * 2.0.0 — adds notification context + message + response columns to
 * `wp_texty_sms_stat` plus the indexes the SMS Logs UI queries against.
 *
 * @package Texty\Migrations
 * @since   2.0.0
 */

namespace Texty\Migrations;

use Texty\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Schema migration for the SMS Logs feature.
 *
 * Public static methods are auto-discovered and executed by
 * `BaseMigration::run()` in declaration order. Delegates to
 * `Install::create_tables()` so the canonical schema (columns + indexes)
 * is the single source of truth; `dbDelta` adds anything missing.
 */
class V_2_0_0 extends TextyMigration {

    /**
     * Sync `wp_texty_sms_stat` with the canonical schema.
     *
     * Adds the SMS Logs columns (`notification_id`, `notification_group`,
     * `message`, `response`) and indexes (`created_at_idx`,
     * `notification_id_idx`, `status_idx`) if missing.
     *
     * @return void
     * @since 2.0.0
     */
    public static function sync_logs_schema(): void {
        ( new Install() )->create_tables();
    }
}
