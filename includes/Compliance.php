<?php

namespace Texty;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Compliance Class.
 *
 * Wires global notification settings into the send pipeline:
 *  - Pause all outgoing SMS when `pause_all` is on.
 *  - Append company name to outgoing messages.
 *
 * @since 2.0.0
 */
class Compliance {

    /**
     * Initialize hooks.
     */
    public function __construct() {
        add_filter( 'texty_pre_send_sms', [ $this, 'maybe_block_send' ], 10, 4 );
        add_filter( 'texty_sms_message', [ $this, 'maybe_append_footer' ], 10, 3 );
    }

    /**
     * Short-circuit the send pipeline when paused.
     *
     * @param null|mixed $pre_send Existing short-circuit value.
     * @param string     $to       Recipient phone number.
     * @param string     $message  Message body.
     * @param mixed      $gateway  Gateway instance.
     *
     * @return null|WP_Error
     */
    public function maybe_block_send( $pre_send, $to, $message, $gateway ) {
        unset( $to, $message, $gateway );

        // Respect a previous short-circuit (don't override an upstream filter).
        if ( null !== $pre_send ) {
            return $pre_send;
        }

        $settings = texty()->notification_settings();

        if ( ! empty( $settings->get( 'pause_all' ) ) ) {
            return new WP_Error(
                'texty_paused',
                __( 'Outgoing SMS notifications are paused.', 'texty' )
            );
        }

        return null;
    }

    /**
     * Append the company name footer to outgoing messages.
     *
     * @param string $message Message body.
     * @param string $to      Recipient phone number.
     * @param mixed  $gateway Gateway instance.
     *
     * @return string
     */
    public function maybe_append_footer( $message, $to, $gateway ) {
        unset( $to, $gateway );

        if ( ! is_string( $message ) ) {
            return $message;
        }

        $settings = texty()->notification_settings();
        $footer   = [];

        if ( ! empty( $settings->get( 'append_company_name' ) ) ) {
            $store_name = (string) get_bloginfo( 'name' );

            /**
             * Filter the company / store name appended to outgoing SMS.
             *
             * @since 2.0.0
             *
             * @param string $store_name Default store name (site title).
             */
            $store_name = (string) apply_filters( 'texty_company_name', $store_name );

            if ( '' !== $store_name ) {
                /* translators: %s: company / store name */
                $footer[] = sprintf( __( 'from %s', 'texty' ), $store_name );
            }
        }

        if ( empty( $footer ) ) {
            return $message;
        }

        return rtrim( $message ) . "\n\n" . implode( ' ', $footer );
    }
}
