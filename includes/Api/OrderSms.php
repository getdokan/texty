<?php
/**
 * Manual order SMS REST controller.
 *
 * Route:
 *   POST /texty/v1/orders/{id}/sms — send `message` to `to` for order {id}.
 *
 * @package Texty\Api
 * @since   2.0.3
 */

namespace Texty\Api;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * OrderSms Class
 */
class OrderSms extends Base {

    /**
     * Constructor.
     *
     * @since 2.0.3
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'orders';
    }

    /**
     * Register routes.
     *
     * @return void
     * @since 2.0.3
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>\d+)/sms',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'send' ],
                    'permission_callback' => [ $this, 'order_permissions_check' ],
                    'args'                => [
                        'id'      => [
                            'description' => __( 'Order ID.', 'texty' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'to'      => [
                            'description'       => __( 'Receiver phone number.', 'texty' ),
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'message' => [
                            'description'       => __( 'Message; may contain order {tokens}.', 'texty' ),
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_textarea_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Admins and shop managers (anyone who can edit others' orders) may
     * message about an order.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return true|WP_Error
     * @since 2.0.3
     */
    public function order_permissions_check( $request ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return new WP_Error( 'texty_no_woocommerce', __( 'WooCommerce is not active.', 'texty' ), [ 'status' => 404 ] );
        }

        // `edit_others_shop_orders`, not `edit_shop_orders`: Dokan gives
        // vendors `edit_shop_orders` for their own orders, which would let any
        // vendor message any customer through this endpoint.
        if ( ! current_user_can( 'edit_others_shop_orders' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to message customers about orders.', 'texty' ), [ 'status' => rest_authorization_required_code() ] );
        }

        return true;
    }

    /**
     * POST — send the SMS.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     * @since 2.0.3
     */
    public function send( $request ) {
        $order = wc_get_order( (int) $request->get_param( 'id' ) );

        if ( ! $order || 'shop_order_refund' === $order->get_type() ) {
            return new WP_Error( 'texty_order_not_found', __( 'Order not found.', 'texty' ), [ 'status' => 404 ] );
        }

        $result = texty()->order_sms()->send( $order, (string) $request->get_param( 'to' ), (string) $request->get_param( 'message' ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            [
                'success' => true,
                'content' => $result['content'],
                'note'    => $result['note'],
            ]
        );
    }
}
