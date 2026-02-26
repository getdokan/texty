<?php

namespace Texty;

use Texty\Integrations\Dokan;
use Texty\Integrations\WooCommerce;

/**
 * Dispatcher Class
 */
class Dispatcher {

    /**
     * Initialize
     */
    public function __construct() {

        // WordPress Events
        add_action( 'user_register', [ $this, 'user_register' ] );
        add_action( 'comment_post', [ $this, 'new_comment' ] );

        // SMS Logging via gateway hooks
        add_action( 'texty_after_send_sms', [ $this, 'log_sms' ], 10, 4 );

        // Load integrations
        $this->register_integrations();
    }

    /**
     * Register integrations via hook for extensibility.
     *
     * @return void
     */
    private function register_integrations() {
        /**
         * Fires to allow registration of custom integrations.
         *
         * Default handler loads WooCommerce and Dokan integrations
         * when their respective plugins are active.
         */
        do_action( 'texty_register_integrations' );

        // Load built-in integrations conditionally
        if ( class_exists( 'WooCommerce' ) ) {
            new WooCommerce();
        }

        if ( class_exists( 'WeDevs_Dokan' ) ) {
            new Dokan();
        }
    }

    /**
     * Send message upon user registration
     *
     * @param int $user_id
     *
     * @return void
     */
    public function user_register( $user_id ) {
        $class    = texty()->notifications()->get( 'registration' );
        $notifier = new $class();

        $notifier->set_user( $user_id );
        $notifier->send();
    }

    /**
     * Send message upon a new comment
     *
     * @param int $comment_id
     *
     * @return void
     */
    public function new_comment( $comment_id ) {
        $class    = texty()->notifications()->get( 'comment' );
        $notifier = new $class();

        $notifier->set_comment( $comment_id );
        $notifier->send();
    }

    /**
     * Log SMS message to database via gateway hook
     *
     * @param mixed                  $result  The send result (bool, array, or WP_Error)
     * @param string                 $to      Recipient phone number
     * @param string                 $message The message body
     * @param GatewayInterface|false $gateway The gateway instance or false
     *
     * @return void
     */
    public function log_sms( $result, $to, $message, $gateway ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'texty_sms_stat';
        $gateway_name = texty()->settings()->gateway();

        // Determine status
        $status = is_wp_error( $result ) ? 'failed' : 'sent';

        // Extract reference_id from result
        $reference_id = $this->extract_reference_id( $result );

        $current_time = current_time( 'mysql' );

        $data = [
            'receiver'     => $to,
            'gateway'      => $gateway_name ? $gateway_name : '',
            'status'       => $status,
            'created_at'   => $current_time,
            'updated_at'   => $current_time,
            'reference_id' => $reference_id,
        ];

        $wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
    private function extract_reference_id( $result ) {
        if ( ! is_array( $result ) ) return null;

        $id_keys = ['sid', 'message-id', 'message_uuid', 'apiMsgId'];

        foreach ( $id_keys as $key ) {
            if ( ! empty( $result[ $key ] ) ) {
                return $result[ $key ];
            }
        }

        return null;
    }
}
