<?php

namespace Texty;

use Texty\Integrations\Dokan;
use Texty\Integrations\WooCommerce;
use Texty\Models\SmsStat;
use WeDevs\WPKit\DataLayer\DataLayerFactory;

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
     * Uses DataLayer for type-safe, cached, and hookable SMS logging.
     *
     * @param mixed                  $result  The send result (bool, array, or WP_Error)
     * @param string                 $to      Recipient phone number
     * @param string                 $message The message body
     * @param GatewayInterface|false $gateway The gateway instance or false
     *
     * @return void
     */
    public function log_sms( $result, $to, $message, $gateway ) {
        $gateway_name = texty()->settings()->gateway();

        // Determine status based on result
        $status = is_wp_error( $result ) ? 'failed' : 'sent';

        // Extract reference_id from gateway response
        $reference_id = $this->extract_reference_id( $result );
        if ( null === $reference_id ) {
            $reference_id = '';
        }

        // Get DataLayerFactory store
        $store = DataLayerFactory::make_store( SmsStat::class );

        // Create and save SMS record using DataLayer.
        // `set_receiver` is typed `string`; coerce defensively so a failed
        // send with a null/missing recipient still logs instead of fatalling.
        $sms = new SmsStat();
        $sms->set_props( [
            'receiver'      => is_string( $to ) ? $to : '',
            'gateway'       => $gateway_name ? $gateway_name : '',
            'status'        => $status,
            'reference_id'  => $reference_id,
            'created_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ] );

        $store->create( $sms );
    }
    private function extract_reference_id( $result ) {
        if ( ! is_array( $result ) ) return null;

        $id_keys = ['sid', 'message-id', 'message_uuid', 'apiMsgId', 'reference_id'];

        foreach ( $id_keys as $key ) {
            if ( ! empty( $result[ $key ] ) ) {
                return $result[ $key ];
            }
        }

        return null;
    }
}
