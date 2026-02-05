<?php
namespace Texty\Gateways;

use WP_Error;

class Octopush implements GatewayInterface {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://www.octopush-dm.com/api';

    /**
     * Get the name
     *
     * @return string|void
     */
    public function name() {
        return __( 'Octopush', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
        // translators: URL to Octopush settings and help docs
            __(
                'Send SMS with Octopush. Follow <a href="%1$s" target="_blank">this link</a> to get the API Key from Octopush. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://client.octopush.com/',
            'https://github.com/weDevsOfficial/texty/wiki/Octopush'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/octopush.svg';
    }

    /**
     * Get the settings
     *
     * @return array[]
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'octopush' );

        return [
            'user_login' => [
                'name'  => __( 'Email Address', 'texty' ),
                'type'  => 'email',
                'value' => isset( $creds['user_login'] ) ? $creds['user_login'] : '',
                'help'  => __( 'User login (email address)', 'texty' ),
            ],
            'api_key' => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['api_key'] ) ? $creds['api_key'] : '',
                'help'  => __( 'API key available on your manager.', 'texty' ),
            ],
            'sms_type' => [
                'name'  => __( 'SMS Type', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['sms_type'] ) ? $creds['sms_type'] : '',
                'help'  => __( 'SMS Type: XXX = Low Cost SMS; FR = Premium SMS; WWW = Global SMS.', 'texty' ),
            ],
            'sms_sender' => [
                'name'  => __( 'Sender', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['sms_sender'] ) ? $creds['sms_sender'] : '',
                'help'  => __( 'Sender of the message, 3-11 alphanumeric characters (a-zA-Z).', 'texty' ),
            ],
        ];
    }


    /**
     * Send the message
     *
     * @param string $to
     * @param string $message
     * @return array|bool|void|WP_Error
     */
    public function send( $to, $message ) {
        $creds = texty()->settings()->get( 'octopush' );

        $args = [
            'body' => [
                'user_login'        => $creds['user_login'],
                'api_key'           => $creds['api_key'],
                'sms_text'          => $message,
                'sms_recipients'    => $to,
                'sms_type'          => $creds['sms_type'],
                'sms_sender'        => $creds['sms_sender'],
            ],
        ];

        $response = wp_remote_post( self::ENDPOINT . '/sms/json', $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( '000' !== $body->error_code ) {
            return new WP_Error(
                422,
                sprintf(
                // translators: Octopush error code
                    __( 'Error code: %1$s', 'texty' ),
                    $body->error_code
                )
            );
        }

        return true;
    }

    /**
     * Validate a REST API request
     *
     * @param \WP_REST_Request $request
     * @return array|WP_Error
     */
    public function validate( $request ) {
        $creds = $request->get_param( 'octopush' );

        $validate_inputs = $this->validate_inputs( $creds );

        if ( is_wp_error( $validate_inputs ) ) {
            return $validate_inputs;
        }

        $response = $this->ping( $creds );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( '000' !== $body->error_code ) {
            return new WP_Error( 422, __( 'Your API credential was invalid.', 'texty' ) );
        }

        return [
            'user_login' => $creds['user_login'],
            'api_key'    => $creds['api_key'],
            'sms_type'   => $creds['sms_type'],
            'sms_sender' => $creds['sms_sender'],
        ];
    }

    /**
     * Validate settings inputs
     *
     * @param $data
     * @return bool|WP_Error
     */
    protected function validate_inputs( $data ) {
        $message = false;

        if ( ! filter_var( $data['user_login'], FILTER_VALIDATE_EMAIL ) ) {
            $message = __( 'Invalid email address.', 'texty' );
        } elseif ( empty( $data['api_key'] ) ) {
            $message = __( 'Invalid API Key', 'texty' );
        } elseif ( ! preg_match( '/^XXX|FR|WWW$/', $data['sms_type'] ) ) {
            $message = __( 'Invalid SMS type', 'texty' );
        } elseif ( ! preg_match( '/^[a-z]{3,11}$/i', $data['sms_sender'] ) ) {
            $message = __( 'Invalid sender', 'texty' );
        }

        if ( $message ) {
            return new WP_Error( 422, $message );
        }

        return true;
    }

    /**
     * Ping to Octopush API
     *
     * @param $credential
     * @return array|WP_Error
     */
    protected function ping( $credential ) {
        $query = http_build_query(
            [
                'user_login' => $credential['user_login'],
                'api_key'    => $credential['api_key'],
            ]
        );

        return wp_remote_get( self::ENDPOINT . '/balance/json?' . $query );
    }
}
