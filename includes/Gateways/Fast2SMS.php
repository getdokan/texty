<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Fast2SMS Class
 *
 * India-only gateway. Two routes are supported: `q` (Quick SMS, sends the
 * full message text, no DLT registration needed) and `dlt` (sends a
 * DLT-approved Message ID plus pipe-separated variables).
 *
 * @see https://docs.fast2sms.com/
 */
class Fast2SMS extends AbstractGateway {

    /**
     * Default API base URL
     */
    const BASE_URL = 'https://www.fast2sms.com';

    /**
     * Quick SMS route (free text)
     */
    const ROUTE_QUICK = 'q';

    /**
     * DLT route (approved template ID + variables)
     */
    const ROUTE_DLT = 'dlt';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'fast2sms';

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
            'docs_url'          => 'https://docs.fast2sms.com/',
            'signup_url'        => 'https://www.fast2sms.com/',
            'regions'           => [ 'IN' ],
            'features'          => [
                'unicode'       => false,
                'sender_id'     => true,
                'template'      => true,
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
        return __( 'Fast2SMS', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the Fast2SMS Dev API page, 2: URL to the setup guide */
            __(
                'Send SMS to Indian mobile numbers with Fast2SMS. Follow <a href="%1$s" target="_blank">this link</a> to get your API key. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://www.fast2sms.com/dashboard/dev-api',
            'https://github.com/getdokan/texty/wiki/Fast2SMS'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/fast2sms-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();
        $route = $this->cred( $creds, 'route' );

        return [
            'api_key'   => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_key' ),
                'help'  => __( 'Found in your Fast2SMS dashboard under Dev API.', 'texty' ),
            ],
            'route'     => [
                'name'    => __( 'Route', 'texty' ),
                'type'    => 'select',
                'options' => [
                    self::ROUTE_QUICK => __( 'Quick SMS', 'texty' ),
                    self::ROUTE_DLT   => __( 'DLT', 'texty' ),
                ],
                'value'   => in_array( $route, $this->routes(), true ) ? $route : self::ROUTE_QUICK,
                'help'    => __( 'Quick SMS: any message text, no DLT needed, random sender, premium per-SMS price. DLT: uses your DLT-approved Sender ID and a DLT Message ID per notification (cheapest).', 'texty' ),
            ],
            'sender_id' => [
                'name'     => __( 'Sender ID', 'texty' ),
                'type'     => 'text',
                'value'    => $this->cred( $creds, 'sender_id' ),
                'help'     => __( 'Your 3–6 letter DLT-approved Sender ID (header), added in DLT Manager. Required for the DLT route, ignored for Quick SMS.', 'texty' ),
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

        if ( '' === $api_key ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Fast2SMS API Key is required.', 'texty' )
            );
        }

        $number = $this->normalize_number( $to );

        if ( '' === $number ) {
            return Result::failure(
                'fast2sms_invalid_number',
                __( 'Fast2SMS can only send to 10-digit Indian mobile numbers.', 'texty' )
            );
        }

        $body = $this->build_body( $this->route( $creds ), $number, $message, $options, $creds );

        if ( $body instanceof Result ) {
            return $body;
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/dev/bulkV2',
            [
                'headers' => $this->auth_headers( $api_key ),
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 200 === $response->status() && $this->is_true( $response->get( 'return' ) ) ) {
            return Result::success( $response->get( 'request_id' ), $response );
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
     * Checks the route and sender ID format, then calls the side-effect-free
     * wallet balance endpoint to verify the API key.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds     = $this->request_creds( $request );
        $api_key   = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $route     = strtolower( sanitize_text_field( $this->cred( $creds, 'route' ) ) );
        $sender_id = sanitize_text_field( $this->cred( $creds, 'sender_id' ) );

        if ( '' === $route ) {
            $route = self::ROUTE_QUICK;
        }

        if ( '' === $api_key ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Fast2SMS API Key is required.', 'texty' )
            );
        }

        if ( ! in_array( $route, $this->routes(), true ) ) {
            return new WP_Error(
                'fast2sms_invalid_route',
                __( 'Fast2SMS route must be "q" (Quick SMS) or "dlt" (DLT).', 'texty' )
            );
        }

        if ( self::ROUTE_DLT === $route && ! preg_match( '/^[A-Za-z]{3,6}$/', $sender_id ) ) {
            return new WP_Error(
                'fast2sms_invalid_sender_id',
                __( 'Fast2SMS DLT route requires a 3–6 letter DLT-approved Sender ID.', 'texty' )
            );
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/dev/wallet',
            [
                'headers' => $this->auth_headers( $api_key ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() || ! $this->is_true( $response->get( 'return' ) ) ) {
            return new WP_Error(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        return [
            'api_key'   => $api_key,
            'route'     => $route,
            'sender_id' => $sender_id,
        ];
    }

    /**
     * Build the bulkV2 JSON body for a route
     *
     * @param string $route
     * @param string $number  Normalized 10-digit number
     * @param string $message
     * @param array  $options Normalized send options
     * @param array  $creds
     *
     * @return array|Result Body, or a failure when the route's inputs are missing
     */
    private function build_body( $route, $number, $message, array $options, array $creds ) {
        if ( self::ROUTE_QUICK === $route ) {
            return [
                'route'   => self::ROUTE_QUICK,
                'message' => (string) $message,
                'numbers' => $number,
            ];
        }

        $template_id = $options['template_id'];

        if ( '' === $template_id ) {
            return Result::failure(
                'texty_template_required',
                __( 'Fast2SMS DLT route requires a DLT Message ID for this notification.', 'texty' )
            );
        }

        if ( ! ctype_digit( $template_id ) ) {
            return Result::failure(
                'fast2sms_invalid_template_id',
                __( 'Fast2SMS DLT Message ID must contain digits only.', 'texty' )
            );
        }

        $sender_id = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender_id' );

        if ( '' === $sender_id ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Fast2SMS DLT route requires a Sender ID.', 'texty' )
            );
        }

        $body = [
            'route'     => self::ROUTE_DLT,
            'sender_id' => $sender_id,
            'message'   => $template_id,
            'numbers'   => $number,
        ];

        $variables = [];

        foreach ( array_values( $options['variables'] ) as $value ) {
            if ( ! is_scalar( $value ) || false !== strpos( (string) $value, '|' ) ) {
                return Result::failure(
                    'fast2sms_invalid_variable',
                    __( 'Fast2SMS template variables must be plain text and cannot contain "|".', 'texty' )
                );
            }

            $variables[] = (string) $value;
        }

        if ( ! empty( $variables ) ) {
            $body['variables_values'] = implode( '|', $variables );
        }

        return $body;
    }

    /**
     * Supported routes
     *
     * @return string[]
     */
    private function routes() {
        return [ self::ROUTE_QUICK, self::ROUTE_DLT ];
    }

    /**
     * Saved route, falling back to Quick SMS
     *
     * @param array $creds
     *
     * @return string
     */
    private function route( array $creds ) {
        $route = strtolower( $this->cred( $creds, 'route' ) );

        return in_array( $route, $this->routes(), true ) ? $route : self::ROUTE_QUICK;
    }

    /**
     * Normalize a phone number to the bare 10-digit Indian mobile format
     *
     * @param string $to
     *
     * @return string Empty string when the number isn't an Indian mobile
     */
    private function normalize_number( $to ) {
        $digits = preg_replace( '/\D+/', '', (string) $to );

        if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '91' ) ) {
            $digits = substr( $digits, 2 );
        } elseif ( 11 === strlen( $digits ) && 0 === strpos( $digits, '0' ) ) {
            $digits = substr( $digits, 1 );
        }

        return preg_match( '/^[6-9]\d{9}$/', $digits ) ? $digits : '';
    }

    /**
     * Build the auth header (raw key, no scheme prefix)
     *
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $api_key ) {
        return [ 'Authorization' => $api_key ];
    }

    /**
     * Whether a `return` value means success
     *
     * bulkV2 documents a boolean, the wallet endpoint a string.
     *
     * @param mixed $value
     *
     * @return bool
     */
    private function is_true( $value ) {
        return true === $value || 'true' === $value;
    }

    /**
     * Error code from a Fast2SMS error body
     *
     * The body's `status_code` can collide with HTTP status numbers, so it is
     * always read from the body and prefixed separately from the HTTP fallback.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $code = $response->get( 'status_code' );

        if ( is_scalar( $code ) && '' !== (string) $code ) {
            return 'fast2sms_' . $code;
        }

        return 'fast2sms_http_' . $response->status();
    }

    /**
     * Readable error from a Fast2SMS error body
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $message = $response->get( 'message' );

        if ( is_array( $message ) ) {
            $message = implode( ' ', array_filter( array_map( 'strval', array_filter( $message, 'is_scalar' ) ) ) );
        }

        if ( is_string( $message ) && '' !== trim( $message ) ) {
            return $message;
        }

        return __( 'Fast2SMS returned an unknown error.', 'texty' );
    }
}
