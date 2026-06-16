<?php

namespace Texty;

use Texty\Models\SmsStat;
use Texty\Models\SmsStatStore;
use WeDevs\WPKit\DataLayer\DataLayerFactory;

/**
 * Installer Class
 */
class Install {

    /**
     * Run the isntaller
     */
    public function run() {
        $this->create_tables();
        $installed = get_option( 'texty_installed' );

        if ( ! $installed ) {
            update_option( 'texty_installed', time() );
        }

        update_option( 'texty_version', TEXTY_VERSION );

        // Seed the schema version so the migration runner skips migrations
        // whose changes are already baked into create_tables() above. The
        // upgrade path on existing installs (where this option is missing or
        // older) is handled by Texty\Migrations on plugins_loaded.
        if ( ! get_option( 'texty_db_version' ) ) {
            update_option( 'texty_db_version', TEXTY_VERSION );
        }
    }

    /**
     * Create database tables
     *
     * @return void
     */
    public function create_tables() {
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
