<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * SMSAPI Class
 *
 * `sms.do` is a legacy endpoint: errors come back as HTTP 200 with an
 * `error` + `message` body, so a send only counts as successful when the
 * reply carries a message id.
 *
 * @see https://www.smsapi.com/docs/
 *
 * @since 2.1.0
 */
class SMSAPI extends AbstractGateway {

    /**
     * Default region
     */
    const DEFAULT_REGION = 'com';

    /**
     * API hosts per region
     */
    const HOSTS = [
        'com' => 'https://api.smsapi.com',
        'pl'  => 'https://api.smsapi.pl',
    ];

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'smsapi';

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
            'docs_url'          => 'https://www.smsapi.com/docs/',
            'signup_url'        => 'https://www.smsapi.com/',
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
        return __( 'SMSAPI', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: %1$s: URL to the SMSAPI OAuth token page, %2$s: URL to the setup guide
            __(
                'Send SMS with SMSAPI. Follow <a href="%1$s" target="_blank">this link</a> to generate an OAuth token in the SMSAPI Customer Portal. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://ssl.smsapi.com/react/oauth/manage',
            'https://github.com/getdokan/texty/wiki/SMSAPI'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/smsapi-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'token'  => [
                'name'  => __( 'OAuth Token', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'token' ),
                'help'  => __( 'Generate it in the SMSAPI Customer Portal under OAuth Tokens. Grant at least the SMS sending and profile/account scopes.', 'texty' ),
            ],
            'region' => [
                'name'    => __( 'Service region', 'texty' ),
                'type'    => 'select',
                'options' => [
                    'com' => __( 'SMSAPI.com (international)', 'texty' ),
                    'pl'  => __( 'SMSAPI.pl (Poland)', 'texty' ),
                ],
                'value'   => $this->region( $creds ),
                'help'    => __( 'Pick the portal where your account was created. Tokens do not work across the two.', 'texty' ),
            ],
            'from'   => [
                'name'         => __( 'Sender name', 'texty' ),
                'type'         => 'text',
                'value'        => $this->cred( $creds, 'from' ),
                'help'         => __( 'Must be verified in the SMSAPI Customer Portal under Sender names. Leave empty to use the account default.', 'texty' ),
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
        $options = SendOptions::normalize( $options );
        $creds   = $this->creds();
        $token   = $this->cred( $creds, 'token' );

        if ( '' === $token ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'SMSAPI OAuth Token is required.', 'texty' )
            );
        }

        $form = [
            'to'       => $this->normalize_number( $to ),
            'message'  => $message,
            'format'   => 'json',
            'encoding' => 'utf-8',
        ];

        $from = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );

        // Leave `from` out entirely so the account default sender applies.
        if ( '' !== $from ) {
            $form['from'] = $from;
        }

        $response = $this->http()->post(
            $this->endpoint( '/sms.do', $creds ),
            [
                'headers' => $this->auth_headers( $token ),
                'form'    => $form,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 200 === $response->status() && null === $response->get( 'error' ) ) {
            // An empty or missing id becomes `texty_missing_message_id`.
            return Result::success( $response->get( 'list.0.id' ), $response );
        }

        return Result::failure(
            $this->error_code( $response ),
            $this->error_message( $response ),
            $response
        );
    }

    /**
     * Validate a REST API request
     *
     * Calls `GET /profile`, which has no side effects and fails for a bad
     * token or a token from the other region.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $submitted = $this->request_creds( $request );
        $creds     = [
            'token'  => sanitize_text_field( $this->cred( $submitted, 'token' ) ),
            'region' => $this->region( [ 'region' => sanitize_text_field( $this->cred( $submitted, 'region' ) ) ] ),
            'from'   => sanitize_text_field( $this->cred( $submitted, 'from' ) ),
        ];

        if ( '' === $creds['token'] ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'SMSAPI OAuth Token is required.', 'texty' )
            );
        }

        $response = $this->http()->get(
            $this->endpoint( '/profile', $creds ),
            [
                'headers' => $this->auth_headers( $creds['token'] ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        $valid = 200 === $response->status()
            && null === $response->get( 'error' )
            && ( null !== $response->get( 'points' ) || null !== $response->get( 'username' ) );

        if ( $valid ) {
            return $creds;
        }

        $code = $this->error_code( $response );

        if ( 403 === $response->status() || '401' === $code ) {
            return new WP_Error(
                $code,
                __( 'The SMSAPI token was accepted but lacks the profile/account scope. Generate a token with that scope and try again.', 'texty' ),
                $response->payload()
            );
        }

        return new WP_Error( $code, $this->error_message( $response ), $response->payload() );
    }

    /**
     * Resolve the saved region, falling back to `com`
     *
     * Accepts `com` / `pl` as well as `smsapi.com` / `smsapi.pl`.
     *
     * @param array $creds
     *
     * @return string
     */
    private function region( array $creds ) {
        $region = strtolower( $this->cred( $creds, 'region' ) );

        if ( 0 === strpos( $region, 'smsapi.' ) ) {
            $region = substr( $region, strlen( 'smsapi.' ) );
        }

        return in_array( $region, array_keys( self::HOSTS ), true ) ? $region : self::DEFAULT_REGION;
    }

    /**
     * Build an API URL for the configured region
     *
     * @param string $path  Path with a leading slash
     * @param array  $creds Credentials in use
     *
     * @return string
     */
    private function endpoint( $path, array $creds ) {
        return $this->base_url( self::HOSTS[ $this->region( $creds ) ], $creds ) . $path;
    }

    /**
     * Build the Bearer auth header
     *
     * @param string $token
     *
     * @return array
     */
    private function auth_headers( $token ) {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * Normalize a phone number to digits with country code and no `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_number( $to ) {
        return (string) preg_replace( '/\D+/', '', (string) $to );
    }

    /**
     * Error code from an SMSAPI error body, or `smsapi_http_<status>`
     *
     * `sms.do` returns an integer code, `/profile` a string.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $code = $response->get( 'error' );

        if ( is_scalar( $code ) && '' !== (string) $code ) {
            return (string) $code;
        }

        return 'smsapi_http_' . $response->status();
    }

    /**
     * Readable error from an SMSAPI error body
     *
     * Adds the per-number reason from `invalid_numbers` when present.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $message = $response->get( 'message' );
        $message = is_string( $message ) && '' !== $message ? $message : __( 'Unknown error', 'texty' );
        $detail  = $response->get( 'invalid_numbers.0.message' );

        if ( is_string( $detail ) && '' !== $detail && $detail !== $message ) {
            return sprintf(
                // translators: %1$s: SMSAPI error message, %2$s: reason the phone number was rejected
                __( '%1$s (%2$s)', 'texty' ),
                $message,
                $detail
            );
        }

        return $message;
    }
}
