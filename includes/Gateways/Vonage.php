<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Nexmo Class
 *
 * @see https://developer.nexmo.com/api/sms
 */
class Vonage extends AbstractGateway {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://rest.nexmo.com';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'vonage';

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
            'docs_url'   => 'https://developer.vonage.com/en/api/sms',
            'signup_url' => 'https://dashboard.nexmo.com/sign-up',
            'features'   => [
                'unicode'       => true,
                'sender_id'     => true,
                'balance_check' => true,
            ],
        ];
    }

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
     * @param array  $options Per-message options, see SendOptions
     *
     * @return Result
     */
    public function send_result( $to, $message, $options = [] ) {
        $options = SendOptions::normalize( $options );
        $creds   = $this->creds();
        $key     = $this->cred( $creds, 'key' );
        $secret  = $this->cred( $creds, 'secret' );

        if ( '' === $key || '' === $secret ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Vonage API Key and API Secret are required.', 'texty' )
            );
        }

        $form = [
            'from'       => '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' ),
            'text'       => $message,
            'to'         => $to,
            'api_key'    => $key,
            'api_secret' => $secret,
        ];

        if ( $options['unicode'] ) {
            $form['type'] = 'unicode';
        }

        $response = $this->http()->post( self::ENDPOINT . '/sms/json', [ 'form' => $form ] );
        $failure  = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        // Vonage answers 200 even for rejected messages; the per-message
        // status ("0" = accepted) is what decides success.
        $status = $response->get( 'messages.0.status' );

        if ( 200 === $response->status() && '0' === (string) $status ) {
            return Result::success( $response->get( 'messages.0.message-id' ), $response );
        }

        return Result::failure(
            null !== $status ? $status : 'vonage_error',
            $response->get( 'messages.0.error-text', '' ),
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
        $creds  = $this->request_creds( $request );
        $key    = sanitize_text_field( $this->cred( $creds, 'key' ) );
        $secret = sanitize_text_field( $this->cred( $creds, 'secret' ) );

        if ( '' === $key || '' === $secret ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Vonage API Key and API Secret are required.', 'texty' )
            );
        }

        $response = $this->http()->get(
            self::ENDPOINT . '/account/get-balance',
            [
                'query' => [
                    'api_key'    => $key,
                    'api_secret' => $secret,
                ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 401 === $response->status() ) {
            return new WP_Error(
                $response->get( 'error-code', 'vonage_auth_failed' ),
                $response->get( 'error-code-label', __( 'Vonage rejected the credentials.', 'texty' ) )
            );
        }

        return [
            'key'    => $key,
            'secret' => $secret,
            'from'   => sanitize_text_field( $this->cred( $creds, 'from' ) ),
        ];
    }
}
