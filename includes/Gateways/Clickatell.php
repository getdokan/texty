<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

/**
 * Clickatell Class
 *
 * @see https://www.clickatell.com/developers/api-documentation/rest-api-send-message/
 */
class Clickatell implements GatewayInterface {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://platform.clickatell.com/messages';

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'Clickatell', 'texty' );
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: URL to Twilio settings and help docs
            __(
                'Send SMS with Clickatell. Follow <a href="%1$s" target="_blank">this link</a> to get the API. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://app.clickatell.com/my-workspace',
            'https://github.com/weDevsOfficial/texty/wiki/Clickatell'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/clickatell-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'clickatell' );

        return [
            'key' => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['key'] ) ? $creds['key'] : '',
                'help'  => '',
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
        $creds = texty()->settings()->get( 'clickatell' );

        $args = [
            'headers' => [
                'Authorization' => $creds['key'],
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode(
                [
                    'to'      => [ $to ],
                    'content' => $message,
                ]
            ),
        ];

        $response = wp_remote_post( self::ENDPOINT, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body          = wp_remote_retrieve_body( $response );
        $response_code = wp_remote_retrieve_response_code( $response );

        // phpcs:disable
        if ( 202 !== $response_code ) {
            switch ( $response_code ) {
                case 200:
                    $body = json_decode( $body );

                    return new WP_Error(
                        $body->errorCode,
                        $body->errorDescription
                    );

                case 400:
                    return new WP_Error(
                        400,
                        'Bad Request'
                    );

                default:
                    return new WP_Error(
                        $response_code,
                        'Bad Request'
                    );
            }
        }
        // phpcs:enable
        $body = json_decode( $body );
        $api_message_id = null;
        if ( isset( $body->messages[0]->apiMessageId ) ) {
            $api_message_id = $body->messages[0]->apiMessageId;
        }
        return [
            'success'      => true,
            'reference_id' => $api_message_id,
        ];
    }

    /**
     * Validate a REST API request
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|mixed
     */
    public function validate( $request ) {
        $creds = $request->get_param( 'clickatell' );

        return [
            'key' => $creds['key'],
        ];
    }
}
