<?php

namespace Texty\Gateways;

use WP_Error;

/**
 * Messagebird Class
 *
 * @see https://dashboard.messagebird.com/en/getting-started/sms
 */
class Messagebird implements GatewayInterface {
    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://rest.messagebird.com/messages';

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'Messagebird', 'texty' );
    }


    /**
     * Get the name
     *
     * @return string
     */
    public function description() {
        return sprintf(
        // translators: URL to Messagebird settings and help docs
            __(
                'Send SMS with Messagebird. Follow <a href="%1$s" target="_blank">this link</a> to get the Key from Messagebird. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://developers.messagebird.com/quickstarts/sms-overview/',
            'https://github.com/weDevsOfficial/texty/wiki/Messagebird'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/messagebird.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'messagebird' );

        return [
            'key'   => [
                'name'  => __( 'Key', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['key'] ) ? $creds['key'] : '',
                'help'  => '',
            ],
            'from'    => [
                'name'  => __( 'From Number', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['from'] ) ? $creds['from'] : '',
                'help'  => __( 'Must be a valid number associated with your Messagebird account', 'texty' ),
            ],
        ];
    }

    /**
     * Send SMS
     *
     * @param string $to
     * @param string $message
     *
     * @return WP_Error|true
     */
    public function send( $to, $message ) {
        $creds = texty()->settings()->get( 'messagebird' );

        $args = [
            'headers' => [
                'Authorization' => 'AccessKey ' . $creds['key'], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            ],
            'body'    => [
                'originator' => $creds['from'],
                'recipients' => $to,
                'body' => $message,
            ],
        ];

        $response = wp_remote_post( self::ENDPOINT, $args );
        $body     = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 201 !== $response['response']['code'] ) {
            return new WP_Error(
                $body->errors[0]->code,
                $body->errors ? $body->errors[0]->description : $body->errors,
                $body->errors
            );
        }

        return true;
    }

    /**
     * Validate a REST API request
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds = $request->get_param( 'messagebird' );

        $args = [
            'headers' => [
                'Authorization' => 'AccessKey ' . $creds['key'], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            ],
            'body'    => [
                'limit'  => 10,
                'offset' => 0,
                'status' => 'scheduled',
            ],
        ];

        $response      = wp_remote_get( self::ENDPOINT, $args );
        $body          = json_decode( wp_remote_retrieve_body( $response ) );

        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $response_code ) {
            return new WP_Error(
                $body->errors[0]->code,
                $body->errors ? $body->errors[0]->description : $body->errors,
                $body->errors
            );
        }

        return [
            'key'     => $creds['key'],
            'from'    => $creds['from'],
        ];
    }
}
