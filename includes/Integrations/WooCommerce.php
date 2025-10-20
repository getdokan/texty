<?php

namespace Texty\Integrations;

/**
 * WooCommerce Integration Class
 */
class WooCommerce {

    /**
     * Initialize
     */
    public function __construct() {
        add_action( 'woocommerce_order_status_changed', [ $this, 'order_status_changed' ], 10, 4 );
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
        // don't process sub-orders
        if ( $order->get_parent_id() ) {
            return;
        }

        switch ( $order_status ) {
            case 'on-hold':
                $this->send( 'order_customer_hold', $order );
                break;

            case 'processing':
                $this->send( 'order_admin_processing', $order );
                $this->send( 'order_customer_processing', $order );
                break;

            case 'completed':
                $this->send( 'order_admin_complete', $order );
                $this->send( 'order_customer_complete', $order );
                break;

            case 'cancelled':
                $this->send( 'order_admin_cancelled', $order );
                $this->send( 'order_customer_cancelled', $order );
                break;

            case 'failed':
                $this->send( 'order_admin_failed', $order );
                $this->send( 'order_customer_failed', $order );
                break;

            case 'refunded':
                $this->send( 'order_admin_refunded', $order );
                $this->send( 'order_customer_refunded', $order );
                break;

            default:
                // code...
                break;
        }
    }

    /**
     * Send notification by event
     *
     * @param string   $event
     * @param WC_Order $order
     *
     * @return void
     */
    private function send( $event, $order ) {
        $class        = texty()->notifications()->get( $event );
        $notification = new $class();

        $notification->set_order( $order );
        $notification->send();
    }
}
