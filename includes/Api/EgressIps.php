<?php
/**
 * Outgoing-IP REST controller, backing the "IP whitelist" panel on gateways
 * that whitelist API callers by IP.
 *
 * Routes:
 *   GET    /texty/v1/egress-ips            — this server's IP + every observed IP.
 *   POST   /texty/v1/egress-ips            — add an IP by hand (e.g. the host's NAT IP).
 *   DELETE /texty/v1/egress-ips            — remove one IP (`ip`), or all when omitted.
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
 * EgressIps Class
 */
class EgressIps extends Base {

    /**
     * Constructor.
     *
     * @since 2.0.3
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'egress-ips';
    }

    /**
     * Register routes.
     *
     * @return void
     * @since 2.0.3
     */
    public function register_routes() {
        $ip_arg = [
            'description' => __( 'IPv4 address.', 'texty' ),
            'type'        => 'string',
        ];

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'refresh' => [
                            'description' => __( "Re-detect this server's IP instead of using the cached value.", 'texty' ),
                            'type'        => 'boolean',
                            'default'     => false,
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_item' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'ip' => array_merge( $ip_arg, [ 'required' => true ] ),
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_items' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'ip' => $ip_arg,
                    ],
                ],
            ]
        );
    }

    /**
     * GET — detect this server's IP, record it, and return the list.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     * @since 2.0.3
     */
    public function get_items( $request ) {
        $egress = texty()->egress_ip();
        $ip     = $egress->detect( (bool) $request->get_param( 'refresh' ) );

        if ( '' !== $ip ) {
            $egress->record( $ip );
        }

        return $this->response();
    }

    /**
     * POST — add an IP by hand.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     * @since 2.0.3
     */
    public function create_item( $request ) {
        $ip = sanitize_text_field( (string) $request->get_param( 'ip' ) );

        if ( ! texty()->egress_ip()->record( $ip, 'detected', '', true ) ) {
            return new WP_Error( 'texty_invalid_ip', __( 'Enter a valid IPv4 address, e.g. 203.0.113.7.', 'texty' ), [ 'status' => 400 ] );
        }

        return $this->response();
    }

    /**
     * DELETE — remove one IP, or clear the list when `ip` is omitted.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     * @since 2.0.3
     */
    public function delete_items( $request ) {
        $ip = sanitize_text_field( (string) $request->get_param( 'ip' ) );

        if ( '' === $ip ) {
            texty()->egress_ip()->clear();
        } else {
            texty()->egress_ip()->remove( $ip );
        }

        return $this->response();
    }

    /**
     * Shape the response: this server's IP (cached detection) plus the
     * observed list.
     *
     * @return WP_REST_Response
     */
    protected function response() {
        $egress  = texty()->egress_ip();
        $current = $egress->detect();

        return rest_ensure_response(
            [
                'current'  => [
                    'ip'   => $current,
                    'host' => $egress->host(),
                ],
                'observed' => array_map(
                    static function ( $entry ) {
                        return [
                            'ip'        => (string) $entry['ip'],
                            'host'      => (string) $entry['host'],
                            'result'    => (string) $entry['result'],
                            'gateway'   => (string) $entry['gateway'],
                            'manual'    => ! empty( $entry['manual'] ),
                            'last_seen' => gmdate( 'c', (int) $entry['last_seen'] ),
                        ];
                    },
                    $egress->observed()
                ),
            ]
        );
    }
}
