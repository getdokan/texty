<?php

namespace Texty\Admin;

use Texty\OrderSms;
use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * "Send SMS" box in the WooCommerce order screen sidebar.
 *
 * Works with both order storage modes: HPOS (`woocommerce_page_wc-orders`)
 * and legacy post-based orders (`shop_order`). The box itself is a small
 * script built on WordPress' own components, loaded only on this screen.
 *
 * @since 2.0.3
 */
class OrderSmsBox {

    /**
     * Script / style handle.
     */
    const HANDLE = 'texty-order-sms';

    /**
     * Hook in.
     */
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'register' ], 40 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    /**
     * Screen id of the order edit page for the active storage mode.
     *
     * @return string
     */
    protected function screen_id() {
        return function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
    }

    /**
     * Register the meta box.
     *
     * @return void
     */
    public function register() {
        if ( ! current_user_can( OrderSms::capability() ) ) {
            return;
        }

        add_meta_box(
            'texty-order-sms',
            __( 'Send SMS', 'texty' ),
            [ $this, 'render' ],
            $this->screen_id(),
            'side',
            // 'high' places it above WooCommerce's Order notes (registered at
            // 'default'), right under the Order actions box.
            'high'
        );
    }

    /**
     * Render the mount point and its data.
     *
     * @param WP_Post|WC_Order $post_or_order Legacy post or HPOS order.
     *
     * @return void
     */
    public function render( $post_or_order ) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : 0 );

        if ( ! $order ) {
            return;
        }

        $gateway = texty()->gateways()->active_gateway();
        $config  = [
            'orderId'     => $order->get_id(),
            'suggestions' => texty()->order_sms()->suggestions( $order ),
            'tokens'      => texty()->order_sms()->tokens(),
            'gateway'     => $gateway ? (string) $gateway->name() : '',
            'settingsUrl' => admin_url( 'admin.php?page=texty#/gateway' ),
            'maxLength'   => OrderSms::MAX_LENGTH,
        ];

        printf(
            '<div id="texty-order-sms-root" data-config="%s"><p class="description">%s</p></div>',
            esc_attr( wp_json_encode( $config ) ),
            esc_html__( 'Loading…', 'texty' )
        );
    }

    /**
     * Load the box script on the order edit screen only.
     *
     * @return void
     */
    public function enqueue() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen || $this->screen_id() !== $screen->id || ! current_user_can( OrderSms::capability() ) ) {
            return;
        }

        // On HPOS the orders list shares the edit screen's id; only load on
        // the single-order (edit / new) view.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

        if ( 'shop_order' !== $screen->id && ! in_array( $action, [ 'edit', 'new' ], true ) ) {
            return;
        }

        $asset_path = TEXTY_DIR . '/dist/order-sms.asset.php';

        if ( ! file_exists( $asset_path ) ) {
            error_log( 'Texty: missing build artifact ' . $asset_path . ' — run `npm run build`. The order "Send SMS" box was not loaded.' );

            return;
        }

        $asset = include $asset_path;

        wp_enqueue_script(
            self::HANDLE,
            TEXTY_URL . '/dist/order-sms.js',
            isset( $asset['dependencies'] ) ? $asset['dependencies'] : [],
            isset( $asset['version'] ) ? $asset['version'] : TEXTY_VERSION,
            true
        );

        wp_enqueue_style( 'wp-components' );
        wp_set_script_translations( self::HANDLE, 'texty', TEXTY_DIR . '/languages' );
    }
}
