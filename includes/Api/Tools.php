<?php

namespace Texty\Api;

use Texty\Dispatcher;
use WP_REST_Server;

class Tools extends Base {

    /**
     * Initialize
     *
     * @return void
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'tools';
    }

    /**
     * Registers the routes for the objects of the controller.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/test',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'test_send' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'to' => [
                            'description' => __( 'The to phone number', 'texty' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                    ],
                ],
            ]
        );
    }

    /*
     * Send a test.
     *
     * @param WP_Rest_Request $request
     *
     * @return WP_Rest_Response|WP_Error
     */
    public function test_send( $request ) {
        $to      = $request->get_param( 'to' );
        $message = 'This is a test message from Texty.';

        $status = texty()->gateways()->send( $to, $message );

        $response = [
            'success' => is_wp_error( $status ) ? false : true,
            'message' => is_wp_error( $status ) ? $status->get_error_message() : '',
        ];

        // Log the SMS if it was sent successfully
        if ( ! is_wp_error( $status ) && is_array( $status ) && isset( $status['success'] ) && $status['success'] ) {
            $dispatcher = new Dispatcher();
            $dispatcher->log_sms( $status, $to, $message, false );
        }

        return rest_ensure_response( $response );
    }
}
