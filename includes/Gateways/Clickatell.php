<?php

namespace Texty\Gateways;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Clickatell Class
 *
 * @see https://www.clickatell.com/developers/api-documentation/rest-api-send-message/
 */
class Clickatell extends AbstractGateway {

    /**
     * API Endpoint
     */
    const ENDPOINT = 'https://platform.clickatell.com/messages';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'clickatell';

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
            'docs_url'   => 'https://docs.clickatell.com/channels/sms-channels/sms-api-reference/',
            'signup_url' => 'https://www.clickatell.com/sign-up/',
            'validation' => 'format_only',
        ];
    }

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
     * @param array  $options Per-message options, see SendOptions
     *
     * @return Result
     */
    public function send_result( $to, $message, $options = [] ) {
        $key = $this->cred( $this->creds(), 'key' );

        if ( '' === $key ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Clickatell API Key is required.', 'texty' )
            );
        }

        $response = $this->http()->post(
            self::ENDPOINT,
            [
                'headers' => [ 'Authorization' => $key ],
                'json'    => [
                    'to'      => [ $to ],
                    'content' => $message,
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 202 === $response->status() && false !== $response->get( 'messages.0.accepted' ) ) {
            return Result::success( $response->get( 'messages.0.apiMessageId' ), $response );
        }

        // Errors come either at the top level or on the message entry; the
        // unused level is present but null.
        $code    = $response->get( 'errorCode' );
        $message = $response->get( 'errorDescription' );

        if ( null === $code ) {
            $code    = $response->get( 'messages.0.errorCode' );
            $message = $response->get( 'messages.0.errorDescription' );
        }

        return Result::failure(
            null !== $code ? $code : $response->status(),
            null !== $message ? $message : __( 'Bad Request', 'texty' ),
            $response
        );
    }

    /**
     * Validate a REST API request
     *
     * Clickatell has no side-effect-free auth endpoint for this API, so only
     * the presence of the key is checked (metadata `validation: format_only`).
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $key = sanitize_text_field( $this->cred( $this->request_creds( $request ), 'key' ) );

        if ( '' === $key ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Clickatell API Key is required.', 'texty' )
            );
        }

        return [
            'key' => $key,
        ];
    }
}
