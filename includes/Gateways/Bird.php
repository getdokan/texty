<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Bird Class
 *
 * @since 2.1.0
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
     * @since 2.1.0
     *
     * @return string
     */
    public function name() {
        return __( 'Bird', 'texty' );
    }

    /**
     * Get the description
     *
     * @since 2.1.0
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
     * @since 2.1.0
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/bird.svg';
    }

    /**
     * Get the settings
     *
     * @since 2.1.0
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'bird' );

        return [
            'access_key'   => [
                'name'  => __( 'Access Key', 'texty' ),
                'type'  => 'password',
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
     * Normalize a credential array, filling missing keys with empty strings
     *
     * @param mixed $creds
     *
     * @return array
     */
    private function normalize_creds( $creds ) {
        $creds = is_array( $creds ) ? $creds : [];

        return [
            'access_key'   => isset( $creds['access_key'] ) ? sanitize_text_field( $creds['access_key'] ) : '',
            'workspace_id' => isset( $creds['workspace_id'] ) ? sanitize_text_field( $creds['workspace_id'] ) : '',
            'channel_id'   => isset( $creds['channel_id'] ) ? sanitize_text_field( $creds['channel_id'] ) : '',
        ];
    }

    /**
     * Check that every credential is present
     *
     * @param array $creds
     *
     * @return WP_Error|true
     */
    private function check_creds( $creds ) {
        if ( '' === $creds['access_key'] || '' === $creds['workspace_id'] || '' === $creds['channel_id'] ) {
            return new WP_Error(
                'bird_missing_credentials',
                __( 'Bird Access Key, Workspace ID and Channel ID are required.', 'texty' )
            );
        }

        return true;
    }

    /**
     * Get the channel URL for a workspace
     *
     * @param array $creds
     *
     * @return string
     */
    private function get_channel_url( $creds ) {
        return sprintf(
            '%s/workspaces/%s/channels/%s',
            self::API_BASE,
            rawurlencode( $creds['workspace_id'] ),
            rawurlencode( $creds['channel_id'] )
        );
    }

    /**
     * Get the request headers
     *
     * @param array $creds
     *
     * @return array
     */
    private function get_headers( $creds ) {
        return [
            'Authorization' => 'AccessKey ' . $creds['access_key'],
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Build a WP_Error from a Bird error response body
     *
     * @param mixed  $body
     * @param string $default_message
     *
     * @return WP_Error
     */
    private function error_from_body( $body, $default_message ) {
        $error_message = isset( $body->message ) ? $body->message : $default_message;
        $error_code    = isset( $body->code ) ? $body->code : 'bird_error';

        if ( isset( $body->errors[0]->description ) ) {
            $error_message = $body->errors[0]->description;
        }

        if ( isset( $body->errors[0]->code ) ) {
            $error_code = $body->errors[0]->code;
        }

        return new WP_Error( $error_code, $error_message, $body );
    }

    /**
     * Send SMS
     *
     * @since 2.1.0
     *
     * @param string $to
     * @param string $message
     *
     * @return WP_Error|array
     */
    public function send( $to, $message ) {
        $creds = $this->normalize_creds( texty()->settings()->get( 'bird' ) );
        $check = $this->check_creds( $creds );

        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $args = [
            'headers' => $this->get_headers( $creds ),
            'body'    => wp_json_encode(
                [
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
                ]
            ),
        ];

        $response = wp_remote_post( $this->get_channel_url( $creds ) . '/messages', $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 202 !== $response_code ) {
            return $this->error_from_body( $body, __( 'Unknown error', 'texty' ) );
        }

        return [
            'success'      => true,
            'reference_id' => isset( $body->id ) ? $body->id : null,
        ];
    }

    /**
     * Validate a REST API request
     *
     * Fetches the configured channel, which verifies the access key,
     * workspace and channel in a single call.
     *
     * @since 2.1.0
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds = $this->normalize_creds( $request->get_param( 'bird' ) );
        $check = $this->check_creds( $creds );

        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $response = wp_remote_get(
            $this->get_channel_url( $creds ),
            [
                'headers' => $this->get_headers( $creds ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 200 !== $response_code ) {
            return $this->error_from_body( $body, __( 'Invalid credentials', 'texty' ) );
        }

        return $creds;
    }
}
