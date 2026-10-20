<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

/**
 * Twilio Class
 *
 * @see https://www.twilio.com/docs/sms/api/message
 */
class Twilio implements GatewayInterface {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json';

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'Twilio', 'texty' );
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
                'Send SMS with Twilio. Follow <a href="%1$s" target="_blank">this link</a> to get the Account SID and Token from Twilio. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://www.twilio.com/console/project/settings',
            'https://github.com/weDevsOfficial/texty/wiki/Twilio'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/twilio-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'twilio' );

        return [
            'sid' => [
                'name'  => __( 'Account SID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['sid'] ) ? $creds['sid'] : '',
                'help'  => '',
            ],
            'token' => [
                'name'  => __( 'Auth Token', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['token'] ) ? $creds['token'] : '',
                'help'  => '',
            ],
            'from' => [
                'name'  => __( 'From Number', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['from'] ) ? $creds['from'] : '',
                'help'  => __( 'Must be a valid number associated with your Twilio account', 'texty' ),
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
        $creds = texty()->settings()->get( 'twilio' );

        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            ],
            'body' => [
                'From' => $creds['from'],
                'To'   => $to,
                'Body' => $message,
            ],
        ];

        $endpoint = str_replace( '{sid}', $creds['sid'], self::ENDPOINT );
        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 201 !== $response['response']['code'] ) {
            return new WP_Error( $body->code, $body->message );
        }

        return [
            'success'      => true,
            'reference_id' => isset( $body->sid ) ? $body->sid : null,
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
        $creds = $request->get_param( 'twilio' );

        if ( ! is_array( $creds ) || empty( $creds['sid'] ) || empty( $creds['token'] ) ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Twilio Account SID and Auth Token are required.', 'texty' )
            );
        }

        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            ],
        ];

        $endpoint      = 'https://api.twilio.com/2010-04-01/Accounts.json';
        $response      = wp_remote_get( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body          = json_decode( wp_remote_retrieve_body( $response ) );
        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 401 === $response_code ) {
            $code = isset( $body->code ) ? $body->code : 'twilio_auth_failed';

            if ( ! empty( $body->detail ) ) {
                $message = $body->detail;
            } elseif ( ! empty( $body->message ) ) {
                $message = $body->message;
            } else {
                $message = __( 'Twilio rejected the credentials.', 'texty' );
            }

            return new WP_Error( $code, $message, $body );
        }

        return [
            'sid'   => $creds['sid'],
            'token' => $creds['token'],
            'from'  => isset( $creds['from'] ) ? $creds['from'] : '',
        ];
    }
}
