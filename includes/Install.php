<?php

namespace Texty;

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
        error_log( 'Texty Installer: ' . ( $installed ? 'Already installed' : 'Running installer' ) );

        if ( ! $installed ) {
            update_option( 'texty_installed', time() );
            $this->create_tables();
        }

        update_option( 'texty_version', TEXTY_VERSION );
    }

    /**
     * Create database tables
     *
     * @return void
     */
    private function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        error_log ( "Creating table with charset: {$charset_collate}" );
        $table_name      = $wpdb->prefix . 'texty_sms_stat';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receiver VARCHAR(20) NOT NULL,
            gateway VARCHAR(50) NOT NULL,
            status VARCHAR(20) DEFAULT NULL,
            timestamp DATETIME NOT NULL,
            reference_id VARCHAR(100) DEFAULT NULL
        ) {$charset_collate};";

        dbDelta( $sql );
    }
}
