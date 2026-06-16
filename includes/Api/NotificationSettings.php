<?php

namespace Texty\Api;

use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * NotificationSettings REST Controller.
 *
 * Exposes global notification compliance settings (admin phone, sender ID,
 * pause-all toggle, company-name footer toggle).
 *
 * Routes:
 *   GET  /texty/v1/notification-settings           — read all settings
 *   POST /texty/v1/notification-settings           — update settings
 *
 * @since 2.0.0
 */
class NotificationSettings extends Base {

    /**
     * Initialize.
     *
     * @since 2.0.0
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'notification-settings';
    }

    /**
     * Register routes.
     *
     * @return void
     * @since 2.0.0
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
    }

    /**
     * GET — return current settings.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return \WP_REST_Response
     * @since 2.0.0
     */
    public function get_items( $request ) {
        unset( $request );

        $settings = texty()->notification_settings();

        return rest_ensure_response(
            [
				'settings' => $settings->all(),
			]
        );
    }

    /**
     * POST — persist settings.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return \WP_REST_Response
     * @since 2.0.0
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

        return rest_ensure_response(
            [
				'settings' => $merged,
			]
        );
    }

    /**
     * Endpoint args for the EDITABLE schema.
     *
     * @return array
     * @since 2.0.0
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
