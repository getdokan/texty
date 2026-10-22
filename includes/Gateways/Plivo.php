<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Plivo Class
 *
 * @see https://www.plivo.com/docs/sms/api/message#send-a-message
 */
class Plivo extends AbstractGateway {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://api.plivo.com/v1/Account/{auth_id}/Message/';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'plivo';

    /**
     * Gateway metadata
     *
     * @since 2.1.0
     *
     * @return array
     */
    protected function meta() {
        return [
            'status'     => Metadata::STATUS_STABLE,
            'docs_url'   => 'https://www.plivo.com/docs/messaging/api/message',
            'signup_url' => 'https://console.plivo.com/accounts/register/',
            'features'   => [
                'unicode'   => true,
                'sender_id' => true,
            ],
        ];
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'Plivo', 'texty' );
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: URL to Plivo settings and help docs
            __(
                'Send SMS with Plivo. Follow <a href="%1$s" target="_blank">this link</a> to get the Auth ID and Token from Plivo. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://console.plivo.com/sms/reporting/',
            'https://github.com/weDevsOfficial/texty/wiki/Plivo'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/plivo-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'plivo' );

        return [
            'auth_id' => [
                'name'  => __( 'Auth ID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['auth_id'] ) ? $creds['auth_id'] : '',
                'help'  => '',
            ],
            'token'   => [
                'name'  => __( 'Auth Token', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['token'] ) ? $creds['token'] : '',
                'help'  => '',
            ],
            'from'    => [
                'name'  => __( 'From Number', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['from'] ) ? $creds['from'] : '',
                'help'  => __( 'Must be a valid number associated with your Plivo account', 'texty' ),
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
        $auth_id = $this->cred( $creds, 'auth_id' );
        $token   = $this->cred( $creds, 'token' );

        if ( '' === $auth_id || '' === $token ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Plivo Auth ID and Auth Token are required.', 'texty' )
            );
        }

        $response = $this->http()->post(
            $this->endpoint( $auth_id ),
            [
                'headers' => $this->auth_headers( $auth_id, $token ),
                'form'    => [
                    'src'  => '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' ),
                    'dst'  => $to,
                    'text' => $message,
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 202 === $response->status() ) {
            // Plivo returns message_uuid as a list (one id per recipient).
            $uuid = $response->get( 'message_uuid' );

            return Result::success( is_array( $uuid ) ? reset( $uuid ) : $uuid, $response );
        }

        return Result::failure(
            $response->get( 'code', 'plivo_error' ),
            $this->error_message( $response ),
            $response
        );
    }

    /**
     * Validate a REST API request
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds   = $this->request_creds( $request );
        $auth_id = sanitize_text_field( $this->cred( $creds, 'auth_id' ) );
        $token   = sanitize_text_field( $this->cred( $creds, 'token' ) );

        if ( '' === $auth_id || '' === $token ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Plivo Auth ID and Auth Token are required.', 'texty' )
            );
        }

        $response = $this->http()->get(
            $this->endpoint( $auth_id ),
            [
                'headers' => $this->auth_headers( $auth_id, $token ),
                'query'   => [
                    'limit'  => 5,
                    'offset' => 0,
                ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() ) {
            return new WP_Error(
                $response->get( 'code', 'plivo_auth_failed' ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        return [
            'auth_id' => $auth_id,
            'token'   => $token,
            'from'    => sanitize_text_field( $this->cred( $creds, 'from' ) ),
        ];
    }

    /**
     * Message endpoint for an account
     *
     * @param string $auth_id
     *
     * @return string
     */
    private function endpoint( $auth_id ) {
        return str_replace( '{auth_id}', rawurlencode( $auth_id ), self::ENDPOINT );
    }

    /**
     * Build the Basic auth header
     *
     * @param string $auth_id
     * @param string $token
     *
     * @return array
     */
    private function auth_headers( $auth_id, $token ) {
        return [
            'Authorization' => 'Basic ' . base64_encode( $auth_id . ':' . $token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        ];
    }

    /**
     * Pull a readable error from a Plivo error body
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        foreach ( [ 'error', 'detail', 'message' ] as $field ) {
            $value = $response->get( $field );

            if ( is_string( $value ) && '' !== $value ) {
                return $value;
            }
        }

        return '';
    }
}
