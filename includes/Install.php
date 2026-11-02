<?php

namespace Texty;

use Texty\Models\SmsStat;
use Texty\Models\SmsStatStore;
use Texty\Dependencies\WeDevs\WPKit\DataLayer\DataLayerFactory;

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

        // dbDelta() format: no "IF NOT EXISTS" (its CREATE TABLE regex would
        // read the table name as "IF" and never diff the real table), one
        // definition per line, and "PRIMARY KEY  (id)" with two spaces.
        //
        // Indexes follow the read paths:
        // - created_at_status_idx       dashboard metrics (range on created_at, count by status)
        //                               and the Logs "All" tab (ORDER BY created_at)
        // - status_created_at_idx       Logs status tabs (WHERE status ORDER BY created_at)
        // - notification_id_created_idx Logs type filter (WHERE notification_id ORDER BY created_at)
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            receiver varchar(20) NOT NULL,
            gateway varchar(50) NOT NULL,
            status varchar(20) DEFAULT NULL,
            notification_id varchar(64) DEFAULT NULL,
            notification_group varchar(32) DEFAULT NULL,
            message text NULL,
            response longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            reference_id varchar(100) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at_status_idx (created_at,status),
            KEY status_created_at_idx (status,created_at),
            KEY notification_id_created_idx (notification_id,created_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }
}
