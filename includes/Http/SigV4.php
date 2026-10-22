<?php

namespace Texty\Http;

defined( 'ABSPATH' ) || exit;

/**
 * AWS Signature Version 4 request signer
 *
 * Pure PHP (`hash` / `hash_hmac`), no SDK. Signs the header form of SigV4:
 * every header passed to `sign()` plus `host` and `x-amz-date` is signed,
 * and the payload hash covers the exact body bytes that will be sent, so the
 * body must be serialized before signing and never re-encoded afterwards.
 *
 * Temporary (STS) credentials are not supported: there is no session token
 * input and `x-amz-security-token` is never added.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 *
 * @since 2.1.0
 */
class SigV4 {

    /**
     * Signing algorithm identifier
     */
    const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * Access key ID
     *
     * @var string
     */
    private $access_key_id;

    /**
     * Secret access key
     *
     * @var string
     */
    private $secret_access_key;

    /**
     * AWS region, e.g. us-east-1
     *
     * @var string
     */
    private $region;

    /**
     * Service signing name, e.g. sms-voice
     *
     * @var string
     */
    private $service;

    /**
     * Clock: null (use `time()`), a Unix timestamp, or a callable returning one
     *
     * @var callable|int|null
     */
    private $clock;

    /**
     * Constructor
     *
     * @since 2.1.0
     *
     * @param string            $access_key_id     Access key ID
     * @param string            $secret_access_key Secret access key
     * @param string            $region            AWS region
     * @param string            $service           Service signing name
     * @param callable|int|null $clock             Fixed timestamp or callable for tests; null = `time()`
     */
    public function __construct( $access_key_id, $secret_access_key, $region, $service, $clock = null ) {
        $this->access_key_id     = (string) $access_key_id;
        $this->secret_access_key = (string) $secret_access_key;
        $this->region            = (string) $region;
        $this->service           = (string) $service;
        $this->clock             = $clock;
    }

    /**
     * Sign a request
     *
     * Returns the given headers plus `X-Amz-Date` and `Authorization`. Any
     * existing `X-Amz-Date` / `Authorization` header is replaced. `Host` is
     * signed from the URL (or a passed `Host` header) but not added to the
     * returned headers, since the HTTP transport sets it from the URL.
     *
     * @since 2.1.0
     *
     * @param string $method  HTTP method
     * @param string $url     Absolute request URL
     * @param array  $headers Headers to send and sign, name => value
     * @param string $body    Exact request body
     *
     * @return array Headers to send
     */
    public function sign( $method, $url, array $headers = [], $body = '' ) {
        $now        = $this->now();
        $amz_date   = gmdate( 'Ymd\THis\Z', $now );
        $date_stamp = gmdate( 'Ymd', $now );

        foreach ( array_keys( $headers ) as $name ) {
            if ( in_array( strtolower( (string) $name ), [ 'x-amz-date', 'authorization' ], true ) ) {
                unset( $headers[ $name ] );
            }
        }

        $headers['X-Amz-Date'] = $amz_date;

        $canonical_request = $this->canonical_request( $method, $url, $headers, hash( 'sha256', (string) $body ) );
        $credential_scope  = $this->credential_scope( $date_stamp );
        $string_to_sign    = $this->string_to_sign( $amz_date, $credential_scope, $canonical_request );
        $signature         = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date_stamp ) );

        $headers['Authorization'] = sprintf(
            '%1$s Credential=%2$s/%3$s, SignedHeaders=%4$s, Signature=%5$s',
            self::ALGORITHM,
            $this->access_key_id,
            $credential_scope,
            $this->signed_headers( $url, $headers ),
            $signature
        );

        return $headers;
    }

    /**
     * Build the canonical request
     *
     * @since 2.1.0
     *
     * @param string $method       HTTP method
     * @param string $url          Absolute request URL
     * @param array  $headers      Headers to sign (must already include X-Amz-Date)
     * @param string $payload_hash Lowercase hex SHA-256 of the body
     *
     * @return string
     */
    public function canonical_request( $method, $url, array $headers, $payload_hash ) {
        $canonical_headers = '';

        foreach ( $this->canonical_headers( $url, $headers ) as $name => $value ) {
            $canonical_headers .= $name . ':' . $value . "\n";
        }

        return implode(
            "\n",
            [
                strtoupper( (string) $method ),
                $this->canonical_uri( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
                $this->canonical_query( (string) wp_parse_url( $url, PHP_URL_QUERY ) ),
                $canonical_headers,
                $this->signed_headers( $url, $headers ),
                (string) $payload_hash,
            ]
        );
    }

    /**
     * Build the string to sign
     *
     * @since 2.1.0
     *
     * @param string $amz_date          ISO 8601 basic timestamp, e.g. 20150830T123600Z
     * @param string $credential_scope  See `credential_scope()`
     * @param string $canonical_request See `canonical_request()`
     *
     * @return string
     */
    public function string_to_sign( $amz_date, $credential_scope, $canonical_request ) {
        return implode(
            "\n",
            [
                self::ALGORITHM,
                (string) $amz_date,
                (string) $credential_scope,
                hash( 'sha256', (string) $canonical_request ),
            ]
        );
    }

    /**
     * Credential scope for a date
     *
     * @since 2.1.0
     *
     * @param string $date_stamp Date as Ymd
     *
     * @return string
     */
    public function credential_scope( $date_stamp ) {
        return $date_stamp . '/' . $this->region . '/' . $this->service . '/aws4_request';
    }

    /**
     * Derive the signing key for a date
     *
     * @since 2.1.0
     *
     * @param string $date_stamp Date as Ymd
     *
     * @return string Raw binary key
     */
    public function signing_key( $date_stamp ) {
        $k_date    = hash_hmac( 'sha256', (string) $date_stamp, 'AWS4' . $this->secret_access_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
        $k_service = hash_hmac( 'sha256', $this->service, $k_region, true );

        return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
    }

    /**
     * Current Unix timestamp from the injected clock
     *
     * @since 2.1.0
     *
     * @return int
     */
    public function now() {
        if ( is_callable( $this->clock ) ) {
            return (int) call_user_func( $this->clock );
        }

        if ( is_numeric( $this->clock ) ) {
            return (int) $this->clock;
        }

        return time();
    }

    /**
     * Lower-cased, trimmed, sorted headers to sign, including host
     *
     * @param string $url
     * @param array  $headers
     *
     * @return array name => value
     */
    private function canonical_headers( $url, array $headers ) {
        $canonical = [
            'host' => $this->host( $url ),
        ];

        foreach ( $headers as $name => $value ) {
            $name = strtolower( trim( (string) $name ) );

            if ( '' === $name ) {
                continue;
            }

            $value = is_array( $value ) ? implode( ',', $value ) : (string) $value;

            $canonical[ $name ] = preg_replace( '/\s+/', ' ', trim( $value ) );
        }

        ksort( $canonical, SORT_STRING );

        return $canonical;
    }

    /**
     * Semicolon-separated list of signed header names
     *
     * @param string $url
     * @param array  $headers
     *
     * @return string
     */
    private function signed_headers( $url, array $headers ) {
        return implode( ';', array_keys( $this->canonical_headers( $url, $headers ) ) );
    }

    /**
     * Host header value for a URL (port kept when non-default)
     *
     * @param string $url
     *
     * @return string
     */
    private function host( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $port = wp_parse_url( $url, PHP_URL_PORT );

        if ( $port && 443 !== (int) $port ) {
            $host .= ':' . (int) $port;
        }

        return $host;
    }

    /**
     * Canonical URI: each path segment URI-encoded, empty path becomes "/"
     *
     * @param string $path
     *
     * @return string
     */
    private function canonical_uri( $path ) {
        if ( '' === $path ) {
            return '/';
        }

        $segments = array_map(
            function ( $segment ) {
                return rawurlencode( rawurldecode( $segment ) );
            },
            explode( '/', $path )
        );

        return implode( '/', $segments );
    }

    /**
     * Canonical query string: encoded pairs sorted by name, then value
     *
     * @param string $query
     *
     * @return string
     */
    private function canonical_query( $query ) {
        if ( '' === $query ) {
            return '';
        }

        $pairs = [];

        foreach ( explode( '&', $query ) as $part ) {
            if ( '' === $part ) {
                continue;
            }

            $pair    = explode( '=', $part, 2 );
            $pairs[] = [
                rawurlencode( rawurldecode( $pair[0] ) ),
                isset( $pair[1] ) ? rawurlencode( rawurldecode( $pair[1] ) ) : '',
            ];
        }

        usort(
            $pairs,
            function ( $a, $b ) {
                $by_name = strcmp( $a[0], $b[0] );

                return 0 !== $by_name ? $by_name : strcmp( $a[1], $b[1] );
            }
        );

        return implode(
            '&',
            array_map(
                function ( $pair ) {
                    return $pair[0] . '=' . $pair[1];
                },
                $pairs
            )
        );
    }
}
