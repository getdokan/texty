<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

/**
 * Nexmo Class
 *
 * @see https://developer.nexmo.com/api/sms
 */
class Vonage implements GatewayInterface {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://rest.nexmo.com';

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'Vonage (nexmo)', 'texty' );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/vonage-logo.png';
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: Vonage dashboard settings URL and help docs
            __(
                'Send SMS with Vonage (formerly Nexmo). Follow <a href="%1$s" target="_blank">this link</a> to get the API Key and Secret from Vonage.  Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://dashboard.nexmo.com/settings',
            'https://github.com/weDevsOfficial/texty/wiki/Vonage'
        );
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'vonage' );

        return [
            'key' => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['key'] ) ? $creds['key'] : '',
                'help'  => '',
            ],
            'secret' => [
                'name'  => __( 'API Secret', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['secret'] ) ? $creds['secret'] : '',
                'help'  => '',
            ],
            'from' => [
                'name'  => __( 'From Number', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['from'] ) ? $creds['from'] : '',
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
        $creds = texty()->settings()->get( 'vonage' );

        $args = [
            'body' => [
                'from'       => $creds['from'],
                'text'       => $message,
                'to'         => $to,
                'api_key'    => $creds['key'],
                'api_secret' => $creds['secret'],
            ],
        ];

        $request       = wp_remote_post( self::ENDPOINT . '/sms/json', $args );
        $body          = json_decode( wp_remote_retrieve_body( $request ) );
        $response_code = wp_remote_retrieve_response_code( $request );

        if ( is_wp_error( $request ) ) {
            return $request;
        }

        if ( $body->messages[0]->status !== '0' ) {
            return new WP_Error(
                $body->messages[0]->status,
                $body->messages[0]->{'error-text'}
            );
        }

        return [
            'success'      => true,
            'reference_id' => isset( $body->messages[0]->{'message-id'} ) ? $body->messages[0]->{'message-id'} : null,
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
        $creds = $request->get_param( 'vonage' );

        $args = [
            'api_key'    => $creds['key'],
            'api_secret' => $creds['secret'],
        ];

        $endpoint      = self::ENDPOINT . '/account/get-balance?' . http_build_query( $args );
        $response      = wp_remote_get( $endpoint, $args );
        $body          = json_decode( wp_remote_retrieve_body( $response ) );
        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 401 === $response_code ) {
            return new WP_Error(
                $body->{'error-code'},
                $body->{'error-code-label'}
            );
        }

        return [
            'key'    => $creds['key'],
            'secret' => $creds['secret'],
            'from'   => $creds['from'],
        ];
    }
}
