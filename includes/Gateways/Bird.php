<?php

namespace Texty\Gateways;

use WP_Error;

/**
 * Bird Class
 *
 * @see https://docs.bird.com/api/channels-api/send-message
 */
class Bird implements GatewayInterface {
    /**
     * API Base URL
     */
    const API_BASE = 'https://api.bird.com';

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'Bird', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
        // translators: URL to Bird settings and help docs
            __(
                'Send SMS with Bird. Follow <a href="%1$s" target="_blank">this link</a> to get started with Bird. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://docs.bird.com/api/quickstarts/send-an-sms-message',
            'https://github.com/getdokan/texty/wiki/Bird'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/bird.svg';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'bird' );

        return [
            'access_key'   => [
                'name'  => __( 'Access Key', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['access_key'] ) ? $creds['access_key'] : '',
                'help'  => '',
            ],
            'workspace_id' => [
                'name'  => __( 'Workspace ID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['workspace_id'] ) ? $creds['workspace_id'] : '',
                'help'  => __( 'Found in your Bird dashboard workspace settings', 'texty' ),
            ],
            'channel_id'   => [
                'name'  => __( 'Channel ID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['channel_id'] ) ? $creds['channel_id'] : '',
                'help'  => __( 'The SMS channel ID from your Bird workspace', 'texty' ),
            ],
        ];
    }

    /**
     * Get the API endpoint for sending messages
     *
     * @param string $workspace_id
     * @param string $channel_id
     *
     * @return string
     */
    private function get_endpoint( $workspace_id, $channel_id ) {
        return sprintf(
            '%s/workspaces/%s/channels/%s/messages',
            self::API_BASE,
            $workspace_id,
            $channel_id
        );
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
        $creds    = texty()->settings()->get( 'bird' );
        $endpoint = $this->get_endpoint( $creds['workspace_id'], $creds['channel_id'] );

        $args = [
            'headers' => [
                'Authorization' => 'AccessKey ' . $creds['access_key'],
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'receiver' => [
                    'contacts' => [
                        [
                            'identifierValue' => '+' . ltrim( $to, '+' ),
                        ],
                    ],
                ],
                'body'     => [
                    'type' => 'text',
                    'text' => [
                        'text' => $message,
                    ],
                ],
            ] ),
        ];

        $response      = wp_remote_post( $endpoint, $args );
        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 202 !== $response_code ) {
            $error_message = isset( $body->errors[0]->description ) ? $body->errors[0]->description : __( 'Unknown error', 'texty' );
            $error_code    = isset( $body->errors[0]->code ) ? $body->errors[0]->code : 'bird_error';

            return new WP_Error( $error_code, $error_message, $body );
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
        $creds = $request->get_param( 'bird' );

        $endpoint = sprintf(
            '%s/workspaces/%s',
            self::API_BASE,
            $creds['workspace_id']
        );

        $args = [
            'headers' => [
                'Authorization' => 'AccessKey ' . $creds['access_key'],
                'Content-Type'  => 'application/json',
            ],
        ];

        $response      = wp_remote_get( $endpoint, $args );
        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 200 !== $response_code ) {
            $error_message = isset( $body->errors[0]->description ) ? $body->errors[0]->description : __( 'Invalid credentials', 'texty' );
            $error_code    = isset( $body->errors[0]->code ) ? $body->errors[0]->code : 'bird_error';

            return new WP_Error( $error_code, $error_message, $body );
        }

        return [
            'access_key'   => $creds['access_key'],
            'workspace_id' => $creds['workspace_id'],
            'channel_id'   => $creds['channel_id'],
        ];
    }
}
