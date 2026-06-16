<?php
/**
 * Surfaces the "database update required" notice to wp-kit's NoticeManager
 * and runs the upgrade when AdminNotice fires its admin-ajax action.
 *
 * @package Texty\Migrations
 * @since   2.0.0
 */

namespace Texty\Migrations;

use Throwable;
use WeDevs\WPKit\AdminNotification\Contracts\NoticeProviderInterface;
use WeDevs\WPKit\AdminNotification\Notice;

defined( 'ABSPATH' ) || exit;

/**
 * Migration notice provider.
 */
class NoticeProvider implements NoticeProviderInterface {

    /**
     * Ajax action name for the upgrade button.
     */
    public const AJAX_ACTION = 'texty_run_migration';

    /**
     * Constructor — registers the admin-ajax handler that AdminNotice's
     * `ajax_data` body posts to.
     *
     * @since 2.0.0
     */
    public function __construct() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_upgrade' ] );
    }

    /**
     * Return notices to surface in the admin.
     *
     * @return array
     * @since 2.0.0
     */
    public function get_notices(): array {
        $migrations = texty()->migrations();

        if ( ! $migrations->is_upgrade_required() ) {
            return [];
        }

        $registry = $migrations->get_manager()->get_registry();

        $notice = new Notice(
            [
                'key'            => 'texty_migration_required',
                'type'           => 'warning',
                'title'          => __( 'Texty database update required', 'texty' ),
                'description'    => sprintf(
                    /* translators: 1: target plugin version, 2: current DB schema version */
                    __( 'Texty %1$s needs to update your database from version %2$s. The update is safe to re-run and usually finishes in a few seconds.', 'texty' ),
                    esc_html( $registry->get_plugin_version() ),
                    esc_html( $registry->get_db_installed_version() )
                ),
                'priority'       => 5,
                'scope'          => 'local',
                'is_dismissible' => false,
                'actions'        => [
                    [
                        'type'         => 'primary',
                        'text'         => __( 'Update database', 'texty' ),
                        'loading_text' => __( 'Updating…', 'texty' ),
                        'reload'       => true,
                        'ajax_data'    => [
                            'action' => self::AJAX_ACTION,
                            'nonce'  => wp_create_nonce( self::AJAX_ACTION ),
                        ],
                    ],
                ],
            ]
        );

        return [ $notice ];
    }

    /**
     * Ajax handler — runs pending migrations.
     *
     * Mirrors wp-kit's MigrationRESTController guards (capability, ongoing
     * upgrade, required check) so both entry points stay consistent.
     *
     * @return void
     * @since 2.0.0
     */
    public function handle_upgrade(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission.', 'texty' ) ], 403 );
        }

        check_ajax_referer( self::AJAX_ACTION, 'nonce' );

        $manager = texty()->migrations()->get_manager();

        if ( $manager->has_ongoing_process() ) {
            wp_send_json_error( [ 'message' => __( 'Upgrade already in progress.', 'texty' ) ], 409 );
        }

        if ( ! $manager->is_upgrade_required() ) {
            wp_send_json_success( [ 'message' => __( 'No upgrade required.', 'texty' ) ] );
        }

        try {
            $manager->do_upgrade();
        } catch ( Throwable $e ) {
            error_log( sprintf( 'Texty migration failed: %s', $e->getMessage() ) );
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }

        wp_send_json_success( [ 'message' => __( 'Database updated successfully.', 'texty' ) ] );
    }
}
