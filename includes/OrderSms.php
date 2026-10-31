<?php

namespace Texty;

use Texty\Notifications\WC\ManualOrder;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Manual SMS from the WooCommerce order screen.
 *
 * Builds the receiver suggestions (billing, shipping, Dokan vendors) and
 * sends an admin-written message through the normal send pipeline, so the
 * pause switch, every `texty_*` hook and the SMS log all apply. Each attempt
 * adds a private order note, like WooCommerce does for emails.
 *
 * @since 2.0.3
 */
class OrderSms {

    /**
     * Longest message accepted (characters).
     */
    const MAX_LENGTH = 1000;

    /**
     * Phone numbers this order suggests as receivers, without duplicates.
     *
     * @param WC_Order $order Order.
     *
     * @return array<int, array{type:string,label:string,phone:string}>
     */
    public function suggestions( WC_Order $order ) {
        $candidates = [
            [
                'type'  => 'billing',
                'label' => __( 'Billing', 'texty' ),
                'phone' => (string) $order->get_billing_phone(),
            ],
        ];

        if ( method_exists( $order, 'get_shipping_phone' ) ) {
            $candidates[] = [
                'type'  => 'shipping',
                'label' => __( 'Shipping', 'texty' ),
                'phone' => (string) $order->get_shipping_phone(),
            ];
        }

        foreach ( $this->vendors( $order ) as $vendor ) {
            $candidates[] = $vendor;
        }

        /**
         * Filter the receiver suggestions shown in the order "Send SMS" box.
         *
         * @since 2.0.3
         *
         * @param array    $candidates Items of [ 'type' => string, 'label' => string, 'phone' => string ].
         * @param WC_Order $order      The order.
         */
        $candidates = (array) apply_filters( 'texty_order_sms_suggestions', $candidates, $order );

        $seen        = [];
        $suggestions = [];

        foreach ( $candidates as $candidate ) {
            $phone = isset( $candidate['phone'] ) ? trim( (string) $candidate['phone'] ) : '';
            $key   = preg_replace( '/\D/', '', $phone );

            if ( '' === $key || isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ]  = true;
            $suggestions[] = [
                'type'  => isset( $candidate['type'] ) ? (string) $candidate['type'] : 'other',
                'label' => isset( $candidate['label'] ) ? (string) $candidate['label'] : '',
                'phone' => $phone,
            ];
        }

        return $suggestions;
    }

    /**
     * `{token}` => description for the message box.
     *
     * @return array<string, string>
     */
    public function tokens() {
        $tokens = [];

        // Filtered list, so third-party additions / overrides / removals for
        // WooCommerce notifications show in the order Send SMS box too.
        foreach ( ( new ManualOrder() )->placeholders() as $token => $placeholder ) {
            $tokens[ '{' . $token . '}' ] = $placeholder['label'];
        }

        return $tokens;
    }

    /**
     * Send a message to one number for an order.
     *
     * @param WC_Order $order   Order whose `{tokens}` fill the message.
     * @param string   $to      Receiver phone number.
     * @param string   $message Message, may contain `{tokens}`.
     *
     * @return array{content:string,note:array}|WP_Error
     */
    public function send( WC_Order $order, $to, $message ) {
        $to      = trim( (string) $to );
        $message = trim( (string) $message );

        if ( strlen( preg_replace( '/\D/', '', $to ) ) < 6 ) {
            return new WP_Error( 'texty_invalid_phone', __( 'Enter a valid phone number.', 'texty' ), [ 'status' => 400 ] );
        }

        if ( '' === $message ) {
            return new WP_Error( 'texty_empty_message', __( 'Write a message first.', 'texty' ), [ 'status' => 400 ] );
        }

        if ( mb_strlen( $message ) > self::MAX_LENGTH ) {
            return new WP_Error(
                'texty_message_too_long',
                /* translators: %d: maximum number of characters */
                sprintf( __( 'Keep the message under %d characters.', 'texty' ), self::MAX_LENGTH ),
                [ 'status' => 400 ]
            );
        }

        $gateway = texty()->gateways()->active_gateway();

        if ( ! $gateway ) {
            return new WP_Error( 'texty_no_gateway', __( 'No SMS gateway is active. Set one up under Texty → Gateway.', 'texty' ), [ 'status' => 400 ] );
        }

        $notification = new ManualOrder();
        $notification->set_order( $order )->set_message( $message );
        $content = (string) $notification->get_message();

        // Tag the log row as a manual order SMS, like notification sends do.
        texty()->notifications()->set_active( $notification );

        try {
            $result = texty()->gateways()->send( $to, $content );
        } finally {
            texty()->notifications()->clear_active();
        }

        if ( is_wp_error( $result ) || false === $result ) {
            $error = is_wp_error( $result ) ? $result->get_error_message() : __( 'The gateway did not accept the message.', 'texty' );

            $note = $this->add_note(
                $order,
                implode(
                    "\n",
                    [
                        // Error code only: private notes are visible to Dokan
                        // vendors, and gateway errors can include e.g. the
                        // server IP. The full error is in Texty → Logs.
                        /* translators: 1: phone number, 2: gateway name, 3: error code */
                        sprintf( __( 'SMS to %1$s via %2$s failed (%3$s), details in Texty → Logs', 'texty' ), $to, $gateway->name(), is_wp_error( $result ) ? $result->get_error_code() : 'texty_send_failed' ),
                        /* translators: %s: SMS text */
                        sprintf( __( 'Message: “%s”', 'texty' ), $content ),
                    ]
                )
            );

            return new WP_Error(
                is_wp_error( $result ) ? $result->get_error_code() : 'texty_send_failed',
                $error,
                [
                    'status' => 502,
                    'note'   => $note,
                ]
            );
        }

        /* translators: 1: phone number, 2: gateway name, 3: message text */
        $note = $this->add_note( $order, sprintf( __( 'SMS sent to %1$s via %2$s: “%3$s”', 'texty' ), $to, $gateway->name(), $content ) );

        return [
            'content' => $content,
            'note'    => $note,
        ];
    }

    /**
     * Dokan vendors on this order (one per sub-order) with their phones.
     *
     * @param WC_Order $order Order.
     *
     * @return array<int, array{type:string,label:string,phone:string}>
     */
    protected function vendors( WC_Order $order ) {
        if ( ! function_exists( 'dokan_get_seller_ids_by' ) ) {
            return [];
        }

        $vendors = [];

        foreach ( (array) dokan_get_seller_ids_by( $order->get_id() ) as $vendor_id ) {
            $vendor_id = (int) $vendor_id;

            if ( ! $vendor_id ) {
                continue;
            }

            $name  = '';
            $phone = '';

            if ( function_exists( 'dokan' ) ) {
                $vendor = dokan()->vendor->get( $vendor_id );

                if ( $vendor && method_exists( $vendor, 'get_phone' ) ) {
                    $phone = (string) $vendor->get_phone();
                    $name  = method_exists( $vendor, 'get_shop_name' ) ? (string) $vendor->get_shop_name() : '';
                }
            }

            if ( '' === $phone ) {
                $phone = (string) get_user_meta( $vendor_id, 'texty_phone', true );
            }

            if ( '' === $name ) {
                $user = get_userdata( $vendor_id );
                $name = $user ? $user->display_name : '';
            }

            $vendors[] = [
                'type'  => 'vendor',
                /* translators: %s: vendor store name */
                'label' => $name ? sprintf( __( 'Vendor (%s)', 'texty' ), $name ) : __( 'Vendor', 'texty' ),
                'phone' => $phone,
            ];
        }

        return $vendors;
    }

    /**
     * Add a private order note and describe it for the order screen.
     *
     * @param WC_Order $order Order.
     * @param string   $text  Note text.
     *
     * @return array{id:int,content:string,date:string,author:string}
     */
    protected function add_note( WC_Order $order, $text ) {
        $note_id = (int) $order->add_order_note( $text, 0, true );
        $user    = wp_get_current_user();

        return [
            'id'      => $note_id,
            'content' => $text,
            'date'    => sprintf(
                /* translators: 1: date, 2: time */
                __( '%1$s at %2$s', 'texty' ),
                wc_format_datetime( wc_string_to_datetime( 'now' ), wc_date_format() ),
                wc_format_datetime( wc_string_to_datetime( 'now' ), wc_time_format() )
            ),
            'author'  => $user ? (string) $user->display_name : '',
        ];
    }
}
