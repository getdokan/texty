<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * MSG91 Class
 *
 * Template-only (DLT) gateway: MSG91 builds the SMS text from an approved
 * template, so every send needs `template_id` and the template variables
 * in `variables`. The message text is never sent to the provider.
 *
 * @see https://api.msg91.com/apidoc/textsms/send-sms-flow.php
 *
 * @since 2.1.0
 */
class MSG91 extends AbstractGateway {

    /**
     * Default API base URL
     */
    const BASE_URL = 'https://control.msg91.com';

    /**
     * Send (flow) endpoint path
     */
    const SEND_PATH = '/api/v5/flow';

    /**
     * Balance endpoint path, used by validate()
     */
    const BALANCE_PATH = '/api/balance.php';

    /**
     * Transactional route, used for the balance check
     */
    const TRANSACTIONAL_ROUTE = '4';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'msg91';

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
            'docs_url'          => 'https://docs.msg91.com/sms/send-sms',
            'signup_url'        => 'https://control.msg91.com/signup/',
            'regions'           => [ 'IN' ],
            'features'          => [
                'unicode'       => true,
                'sender_id'     => true,
                'template'      => true,
                'balance_check' => true,
            ],
            'validation'        => 'live',
            'template_required' => true,
            'sandbox'           => false,
        ];
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'MSG91', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the MSG91 panel, 2: URL to the Texty setup guide */
            __(
                'Send DLT template SMS with MSG91. Follow <a href="%1$s" target="_blank">this link</a> to get your Auth Key from the MSG91 panel. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://control.msg91.com/',
            'https://github.com/getdokan/texty/wiki/MSG91'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/msg91.svg';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'auth_key'  => [
                'name'  => __( 'Auth Key', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['auth_key'] ) ? $creds['auth_key'] : '',
                'help'  => __( 'MSG91 panel → user menu → Authkey. If IP Security is on for this key (MSG91 turns it on by default), add your server\'s outbound IP to the key\'s Whitelisted IPs.', 'texty' ),
            ],
            'sender_id' => [
                'name'  => __( 'Sender ID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['sender_id'] ) ? $creds['sender_id'] : '',
                'help'  => __( 'Your DLT-approved 6-character header. It is only used when your MSG91 template\'s sender is set to "FromAPI"; otherwise the sender saved on the template is used.', 'texty' ),
            ],
        ];
    }

    /**
     * Send SMS
     *
     * @param string $to
     * @param string $message Not sent; MSG91 renders the approved template
     * @param array  $options Per-message options, see SendOptions
     *
     * @return Result
     */
    public function send_result( $to, $message, $options = [] ) {
        $options  = SendOptions::normalize( $options );
        $creds    = $this->creds();
        $auth_key = $this->cred( $creds, 'auth_key' );

        if ( '' === $auth_key ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'MSG91 Auth Key is required.', 'texty' )
            );
        }

        if ( '' === $options['template_id'] ) {
            return Result::failure(
                'texty_template_required',
                __( 'MSG91 requires an approved template ID for this notification. Set it under Texty > Notifications.', 'texty' )
            );
        }

        $mobile = $this->normalize_number( $to );

        if ( '' === $mobile ) {
            return Result::failure(
                'texty_invalid_number',
                __( 'MSG91 needs the phone number in international format with the country code, e.g. 919876543210.', 'texty' )
            );
        }

        $body = [
            'template_id' => $options['template_id'],
            'short_url'   => '0',
        ];

        $sender = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender_id' );

        if ( '' !== $sender ) {
            $body['sender'] = $sender;
        }

        if ( $options['unicode'] ) {
            $body['unicode'] = 1;
        }

        $body['recipients'] = [
            array_merge( [ 'mobiles' => $mobile ], $this->template_variables( $options['variables'] ) ),
        ];

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . self::SEND_PATH,
            [
                'headers' => [
                    'authkey' => $auth_key,
                    'Accept'  => 'application/json',
                ],
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        // HTTP 200 can carry an error, so success is decided by the body.
        $type       = $this->body_string( $response, [ 'type', 'msg_type' ] );
        $request_id = $this->body_string( $response, [ 'message', 'msg' ] );

        if ( $response->is_2xx() && 'success' === $type && '' !== $request_id ) {
            return Result::success( $request_id, $response );
        }

        return $this->failure( $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the auth key against the side-effect-free balance endpoint.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds     = $this->request_creds( $request );
        $auth_key  = trim( sanitize_text_field( $this->cred( $creds, 'auth_key' ) ) );
        $sender_id = trim( sanitize_text_field( $this->cred( $creds, 'sender_id' ) ) );

        if ( '' === $auth_key || '' === $sender_id ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'MSG91 Auth Key and Sender ID are required.', 'texty' )
            );
        }

        if ( ! preg_match( '/^[A-Za-z0-9]{3,11}$/', $sender_id ) ) {
            return new WP_Error(
                'msg91_invalid_sender_id',
                __( 'MSG91 Sender ID must be 3 to 11 letters or digits.', 'texty' )
            );
        }

        $sanitized = [
            'auth_key'  => $auth_key,
            'sender_id' => $sender_id,
        ];

        // The balance endpoint only accepts the key as a query parameter.
        // auth_key is a password field, so it is redacted from logs.
        $response = $this->http()->get(
            $this->base_url( self::BASE_URL, $sanitized ) . self::BALANCE_PATH,
            [
                'query' => [
                    'authkey' => $auth_key,
                    'type'    => self::TRANSACTIONAL_ROUTE,
                ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 === $response->status() && is_numeric( trim( $response->body() ) ) ) {
            return $sanitized;
        }

        $code    = $this->body_string( $response, [ 'code' ] );
        $message = '418' === $code
            ? $this->ip_whitelist_message()
            : $this->body_string( $response, [ 'message', 'msg', 'errors' ] );

        return new WP_Error(
            'msg91_invalid_credentials',
            '' !== $message ? $message : __( 'Invalid MSG91 auth key. If IP Security is on for this key, make sure this server\'s IP address is whitelisted.', 'texty' ),
            $response->payload()
        );
    }

    /**
     * Normalize a phone number to MSG91's format: digits with the country
     * code and no `+`
     *
     * @param string $to
     *
     * @return string Empty string when the number is not valid
     */
    private function normalize_number( $to ) {
        $original = is_scalar( $to ) ? (string) $to : '';
        $number   = preg_replace( '/[\s\-().]/', '', $original );

        if ( 0 === strpos( $number, '+' ) ) {
            $number = substr( $number, 1 );
        } elseif ( 0 === strpos( $number, '00' ) ) {
            $number = substr( $number, 2 );
        }

        /**
         * Filter the phone number sent to MSG91, e.g. to prefix a country
         * code to local numbers.
         *
         * @since 2.1.0
         *
         * @param string $number   Number without `+`, spaces or dashes
         * @param string $original Number as passed to the gateway
         */
        $number = (string) apply_filters( 'texty_msg91_normalize_number', $number, $original );

        return preg_match( '/^[1-9]\d{9,14}$/', $number ) ? $number : '';
    }

    /**
     * Prepare template variables for the recipient entry
     *
     * Values are cast to strings; non-scalar values and the reserved
     * recipient keys are dropped so a variable can't replace the number.
     *
     * @param array $variables
     *
     * @return array
     */
    private function template_variables( array $variables ) {
        $prepared = [];

        foreach ( $variables as $name => $value ) {
            $name = (string) $name;

            if ( '' === $name || in_array( strtolower( $name ), [ 'mobiles', 'mobile' ], true ) || ! is_scalar( $value ) ) {
                continue;
            }

            $prepared[ $name ] = (string) $value;
        }

        return $prepared;
    }

    /**
     * Build a failed result from an MSG91 error response
     *
     * @param Response $response
     *
     * @return Result
     */
    private function failure( Response $response ) {
        if ( null === $response->json() ) {
            return Result::failure(
                'msg91_http_' . $response->status(),
                $response->status() >= 500
                    ? __( 'MSG91 server error', 'texty' )
                    : __( 'Unexpected response from MSG91.', 'texty' ),
                $response
            );
        }

        $code    = $this->body_string( $response, [ 'code' ] );
        $message = '418' === $code
            ? $this->ip_whitelist_message()
            : $this->body_string( $response, [ 'message', 'msg', 'errors' ] );

        return Result::failure(
            'msg91_' . ( '' !== $code ? $code : 'error' ),
            '' !== $message ? $message : __( 'Unknown error', 'texty' ),
            $response
        );
    }

    /**
     * Read the first non-empty scalar value from a list of body fields
     *
     * @param Response $response
     * @param string[] $fields
     *
     * @return string
     */
    private function body_string( Response $response, array $fields ) {
        foreach ( $fields as $field ) {
            $value = $response->get( $field );

            if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                return trim( (string) $value );
            }
        }

        return '';
    }

    /**
     * Error text for MSG91 code 418 (server IP not whitelisted)
     *
     * @return string
     */
    private function ip_whitelist_message() {
        return __( 'MSG91 rejected the request because this server\'s IP address is not whitelisted for the auth key. Add it under the auth key\'s Whitelisted IPs in the MSG91 panel.', 'texty' );
    }
}
