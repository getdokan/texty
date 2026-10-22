<?php

namespace Texty\Gateways;

use Texty\Http\Client;
use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for gateways
 *
 * Gives a gateway the shared HTTP client, its saved credentials and
 * metadata defaults. Implementing `GatewayInterface` directly still works;
 * this class is optional for third-party gateways.
 *
 * @since 2.1.0
 */
abstract class AbstractGateway implements GatewayInterface {

    /**
     * Registry key, also the `texty_settings` sub-key holding the credentials
     *
     * @var string
     */
    protected $key = '';

    /**
     * HTTP client
     *
     * @var Client|null
     */
    private $http = null;

    /**
     * Send a message and return the normalized result
     *
     * @since 2.1.0
     *
     * @param string $to
     * @param string $message
     * @param array  $options Per-message options, see SendOptions
     *
     * @return Result
     */
    abstract public function send_result( $to, $message, $options = [] );

    /**
     * Send SMS
     *
     * Keeps the legacy return shape (array on success, WP_Error on failure)
     * for anyone calling a gateway directly; use `send_result()` for the
     * typed result.
     *
     * @since 2.1.0
     *
     * @param string $to
     * @param string $message
     * @param array  $options Per-message options, see SendOptions
     *
     * @return array|WP_Error
     */
    public function send( $to, $message, $options = [] ) {
        $result = $this->send_result( $to, $message, SendOptions::normalize( $options ) );

        if ( ! $result instanceof Result ) {
            return Result::failure( 'texty_invalid_result', '' )->to_wp_error();
        }

        return $result->to_legacy();
    }

    /**
     * Get the registry key
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function get_key() {
        return $this->key;
    }

    /**
     * Get the gateway metadata
     *
     * @since 2.1.0
     *
     * @return array See `Metadata::defaults()`
     */
    public function metadata() {
        return Metadata::normalize( $this->meta() );
    }

    /**
     * Gateway-specific metadata, merged over the defaults
     *
     * @since 2.1.0
     *
     * @return array
     */
    protected function meta() {
        return [];
    }

    /**
     * Get the shared HTTP client
     *
     * @since 2.1.0
     *
     * @return Client
     */
    protected function http() {
        if ( null === $this->http ) {
            $this->http = new Client( $this->key );
        }

        return $this->http;
    }

    /**
     * Get the saved credentials
     *
     * @since 2.1.0
     *
     * @return array
     */
    protected function creds() {
        $creds = texty()->settings()->get( $this->key );

        return is_array( $creds ) ? $creds : [];
    }

    /**
     * Read one credential as a trimmed string
     *
     * @since 2.1.0
     *
     * @param array  $creds
     * @param string $key
     *
     * @return string
     */
    protected function cred( array $creds, $key ) {
        return isset( $creds[ $key ] ) && is_scalar( $creds[ $key ] ) ? trim( (string) $creds[ $key ] ) : '';
    }

    /**
     * Read the submitted credentials from a validate() request
     *
     * @since 2.1.0
     *
     * @param WP_REST_Request $request
     *
     * @return array
     */
    protected function request_creds( $request ) {
        $creds = $request->get_param( $this->key );

        return is_array( $creds ) ? $creds : [];
    }

    /**
     * Resolve a provider base URL, allowing it to be overridden
     *
     * @since 2.1.0
     *
     * @param string $url   Default base URL
     * @param array  $creds Credentials in use (region/env selectors live here)
     *
     * @return string Base URL without a trailing slash
     */
    protected function base_url( $url, array $creds = [] ) {
        /**
         * Filter a gateway's API base URL, e.g. for dedicated or regional hosts.
         *
         * @since 2.1.0
         *
         * @param string $url   Base URL
         * @param string $key   Gateway registry key
         * @param array  $creds Credentials in use
         */
        $url = apply_filters( 'texty_gateway_base_url', $url, $this->key, $creds );

        return untrailingslashit( (string) $url );
    }

    /**
     * Turn a transport failure into a result, or null if a response arrived
     *
     * @since 2.1.0
     *
     * @param Response $response
     *
     * @return Result|null
     */
    protected function transport_failure( Response $response ) {
        return $response->is_transport_error() ? Result::from_transport_error( $response ) : null;
    }
}
