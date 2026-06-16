<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

/**
 * Plivo Class
 *
 * @see https://www.plivo.com/docs/sms/api/message#send-a-message
 */
class Plivo implements GatewayInterface {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://api.plivo.com/v1/Account/{auth_id}/Message/';

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
        return TEXTY_URL . '/assets/images/plivo-logo.png';
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
     *
     * @return WP_Error|array
     */
    public function send( $to, $message ) {
        $creds = texty()->settings()->get( 'plivo' );

        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['auth_id'] . ':' . $creds['token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            ],
            'body'    => [
                'src'  => $creds['from'],
                'dst'  => $to,
                'text' => $message,
            ],
        ];

        $endpoint = str_replace( '{auth_id}', $creds['auth_id'], self::ENDPOINT );
        $response = wp_remote_post( $endpoint, $args );
        $body     = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 202 !== $response['response']['code'] ) {
            return new WP_Error( $body->code, $body->message );
        }

        return [
            'success'      => true,
            'reference_id' => isset( $body->message_uuid )
                ? ( is_array( $body->message_uuid ) ? ( $body->message_uuid[0] ?? null ) : $body->message_uuid )
                : null,
        ];
    }

    /**
     * Validate a REST API request
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds = $request->get_param( 'plivo' );

        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['auth_id'] . ':' . $creds['token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            ],
            'body'    => [
                'limit'  => 5,
                'offset' => 0,
            ],
        ];

        $endpoint      = "https://api.plivo.com/v1/Account/{$creds['auth_id']}/Message/";
        $response      = wp_remote_get( $endpoint, $args );
        $body          = json_decode( wp_remote_retrieve_body( $response ) );
        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $response_code ) {
            return new WP_Error(
                $body->code,
                $body->detail ? $body->detail : $body->message,
                $body
            );
        }

        return [
            'auth_id' => $creds['auth_id'],
            'token'   => $creds['token'],
            'from'    => $creds['from'],
        ];
    }
}
