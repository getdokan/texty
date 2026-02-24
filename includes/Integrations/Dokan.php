<?php

namespace Texty\Integrations;

/**
 * Dokan Integration Class
 */
class Dokan {

    /**
     * Initialize
     */
    public function __construct() {
        add_action( 'woocommerce_order_status_changed', [ $this, 'order_status_changed' ], 99, 4 );
        add_action( 'dokan_new_seller_created', [ $this, 'update_vendor_phone' ], 35, 2 );
        add_action( 'dokan_store_profile_saved', [ $this, 'update_vendor_phone' ], 35, 2 );
        add_action( 'texty_register_notifications', [ $this, 'register_notifications' ] );
    }

    /**
     * Register Dokan notification types.
     *
     * @param \Texty\Notifications $notifications The notifications manager
     *
     * @return void
     */
    public function register_notifications( $notifications ) {
        if ( ! class_exists( 'WeDevs_Dokan' ) ) {
            return;
        }

        $namespace = 'Texty\Notifications\Dokan\\';

        $notifications->register( 'order_dokan_processing', $namespace . 'ProcessingVendor' );
        $notifications->register( 'order_dokan_complete', $namespace . 'CompleteVendor' );
        $notifications->register( 'order_dokan_cancelled', $namespace . 'CancelledVendor' );
        $notifications->register( 'order_dokan_failed', $namespace . 'FailedVendor' );
        $notifications->register( 'order_dokan_refunded', $namespace . 'RefundedVendor' );
    }

    /**
     * Send a message when an order status changes
     *
     * @param int      $order_id
     * @param string   $old_status
     * @param string   $order_status
     * @param WC_Order $order
     *
     * @return void
     */
    public function order_status_changed( $order_id, $old_status, $order_status, $order ) {
        $vendor_id = $this->vendor_id( $order );

        if ( ! $vendor_id ) {
            return;
        }

        switch ( $order_status ) {
            case 'processing':
                $this->send( 'order_dokan_processing', $order, $vendor_id );
                break;

            case 'completed':
                $this->send( 'order_dokan_complete', $order, $vendor_id );
                break;

            case 'cancelled':
                $this->send( 'order_dokan_cancelled', $order, $vendor_id );
                break;

            case 'failed':
                $this->send( 'order_dokan_failed', $order, $vendor_id );
                break;

            case 'refunded':
                $this->send( 'order_dokan_refunded', $order, $vendor_id );
                break;

            default:
                // code...
                break;
        }
    }

    /**
     * Save texty phone number when a seller add/update their phone number
     *
     * @param int   $user_id
     * @param array $settings
     *
     * @return void
     */
    public function update_vendor_phone( $user_id, $settings ) {
        if ( ! isset( $settings['phone'] ) ) {
            return;
        }

        update_user_meta( $user_id, 'texty_phone', $settings['phone'] );
    }

    /**
     * Get the vendor ID from order
     *
     * @param WC_Order $order
     *
     * @return int
     */
    protected function vendor_id( $order ) {
        return (int) $order->get_meta( '_dokan_vendor_id' );
    }

    /**
     * Send notification by event
     *
     * @param string   $event
     * @param WC_Order $order
     * @param int      $vendor_id
     *
     * @return void
     */
    private function send( $event, $order, $vendor_id ) {
        $class        = texty()->notifications()->get( $event );
        $notification = new $class();

        $notification->set_order( $order );
        $notification->set_vendor( $vendor_id );
        $notification->send();
    }
}
