<?php

namespace Texty\Gateways;

use Texty\Http\MacAuth;
use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * SMSGlobal Class
 *
 * Every request is signed with a fresh MAC (HMAC-SHA256) Authorization
 * header built from the API key, API secret, method and exact URL.
 *
 * @see https://www.smsglobal.com/us/rest-api/
 * @see https://api.smsglobal.com/v2/doc
 */
class SMSGlobal extends AbstractGateway {

    /**
     * API base URL
     */
    const BASE_URL = 'https://api.smsglobal.com';

    /**
     * Send path
     */
    const SEND_PATH = '/v2/sms';

    /**
     * Credit balance path (used by validate())
     */
    const BALANCE_PATH = '/v2/user/credit-balance';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'smsglobal';

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
            'docs_url'          => 'https://www.smsglobal.com/us/rest-api/',
            'signup_url'        => 'https://www.smsglobal.com/',
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
        return __( 'SMSGlobal', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the SMSGlobal MXT API keys page, 2: URL to the Texty setup guide */
            __(
                'Send SMS with SMSGlobal. Follow <a href="%1$s" target="_blank">this link</a> to create a REST API key and secret in MXT. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://mxt.smsglobal.com/integrations',
            'https://github.com/getdokan/texty/wiki/SMSGlobal'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/smsglobal-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'api_key'    => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['api_key'] ) ? $creds['api_key'] : '',
                'help'  => __( 'REST API key from MXT → API & Integrations.', 'texty' ),
            ],
            'api_secret' => [
                'name'  => __( 'API Secret', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['api_secret'] ) ? $creds['api_secret'] : '',
                'help'  => __( 'REST API secret, shown once when the key is created in MXT.', 'texty' ),
            ],
            'origin'     => [
                'name'     => __( 'Origin (Sender ID)', 'texty' ),
                'type'     => 'text',
                'value'    => isset( $creds['origin'] ) ? $creds['origin'] : '',
                'help'     => __( 'Number or alphanumeric sender ID (3–11 letters/digits, or 3–15 digits). Leave empty to use your account default.', 'texty' ),
                'required' => false,
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
        $options = SendOptions::normalize( $options );
        $creds   = $this->creds();
        $api_key = $this->cred( $creds, 'api_key' );
        $secret  = $this->cred( $creds, 'api_secret' );

        if ( '' === $api_key || '' === $secret ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'SMSGlobal API Key and API Secret are required.', 'texty' )
            );
        }

        $destination = $this->normalize_number( $to );

        if ( ! preg_match( '/^\d{3,15}$/', $destination ) ) {
            return Result::failure(
                'smsglobal_invalid_number',
                __( 'SMSGlobal needs the destination as 3–15 digits in international format.', 'texty' )
            );
        }

        $body = [
            'destination' => $destination,
            'message'     => (string) $message,
        ];

        // Unicode is detected automatically and templates are not supported
        // on /v2/sms, so only the sender override is mapped.
        $origin = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'origin' );

        if ( '' !== $origin ) {
            $body['origin'] = $origin;
        }

        $url      = $this->base_url( self::BASE_URL, $creds ) . self::SEND_PATH;
        $response = $this->http()->post(
            $url,
            [
                'headers' => $this->auth_headers( $api_key, $secret, 'POST', $url ),
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( ! in_array( $response->status(), [ 200, 202 ], true ) ) {
            return Result::failure( $this->error_code( $response ), $this->error_message( $response ), $response );
        }

        $sent = $this->first_message( $response );

        if ( isset( $sent['status'] ) && is_string( $sent['status'] ) && preg_match( '/fail|reject|error|invalid/i', $sent['status'] ) ) {
            return Result::failure(
                'smsglobal_message_' . sanitize_key( $sent['status'] ),
                sprintf(
                    /* translators: %s: message status returned by SMSGlobal */
                    __( 'SMSGlobal did not accept the message (status: %s).', 'texty' ),
                    $sent['status']
                ),
                $response
            );
        }

        return Result::success( $this->message_id( $sent ), $response );
    }

    /**
     * Validate a REST API request
     *
     * Calls the side-effect-free credit balance endpoint. Post-paid accounts
     * get a 404 there, which still proves the key and secret are valid.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds   = $this->request_creds( $request );
        $api_key = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $secret  = sanitize_text_field( $this->cred( $creds, 'api_secret' ) );
        $origin  = sanitize_text_field( $this->cred( $creds, 'origin' ) );

        if ( '' === $api_key || '' === $secret ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'SMSGlobal API Key and API Secret are required.', 'texty' )
            );
        }

        if ( '' !== $origin && ! preg_match( '/^[A-Za-z0-9]{3,11}$/', $origin ) && ! preg_match( '/^\d{3,15}$/', $origin ) ) {
            return new WP_Error(
                'smsglobal_invalid_origin',
                __( 'The SMSGlobal origin must be 3–11 letters or digits, or a number of 3–15 digits.', 'texty' )
            );
        }

        $url      = $this->base_url( self::BASE_URL, $creds ) . self::BALANCE_PATH;
        $response = $this->http()->get(
            $url,
            [
                'headers' => array_merge(
                    $this->auth_headers( $api_key, $secret, 'GET', $url ),
                    [ 'Accept' => 'application/json' ]
                ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( ! in_array( $response->status(), [ 200, 404 ], true ) ) {
            return new WP_Error(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        return [
            'api_key'    => $api_key,
            'api_secret' => $secret,
            'origin'     => $origin,
        ];
    }

    /**
     * Build the signed request headers
     *
     * @param string $api_key
     * @param string $secret
     * @param string $method
     * @param string $url     Exact URL that will be requested
     *
     * @return array
     */
    private function auth_headers( $api_key, $secret, $method, $url ) {
        return [
            'Authorization' => ( new MacAuth( $api_key, $secret ) )->header( $method, $url ),
        ];
    }

    /**
     * Normalize a phone number to digits only (international, no `+`)
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_number( $to ) {
        $to = preg_replace( '/\D+/', '', (string) $to );

        return is_string( $to ) ? $to : '';
    }

    /**
     * Get the first message object from a send response
     *
     * The API documents a `messages[]` wrapper; SDK mocks also show the
     * single message returned unwrapped, so both shapes are accepted.
     *
     * @param Response $response
     *
     * @return array
     */
    private function first_message( Response $response ) {
        $first = $response->get( 'messages.0' );

        if ( is_array( $first ) ) {
            return $first;
        }

        $json = $response->json();

        return is_array( $json ) && ! isset( $json['messages'] ) ? $json : [];
    }

    /**
     * Pick the message id, preferring `outgoing_id` over the per-part `id`
     *
     * @param array $sent
     *
     * @return string
     */
    private function message_id( array $sent ) {
        foreach ( [ 'outgoing_id', 'id' ] as $field ) {
            if ( isset( $sent[ $field ] ) && is_scalar( $sent[ $field ] ) && '' !== (string) $sent[ $field ] ) {
                return (string) $sent[ $field ];
            }
        }

        return '';
    }

    /**
     * Map an error response to an error code
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $status = $response->status();

        if ( 401 === $status || 403 === $status ) {
            return 'smsglobal_invalid_credentials';
        }

        if ( 402 === $status ) {
            return 'smsglobal_insufficient_credit';
        }

        if ( 404 === $status ) {
            return 'smsglobal_not_found';
        }

        if ( $status >= 500 ) {
            return 'smsglobal_server_error';
        }

        $field = $this->field_error( $response );

        if ( null !== $field ) {
            return 'smsglobal_' . sanitize_key( $field[0] );
        }

        $code = $response->get( 'code' );

        return 'smsglobal_' . ( is_scalar( $code ) && '' !== (string) $code ? sanitize_key( (string) $code ) : (string) $status );
    }

    /**
     * Pull a readable message from an error response
     *
     * Auth failures come back as `text/plain`; other errors as JSON with
     * either field errors or a top-level `message`.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $status = $response->status();
        $detail = $this->error_detail( $response );

        if ( 401 === $status || 403 === $status ) {
            if ( '' === $detail ) {
                $detail = __( 'Unauthorized', 'texty' );
            }

            return sprintf(
                /* translators: %s: error returned by SMSGlobal */
                __( 'SMSGlobal rejected the API key or secret (%s). Check both values and make sure this server’s clock is correct; requests signed with the wrong time are refused.', 'texty' ),
                $detail
            );
        }

        if ( 402 === $status && '' === $detail ) {
            return __( 'Your SMSGlobal account is out of credit.', 'texty' );
        }

        return $detail;
    }

    /**
     * Get the provider's own error text
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_detail( Response $response ) {
        $field = $this->field_error( $response );

        if ( null !== $field ) {
            return $field[1];
        }

        $message = $response->get( 'message' );

        if ( is_string( $message ) && '' !== trim( $message ) ) {
            return trim( $message );
        }

        if ( null === $response->json() ) {
            return trim( wp_html_excerpt( $response->body(), 200 ) );
        }

        return '';
    }

    /**
     * Find the first field-level validation error
     *
     * Walks `errors.<field>.errors[0]` and `data.errors.<field>.errors[0]`.
     *
     * @param Response $response
     *
     * @return array|null [ field, message ] or null
     */
    private function field_error( Response $response ) {
        foreach ( [ 'errors', 'data.errors' ] as $path ) {
            $errors = $response->get( $path );

            if ( ! is_array( $errors ) ) {
                continue;
            }

            foreach ( $errors as $field => $value ) {
                if ( is_array( $value ) && isset( $value['errors'] ) && is_array( $value['errors'] ) ) {
                    $value = reset( $value['errors'] );
                } elseif ( is_array( $value ) ) {
                    $value = reset( $value );
                }

                if ( is_string( $value ) && '' !== trim( $value ) ) {
                    return [ (string) $field, trim( $value ) ];
                }
            }
        }

        return null;
    }
}
