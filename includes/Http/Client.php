<?php

namespace Texty\Http;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared HTTP client for gateways
 *
 * Every gateway request goes through here so timeouts, TLS and encoding are
 * consistent. Supported request args:
 *
 * - `headers` array  Request headers
 * - `query`   array  Appended to the URL as a query string
 * - `json`    array  Encoded as the JSON body; sets Content-Type
 * - `form`    array  Sent form-encoded
 * - `body`    string Raw body (used as-is)
 * - `timeout` int    Overrides the default timeout for this request
 *
 * @since 2.1.0
 */
class Client {

    /**
     * Default timeout in seconds
     */
    const DEFAULT_TIMEOUT = 15;

    /**
     * Gateway key, passed to filters
     *
     * @var string
     */
    private $gateway;

    /**
     * Constructor
     *
     * @since 2.1.0
     *
     * @param string $gateway Gateway registry key
     */
    public function __construct( $gateway = '' ) {
        $this->gateway = (string) $gateway;
    }

    /**
     * Send a GET request
     *
     * @since 2.1.0
     *
     * @param string $url
     * @param array  $args
     *
     * @return Response
     */
    public function get( $url, array $args = [] ) {
        return $this->request( 'GET', $url, $args );
    }

    /**
     * Send a POST request
     *
     * @since 2.1.0
     *
     * @param string $url
     * @param array  $args
     *
     * @return Response
     */
    public function post( $url, array $args = [] ) {
        return $this->request( 'POST', $url, $args );
    }

    /**
     * Send a request
     *
     * @since 2.1.0
     *
     * @param string $method HTTP method
     * @param string $url    Absolute https URL
     * @param array  $args   See class docblock
     *
     * @return Response
     */
    public function request( $method, $url, array $args = [] ) {
        if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
            return Response::from_error(
                new WP_Error( 'texty_insecure_url', __( 'Gateway requests must use HTTPS.', 'texty' ) )
            );
        }

        if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
            $url = add_query_arg( rawurlencode_deep( $args['query'] ), $url );
        }

        $headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];
        $body    = null;

        if ( isset( $args['json'] ) ) {
            $body                    = wp_json_encode( $args['json'] );
            $headers['Content-Type'] = 'application/json';

            if ( ! isset( $headers['Accept'] ) ) {
                $headers['Accept'] = 'application/json';
            }
        } elseif ( isset( $args['form'] ) && is_array( $args['form'] ) ) {
            $body = $args['form'];
        } elseif ( isset( $args['body'] ) ) {
            $body = (string) $args['body'];
        }

        $timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : self::DEFAULT_TIMEOUT;

        /**
         * Filter the timeout for gateway HTTP requests.
         *
         * @since 2.1.0
         *
         * @param int    $timeout Timeout in seconds
         * @param string $gateway Gateway registry key
         * @param string $url     Request URL
         */
        $timeout = (int) apply_filters( 'texty_http_timeout', $timeout, $this->gateway, $url );

        $request = [
            'method'  => strtoupper( $method ),
            'headers' => $headers,
            'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
        ];

        if ( null !== $body ) {
            $request['body'] = $body;
        }

        /**
         * Filter the arguments passed to `wp_remote_request()` for a gateway request.
         *
         * TLS verification is always enforced after this filter runs.
         *
         * @since 2.1.0
         *
         * @param array  $request Request arguments
         * @param string $url     Request URL
         * @param string $gateway Gateway registry key
         */
        $request = apply_filters( 'texty_http_request_args', $request, $url, $this->gateway );

        $request['sslverify'] = true;

        return Response::from_wp( wp_remote_request( $url, $request ) );
    }
}
