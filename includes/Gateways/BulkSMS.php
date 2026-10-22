<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * BulkSMS Class
 *
 * JSON REST API v1 with HTTP Basic auth built from an API token.
 *
 * @see https://www.bulksms.com/developer/json/v1/
 *
 * @since 2.1.0
 */
class BulkSMS extends AbstractGateway {

    /**
     * API base URL
     */
    const ENDPOINT = 'https://api.bulksms.com/v1';

    /**
     * Maximum message parts BulkSMS may split a long message into
     *
     * The API default is 3, which a long or unicode notification can exceed.
     */
    const MAX_PARTS = 10;

    /**
     * Message status types that mean the message was accepted
     */
    const ACCEPTED_STATUSES = [ 'ACCEPTED', 'SCHEDULED', 'SENT', 'DELIVERED' ];

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'bulksms';

    /**
     * Gateway metadata
     *
     * @since 2.1.0
     *
     * @return array
     */
    protected function meta() {
        return [
            'status'            => Metadata::STATUS_BETA,
            'docs_url'          => 'https://www.bulksms.com/developer/json/v1/',
            'signup_url'        => 'https://www.bulksms.com/',
            'regions'           => [],
            'features'          => [
                'unicode'       => true,
                'sender_id'     => true,
                'template'      => false,
                'balance_check' => true,
            ],
            'validation'        => 'live',
            'template_required' => false,
            'sandbox'           => false,
        ];
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'BulkSMS', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the BulkSMS API token docs, 2: URL to the Texty setup guide */
            __(
                'Send SMS with BulkSMS. Follow <a href="%1$s" target="_blank">this link</a> to learn how to create an API token (Settings > Developer Settings > API Tokens). Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://www.bulksms.com/developer/json/v1/',
            'https://github.com/getdokan/texty/wiki/BulkSMS'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/bulksms-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'token_id'     => [
                'name'  => __( 'Token ID', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'token_id' ),
                'help'  => __( 'Create in BulkSMS: Settings > Developer Settings > API Tokens', 'texty' ),
            ],
            'token_secret' => [
                'name'  => __( 'Token Secret', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'token_secret' ),
                'help'  => __( 'Shown once when the token is created', 'texty' ),
            ],
            'from'         => [
                'name'         => __( 'From (Sender ID)', 'texty' ),
                'type'         => 'text',
                'value'        => $this->cred( $creds, 'from' ),
                'help'         => __( 'Optional. Alphanumeric (max 11 chars) or international number (7-15 digits, may start with +). Leave empty to use the BulkSMS default.', 'texty' ),
                'required'     => false,
                'alphanumeric' => true,
            ],
        ];
    }

    /**
     * Send SMS
     *
     * @param string $to
     * @param string $message
     * @param array  $options Per-message options, see SendOptions
     *
     * @return Result
     */
    public function send_result( $to, $message, $options = [] ) {
        $options      = SendOptions::normalize( $options );
        $creds        = $this->creds();
        $token_id     = $this->cred( $creds, 'token_id' );
        $token_secret = $this->cred( $creds, 'token_secret' );

        if ( '' === $token_id || '' === $token_secret ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'BulkSMS Token ID and Token Secret are required.', 'texty' )
            );
        }

        $body = [
            'to'   => $this->normalize_to( $to ),
            'body' => (string) $message,
        ];

        $from = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );

        if ( '' !== $from ) {
            $body['from'] = $from;
        }

        if ( true === $options['unicode'] ) {
            $body['encoding'] = 'UNICODE';
        }

        $body['longMessageMaxParts'] = self::MAX_PARTS;

        $response = $this->http()->post(
            $this->base_url( self::ENDPOINT, $creds ) . '/messages',
            [
                'headers' => $this->auth_headers( $token_id, $token_secret ),
                // Non-GSM text (e.g. Bangla) would otherwise degrade to `?`.
                'query'   => [ 'auto-unicode' => 'true' ],
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 201 !== $response->status() ) {
            return Result::failure(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response
            );
        }

        $entry = $response->get( '0' );

        if ( ! is_array( $entry ) ) {
            $entry = $response->json();
        }

        if ( ! is_array( $entry ) ) {
            return Result::failure(
                'bulksms_unexpected_response',
                __( 'BulkSMS returned an unexpected response.', 'texty' ),
                $response
            );
        }

        $status_type = isset( $entry['status']['type'] ) && is_string( $entry['status']['type'] ) ? strtoupper( $entry['status']['type'] ) : '';

        if ( ! in_array( $status_type, self::ACCEPTED_STATUSES, true ) ) {
            return $this->message_failure( $entry, $response );
        }

        return Result::success( isset( $entry['id'] ) ? $entry['id'] : null, $response );
    }

    /**
     * Validate a REST API request
     *
     * Calls `GET /v1/profile`, which has no side effects.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds    = $this->request_creds( $request );
        $token_id = sanitize_text_field( $this->cred( $creds, 'token_id' ) );
        // sanitize_text_field() would strip `%xx` octets and tags from the
        // secret, so only control characters are removed.
        $token_secret = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $this->cred( $creds, 'token_secret' ) );
        $from         = sanitize_text_field( $this->cred( $creds, 'from' ) );

        if ( '' === $token_id || '' === $token_secret ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'BulkSMS Token ID and Token Secret are required.', 'texty' )
            );
        }

        if ( '' !== $from && ! $this->is_valid_sender( $from ) ) {
            return new WP_Error(
                'texty_invalid_sender',
                __( 'The BulkSMS sender must be up to 11 letters, digits or spaces, or an international number of 7-15 digits.', 'texty' )
            );
        }

        $response = $this->http()->get(
            $this->base_url( self::ENDPOINT, $creds ) . '/profile',
            [
                'headers' => array_merge(
                    $this->auth_headers( $token_id, $token_secret ),
                    [ 'Accept' => 'application/json' ]
                ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() || null === $response->get( 'id' ) ) {
            return new WP_Error(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        return [
            'token_id'     => $token_id,
            'token_secret' => $token_secret,
            'from'         => $from,
        ];
    }

    /**
     * Normalize a phone number to E.164 with a leading `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_to( $to ) {
        return '+' . preg_replace( '/\D+/', '', (string) $to );
    }

    /**
     * Check a sender against the `from.address` rules
     *
     * A value with a non-digit and no leading `+` is alphanumeric, anything
     * else is an international number.
     *
     * @param string $from
     *
     * @return bool
     */
    private function is_valid_sender( $from ) {
        if ( 1 === preg_match( '/^\+?\d+$/', $from ) ) {
            return 1 === preg_match( '/^\+?\d{7,15}$/', $from );
        }

        if ( 0 === strpos( $from, '+' ) ) {
            return false;
        }

        return 1 === preg_match( '/^[A-Za-z0-9 ]{1,11}$/', $from );
    }

    /**
     * Build the Basic auth header
     *
     * @param string $token_id
     * @param string $token_secret
     *
     * @return array
     */
    private function auth_headers( $token_id, $token_secret ) {
        return [
            'Authorization' => 'Basic ' . base64_encode( $token_id . ':' . $token_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        ];
    }

    /**
     * Failure for a message BulkSMS created but did not accept
     *
     * @param array    $entry    Message object from the response
     * @param Response $response
     *
     * @return Result
     */
    private function message_failure( array $entry, Response $response ) {
        $status    = isset( $entry['status'] ) && is_array( $entry['status'] ) ? $entry['status'] : [];
        $status_id = isset( $status['id'] ) && is_string( $status['id'] ) && '' !== $status['id'] ? $status['id'] : '';
        $type      = isset( $status['type'] ) && is_string( $status['type'] ) ? strtoupper( $status['type'] ) : '';
        $subtype   = isset( $status['subtype'] ) && is_string( $status['subtype'] ) ? strtoupper( $status['subtype'] ) : '';

        if ( '' === $status_id ) {
            $status_id = '' !== $type ? $type : 'unknown';
        }

        $descriptions = [
            'EXPIRED'       => __( 'The message expired before it could be delivered.', 'texty' ),
            'HANDSET_ERROR' => __( 'The recipient handset could not receive the message.', 'texty' ),
            'BLOCKED'       => __( 'The message was blocked.', 'texty' ),
            'NOT_SENT'      => __( 'BulkSMS could not find a route for the phone number.', 'texty' ),
        ];

        return Result::failure(
            'bulksms_' . strtolower( $status_id ),
            isset( $descriptions[ $subtype ] ) ? $descriptions[ $subtype ] : __( 'BulkSMS did not accept the message.', 'texty' ),
            $response
        );
    }

    /**
     * Error code from an RFC 7807-style error body
     *
     * The `type` URL is `.../errors#<code>` or `.../errors/#<code>`.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $type = $response->get( 'type' );

        if ( is_string( $type ) ) {
            $hash = strrpos( $type, '#' );

            if ( false !== $hash ) {
                $code = sanitize_key( substr( $type, $hash + 1 ) );

                if ( '' !== $code ) {
                    return 'bulksms_' . $code;
                }
            }
        }

        return 'bulksms_http_' . $response->status();
    }

    /**
     * Error message from an RFC 7807-style error body
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        foreach ( [ 'detail', 'title' ] as $field ) {
            $value = $response->get( $field );

            if ( is_string( $value ) && '' !== trim( $value ) ) {
                return trim( $value );
            }
        }

        return sprintf(
            /* translators: %d: HTTP status code */
            __( 'BulkSMS returned an unexpected response (HTTP %d).', 'texty' ),
            $response->status()
        );
    }
}
