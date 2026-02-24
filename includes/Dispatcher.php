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

        // WooCommerce
        new WooCommerce();
        new Dokan();
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
     * Log SMS message to database
     *
     * @param string $to           Recipient phone number
     * @param string $reference_id Gateway message SID/ID (optional)
     * @return bool True on success, false on failure
     */
    public static function log_sms( $to, $reference_id = null ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'texty_sms_stat';
        $gateway    = texty()->settings()->gateway();

        $data = [
            'receiver'     => $to,
            'gateway'      => $gateway ? $gateway : '',
            'status'       => null,
            'timestamp'    => current_time( 'mysql' ),
            'reference_id' => $reference_id,
        ];

        $result = $wpdb->insert( $table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $result !== false;
    }
}
