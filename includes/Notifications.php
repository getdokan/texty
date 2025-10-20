<?php

namespace Texty;

/**
 * Notification Class
 */
class Notifications {

    /**
     * Option key to hold the notifications
     */
    const OPTION_KEY = 'texty_notifications';

    /**
     * Notifications
     *
     * @var array
     */
    private $notifications = [];

    /**
     * Get a notification class
     *
     * @param string $key
     *
     * @return false|string
     */
    public function get( $key ) {
        $notifications = $this->all();

        if ( array_key_exists( $key, $notifications ) ) {
            return $notifications[ $key ];
        }

        return false;
    }

    /**
     * Get available notification classes
     *
     * @return array
     */
    public function all() {
        if ( $this->notifications ) {
            return $this->notifications;
        }

        $notifications = [
            'registration' => __NAMESPACE__ . '\Notifications\WP\Registration',
            'comment'      => __NAMESPACE__ . '\Notifications\WP\Comment',
        ];

        if ( class_exists( 'WooCommerce' ) ) {
            // WC Admin
            $notifications['order_admin_processing'] = __NAMESPACE__ . '\Notifications\WC\ProcessingAdmin';
            $notifications['order_admin_complete']   = __NAMESPACE__ . '\Notifications\WC\CompleteAdmin';
            $notifications['order_admin_cancelled']  = __NAMESPACE__ . '\Notifications\WC\CancelledAdmin';
            $notifications['order_admin_failed']     = __NAMESPACE__ . '\Notifications\WC\FailedAdmin';
            $notifications['order_admin_refunded']   = __NAMESPACE__ . '\Notifications\WC\RefundedAdmin';

            // WC Customers
            $notifications['order_customer_hold']       = __NAMESPACE__ . '\Notifications\WC\HoldCustomer';
            $notifications['order_customer_processing'] = __NAMESPACE__ . '\Notifications\WC\ProcessingCustomer';
            $notifications['order_customer_complete']   = __NAMESPACE__ . '\Notifications\WC\CompleteCustomer';
            $notifications['order_customer_cancelled']  = __NAMESPACE__ . '\Notifications\WC\CancelledCustomer';
            $notifications['order_customer_failed']     = __NAMESPACE__ . '\Notifications\WC\FailedCustomer';
            $notifications['order_customer_refunded']   = __NAMESPACE__ . '\Notifications\WC\RefundedCustomer';
        }

        if ( class_exists( 'WeDevs_Dokan' ) ) {
            $notifications['order_dokan_processing'] = __NAMESPACE__ . '\Notifications\Dokan\ProcessingVendor';
            $notifications['order_dokan_complete']   = __NAMESPACE__ . '\Notifications\Dokan\CompleteVendor';
            $notifications['order_dokan_cancelled']  = __NAMESPACE__ . '\Notifications\Dokan\CancelledVendor';
            $notifications['order_dokan_failed']     = __NAMESPACE__ . '\Notifications\Dokan\FailedVendor';
            $notifications['order_dokan_refunded']   = __NAMESPACE__ . '\Notifications\Dokan\RefundedVendor';
        }

        $this->notifications = apply_filters( 'texty_available_notifications', $notifications );

        return $this->notifications;
    }

    /**
     * Get the name of the groups
     *
     * @return void
     */
    public function get_groups() {
        return apply_filters( 'texty_notification_groups', [ // phpcs:ignore
            'wp' => [
                'title'       => __( 'WordPress', 'texty' ),
                'description' => '',
                'available'   => true,
            ],
            'wc' => [
                'title'       => __( 'WooCommerce', 'texty' ),
                'description' => '',
                'available'   => class_exists( 'WooCommerce' ) ? true : false,
            ],
            'dokan' => [
                'title'       => __( 'Dokan', 'texty' ),
                'description' => '',
                'available'   => class_exists( 'WeDevs_Dokan' ) ? true : false,
            ],
        ] ); // phpcs:ignore
    }

    /**
     * Retreive all the settings
     *
     * @return array
     */
    public function settings() {
        return get_option( self::OPTION_KEY, [] );
    }
}
