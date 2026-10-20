<?php

namespace Texty\Http;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized HTTP response
 *
 * Wraps the array returned by `wp_remote_request()` (or the WP_Error it
 * returns on a transport failure) so gateways read status, headers and
 * body the same way regardless of content type.
 *
 * @since 2.1.0
 */
class Response {

    /**
     * HTTP status code (0 on transport failure)
     *
     * @var int
     */
    private $status = 0;

    /**
     * Response headers, keyed by lower-cased name
     *
     * @var array
     */
    private $headers = [];

    /**
     * Raw response body
     *
     * @var string
     */
    private $body = '';

    /**
     * Transport error, if the request never got a response
     *
     * @var WP_Error|null
     */
    private $error = null;

    /**
     * Decoded JSON cache
     *
     * @var array|null|false False until decoded
     */
    private $json = false;

    /**
     * Build from the return value of `wp_remote_request()`
     *
     * @since 2.1.0
     *
     * @param array|WP_Error $raw
     *
     * @return Response
     */
    public static function from_wp( $raw ) {
        $response = new self();

        if ( is_wp_error( $raw ) ) {
            $response->error = $raw;

            return $response;
        }

        $response->status = (int) wp_remote_retrieve_response_code( $raw );
        $response->body   = (string) wp_remote_retrieve_body( $raw );

        $headers = wp_remote_retrieve_headers( $raw );

        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }

        if ( is_array( $headers ) ) {
            foreach ( $headers as $name => $value ) {
                $response->headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
            }
        }

        return $response;
    }

    /**
     * Build a response that represents a request which was never sent
     *
     * @since 2.1.0
     *
     * @param WP_Error $error
     *
     * @return Response
     */
    public static function from_error( WP_Error $error ) {
        return self::from_wp( $error );
    }

    /**
     * Whether the request failed before a response was received
     *
     * @since 2.1.0
     *
     * @return bool
     */
    public function is_transport_error() {
        return null !== $this->error;
    }

    /**
     * Get the transport error
     *
     * @since 2.1.0
     *
     * @return WP_Error|null
     */
    public function get_error() {
        return $this->error;
    }

    /**
     * Get the HTTP status code
     *
     * @since 2.1.0
     *
     * @return int
     */
    public function status() {
        return $this->status;
    }

    /**
     * Whether the status code is 2xx
     *
     * @since 2.1.0
     *
     * @return bool
     */
    public function is_2xx() {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Get the raw body
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function body() {
        return $this->body;
    }

    /**
     * Get a response header
     *
     * @since 2.1.0
     *
     * @param string $name Case-insensitive header name
     *
     * @return string Empty string when missing
     */
    public function header( $name ) {
        $name = strtolower( $name );

        return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : '';
    }

    /**
     * Decode the body as JSON
     *
     * Objects become associative arrays, top-level arrays stay lists, and
     * big integers (provider message ids) are kept as strings so they don't
     * lose precision.
     *
     * @since 2.1.0
     *
     * @return array|null Null when the body is empty or not JSON
     */
    public function json() {
        if ( false === $this->json ) {
            $decoded    = '' === trim( $this->body ) ? null : json_decode( $this->body, true, 512, JSON_BIGINT_AS_STRING );
            $this->json = is_array( $decoded ) ? $decoded : null;
        }

        return $this->json;
    }

    /**
     * Read a value from the decoded JSON body by dot path
     *
     * @since 2.1.0
     *
     * @param string $path    Dot path, e.g. `messages.0.id`
     * @param mixed  $default Returned when the path doesn't exist
     *
     * @return mixed
     */
    public function get( $path, $default = null ) {
        $value = $this->json();

        foreach ( explode( '.', $path ) as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return $default;
            }

            $value = $value[ $segment ];
        }

        return $value;
    }

    /**
     * The body in the form it should be logged: decoded JSON when possible,
     * otherwise the raw string
     *
     * @since 2.1.0
     *
     * @return array|string
     */
    public function payload() {
        $json = $this->json();

        return null === $json ? $this->body : $json;
    }
}
