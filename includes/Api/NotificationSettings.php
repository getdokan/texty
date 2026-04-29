<?php

namespace Texty\Api;

use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * NotificationSettings REST Controller.
 *
 * Exposes global notification compliance settings (admin phone, sender ID,
 * pause-all toggle, company-name footer toggle) and the opt-out phone list
 * management endpoint.
 *
 * Routes:
 *   GET  /texty/v1/notification-settings           — read all settings
 *   POST /texty/v1/notification-settings           — update settings
 *   POST /texty/v1/notification-settings/opt-out   — opt a phone in / out
 *
 * @since 1.2.0
 */
class NotificationSettings extends Base {

    /**
     * Initialize.
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'notification-settings';
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_items' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => $this->get_settings_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/opt-out',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'update_opt_out' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'phone' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'action' => [
                            'required' => true,
                            'type'     => 'string',
                            'enum'     => [ 'opt_out', 'opt_in' ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * GET — return current settings + opted-out numbers.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return \WP_REST_Response
     */
    public function get_items( $request ) {
        unset( $request );

        $settings = texty()->notification_settings();

        return rest_ensure_response( [
            'settings'    => $settings->all(),
            'opted_out'   => $settings->opted_out_numbers(),
        ] );
    }

    /**
     * POST — persist settings.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return \WP_REST_Response
     */
    public function update_items( $request ) {
        $service = texty()->notification_settings();

        $values = [
            'global_sender_id'    => sanitize_text_field( (string) $request->get_param( 'global_sender_id' ) ),
            'pause_all'           => (bool) $request->get_param( 'pause_all' ),
            'append_company_name' => (bool) $request->get_param( 'append_company_name' ),
        ];

        // Allow partial updates — drop keys the request didn't actually send.
        $params = $request->get_params();
        foreach ( array_keys( $values ) as $key ) {
            if ( ! array_key_exists( $key, $params ) ) {
                unset( $values[ $key ] );
            }
        }

        $merged = $service->update( $values );

        return rest_ensure_response( [
            'settings'  => $merged,
            'opted_out' => $service->opted_out_numbers(),
        ] );
    }

    /**
     * POST /opt-out — toggle opt-out state for a number.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return \WP_REST_Response
     */
    public function update_opt_out( $request ) {
        $service = texty()->notification_settings();
        $phone   = (string) $request->get_param( 'phone' );
        $action  = (string) $request->get_param( 'action' );

        if ( 'opt_out' === $action ) {
            $service->opt_out( $phone );
        } elseif ( 'opt_in' === $action ) {
            $service->opt_in( $phone );
        }

        return rest_ensure_response( [
            'opted_out' => $service->opted_out_numbers(),
        ] );
    }

    /**
     * Endpoint args for the EDITABLE schema.
     *
     * @return array
     */
    private function get_settings_args() {
        return [
            'global_sender_id' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'pause_all' => [
                'type' => 'boolean',
            ],
            'append_company_name' => [
                'type' => 'boolean',
            ],
        ];
    }
}
