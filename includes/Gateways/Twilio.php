<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Twilio Class
 *
 * @see https://www.twilio.com/docs/sms/api/message
 */
class Twilio extends AbstractGateway {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json';

    /**
     * Endpoint used to check credentials
     */
    const ACCOUNTS_ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts.json';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'twilio';

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
            'docs_url'   => 'https://www.twilio.com/docs/sms/api/message-resource',
            'signup_url' => 'https://www.twilio.com/try-twilio',
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
     * @param array  $options Per-message options, see SendOptions
     *
     * @return Result
     */
    public function send_result( $to, $message, $options = [] ) {
        $options = SendOptions::normalize( $options );
        $creds   = $this->creds();
        $sid     = $this->cred( $creds, 'sid' );
        $token   = $this->cred( $creds, 'token' );

        if ( '' === $sid || '' === $token ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Twilio Account SID and Auth Token are required.', 'texty' )
            );
        }

        $response = $this->http()->post(
            str_replace( '{sid}', rawurlencode( $sid ), self::ENDPOINT ),
            [
                'headers' => $this->auth_headers( $sid, $token ),
                'form'    => [
                    'From' => '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' ),
                    'To'   => $to,
                    'Body' => $message,
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 201 === $response->status() ) {
            return Result::success( $response->get( 'sid' ), $response );
        }

        return Result::failure(
            $response->get( 'code', 'twilio_error' ),
            $response->get( 'message', '' ),
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
        $creds = $this->request_creds( $request );
        $sid   = sanitize_text_field( $this->cred( $creds, 'sid' ) );
        $token = sanitize_text_field( $this->cred( $creds, 'token' ) );

        if ( '' === $sid || '' === $token ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Twilio Account SID and Auth Token are required.', 'texty' )
            );
        }

        $response = $this->http()->get(
            self::ACCOUNTS_ENDPOINT,
            [ 'headers' => $this->auth_headers( $sid, $token ) ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 401 === $response->status() ) {
            $message = $response->get( 'detail', $response->get( 'message', '' ) );

            return new WP_Error(
                $response->get( 'code', 'twilio_auth_failed' ),
                '' !== (string) $message ? $message : __( 'Twilio rejected the credentials.', 'texty' ),
                $response->payload()
            );
        }

        return [
            'sid'   => $sid,
            'token' => $token,
            'from'  => sanitize_text_field( $this->cred( $creds, 'from' ) ),
        ];
    }

    /**
     * Build the Basic auth header
     *
     * @param string $sid
     * @param string $token
     *
     * @return array
     */
    private function auth_headers( $sid, $token ) {
        return [
            'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        ];
    }
}
