<?php

namespace Texty\Http;

use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * MAC (HMAC-SHA256) request signer
 *
 * Builds the `Authorization: MAC id="…", ts="…", nonce="…", mac="…"` header
 * used by SMSGlobal's REST API (OAuth 2 MAC-token draft style). The MAC is
 * the base64-encoded raw HMAC-SHA256 of the normalized request string:
 *
 *     ts \n nonce \n METHOD \n URI \n host \n port \n ext \n
 *
 * where URI is the path plus query string exactly as sent and `ext` is
 * empty. Timestamp and nonce are generated fresh for every header unless
 * fixed through the constructor (for tests).
 *
 * @since 2.1.0
 */
class MacAuth {

    /**
     * Maximum nonce length accepted by the provider
     */
    const MAX_NONCE_LENGTH = 32;

    /**
     * MAC key id (the API key)
     *
     * @var string
     */
    private $id;

    /**
     * MAC secret (the API secret)
     *
     * @var string
     */
    private $secret;

    /**
     * Fixed timestamp, or null to use the current time
     *
     * @var int|null
     */
    private $timestamp;

    /**
     * Fixed nonce, or null to generate one per header
     *
     * @var string|null
     */
    private $nonce;

    /**
     * Constructor
     *
     * @since 2.1.0
     *
     * @param string      $id        API key
     * @param string      $secret    API secret
     * @param int|null    $timestamp Fixed Unix timestamp (tests only)
     * @param string|null $nonce     Fixed nonce (tests only)
     */
    public function __construct( $id, $secret, $timestamp = null, $nonce = null ) {
        $this->id        = (string) $id;
        $this->secret    = (string) $secret;
        $this->timestamp = null === $timestamp ? null : (int) $timestamp;
        $this->nonce     = null === $nonce ? null : (string) $nonce;
    }

    /**
     * Build the Authorization header value for a request
     *
     * @since 2.1.0
     *
     * @param string $method HTTP method
     * @param string $url    Absolute request URL, exactly as it will be sent
     *
     * @return string
     */
    public function header( $method, $url ) {
        $timestamp = null === $this->timestamp ? time() : $this->timestamp;
        $nonce     = null === $this->nonce ? self::generate_nonce() : $this->nonce;

        return sprintf(
            'MAC id="%s", ts="%s", nonce="%s", mac="%s"',
            $this->id,
            $timestamp,
            $nonce,
            $this->mac( $method, $url, $timestamp, $nonce )
        );
    }

    /**
     * Compute the MAC for a request
     *
     * @since 2.1.0
     *
     * @param string $method    HTTP method
     * @param string $url       Absolute request URL
     * @param int    $timestamp Unix timestamp
     * @param string $nonce     Nonce
     *
     * @return string Base64-encoded HMAC-SHA256
     */
    public function mac( $method, $url, $timestamp, $nonce ) {
        return base64_encode( hash_hmac( 'sha256', self::normalized_string( $method, $url, $timestamp, $nonce ), $this->secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Build the normalized request string that gets signed
     *
     * @since 2.1.0
     *
     * @param string $method    HTTP method
     * @param string $url       Absolute request URL
     * @param int    $timestamp Unix timestamp
     * @param string $nonce     Nonce
     *
     * @return string
     */
    public static function normalized_string( $method, $url, $timestamp, $nonce ) {
        $parts  = wp_parse_url( (string) $url );
        $parts  = is_array( $parts ) ? $parts : [];
        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
        $host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
        $port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'http' === $scheme ? 80 : 443 );
        $uri    = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

        if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
            $uri .= '?' . $parts['query'];
        }

        $lines = [
            (string) $timestamp,
            (string) $nonce,
            strtoupper( (string) $method ),
            $uri,
            $host,
            (string) $port,
            '',
        ];

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Generate a random nonce of at most 32 characters
     *
     * @since 2.1.0
     *
     * @return string
     */
    public static function generate_nonce() {
        try {
            return bin2hex( random_bytes( self::MAX_NONCE_LENGTH / 2 ) );
        } catch ( Exception $e ) {
            return wp_generate_password( self::MAX_NONCE_LENGTH, false );
        }
    }
}
