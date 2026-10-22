<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Octopush gateway
 *
 * Sends transactional (`purpose: alert`) premium SMS through the Octopush
 * JSON API, authenticated with the `api-login` / `api-key` headers.
 *
 * @see https://dev.octopush.com/en/sms-gateway-api-documentation/send-sms/
 *
 * @since 2.1.0
 */
class Octopush extends AbstractGateway {

    /**
     * API base URL
     */
    const BASE_URL = 'https://api.octopush.com/v1/public';

    /**
     * Send path
     */
    const SEND_PATH = '/sms-campaign/send';

    /**
     * Balance path, used by validate()
     */
    const BALANCE_PATH = '/wallet/check-balance';

    /**
     * Allowed custom sender: 3-11 letters, digits or spaces
     */
    const SENDER_PATTERN = '/^[A-Za-z0-9 ]{3,11}$/';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'octopush';

    /**
     * Gateway metadata
     *
     * @since 2.1.0
     *
     * @return array
     */
    protected function meta() {
        return [
            'status'            => Metadata::STATUS_BETA,
            'docs_url'          => 'https://dev.octopush.com/en/sms-gateway-api-documentation/send-sms/',
            'signup_url'        => 'https://client.octopush.com/',
            'regions'           => [ 'FR', 'EU' ],
            'features'          => [
                'unicode'       => true,
                'sender_id'     => true,
                'template'      => false,
                'balance_check' => true,
            ],
            'validation'        => 'live',
            'template_required' => false,
            'sandbox'           => false,
        ];
    }

    /**
     * Get the name
     *
     * @return string
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
            /* translators: 1: URL of the Octopush account where the API credentials are found, 2: URL of the setup guide */
            __(
                'Send transactional SMS with Octopush (notifications only, not marketing). Follow <a href="%1$s" target="_blank">this link</a> to get your API login and API key from your Octopush profile. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://client.octopush.com/',
            'https://github.com/getdokan/texty/wiki/Octopush'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/octopush-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'api_login' => [
                'name'  => __( 'API Login', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'api_login' ),
                'help'  => __( 'The email address you use to log in to Octopush', 'texty' ),
            ],
            'api_key'   => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_key' ),
                'help'  => __( 'Found in your Octopush user profile (API credentials)', 'texty' ),
            ],
            'sender'    => [
                'name'     => __( 'Sender', 'texty' ),
                'type'     => 'text',
                'value'    => $this->cred( $creds, 'sender' ),
                'help'     => __( '3–11 letters/digits. Only used if your account is allowed a custom sender. Leave empty to use the default.', 'texty' ),
                'required' => false,
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
        $login   = $this->cred( $creds, 'api_login' );
        $api_key = $this->cred( $creds, 'api_key' );

        if ( '' === $login || '' === $api_key ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Octopush API Login and API Key are required.', 'texty' )
            );
        }

        $phone = $this->normalize_number( $to );

        if ( '' === $phone ) {
            return Result::failure(
                'octopush_invalid_number',
                __( 'The recipient phone number is not valid.', 'texty' )
            );
        }

        $sender = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender' );

        $body = [
            'recipients' => [
                [ 'phone_number' => $phone ],
            ],
            'text'       => (string) $message,
            'type'       => 'sms_premium',
            'purpose'    => 'alert',
        ];

        if ( '' !== $sender ) {
            $body['sender'] = $sender;
        }

        // Without this flag Octopush "optimizes" the text, which can strip
        // characters outside the GSM alphabet.
        if ( $options['unicode'] ) {
            $body['auto_optimize_text'] = false;
        }

        /**
         * Filter the Octopush send request body, e.g. to switch `type` to
         * `sms_low_cost` or `purpose` to `wholesale`.
         *
         * @since 2.1.0
         *
         * @param array  $body    JSON request body
         * @param string $to      Recipient as passed to the gateway
         * @param string $message Message text
         * @param array  $options Normalized send options
         */
        $body = apply_filters( 'texty_octopush_request_body', $body, $to, $message, $options );

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . self::SEND_PATH,
            [
                'headers' => $this->auth_headers( $login, $api_key ),
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        $ticket = $response->get( 'sms_ticket' );

        if ( 201 === $response->status() && is_string( $ticket ) && '' !== $ticket ) {
            $contacts = $response->get( 'number_of_contacts' );

            // An invalid recipient is dropped from the campaign rather than
            // rejected, so zero contacts means nothing will be delivered.
            if ( null !== $contacts && 0 === (int) $contacts ) {
                return Result::failure(
                    'octopush_no_valid_recipient',
                    __( 'Octopush accepted the request but found no valid recipient.', 'texty' ),
                    $response
                );
            }

            return Result::success( $ticket, $response );
        }

        $error = $this->error( $response );

        return Result::failure( $error['code'], $error['message'], $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the credentials against the read-only balance endpoint.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds   = $this->request_creds( $request );
        $login   = sanitize_text_field( $this->cred( $creds, 'api_login' ) );
        $api_key = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $sender  = sanitize_text_field( $this->cred( $creds, 'sender' ) );

        if ( '' === $login || '' === $api_key ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Octopush API Login and API Key are required.', 'texty' )
            );
        }

        if ( '' !== $sender && ! preg_match( self::SENDER_PATTERN, $sender ) ) {
            return new WP_Error(
                'octopush_invalid_sender',
                __( 'The Octopush sender must be 3 to 11 letters, digits or spaces.', 'texty' )
            );
        }

        $sanitized = [
            'api_login' => $login,
            'api_key'   => $api_key,
            'sender'    => $sender,
        ];

        $response = $this->http()->get(
            $this->base_url( self::BASE_URL, $sanitized ) . self::BALANCE_PATH,
            [
                'headers' => $this->auth_headers( $login, $api_key ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() || null === $response->get( 'amount' ) ) {
            $error = $this->error( $response );

            return new WP_Error( $error['code'], $error['message'], $response->payload() );
        }

        return $sanitized;
    }

    /**
     * Normalize a recipient to `+` international format
     *
     * @param string $to
     *
     * @return string Empty string when no digits remain
     */
    private function normalize_number( $to ) {
        $digits = preg_replace( '/\D+/', '', (string) $to );

        return '' === $digits ? '' : '+' . $digits;
    }

    /**
     * Build the authentication headers
     *
     * @param string $login
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $login, $api_key ) {
        return [
            'api-login'     => $login,
            'api-key'       => $api_key,
            'Content-Type'  => 'application/json',
            'cache-control' => 'no-cache',
        ];
    }

    /**
     * Pull the provider error code and message from a response
     *
     * @param Response $response
     *
     * @return array { code: string, message: string }
     */
    private function error( Response $response ) {
        $code    = $response->get( 'code' );
        $message = $response->get( 'message' );

        if ( null === $code || ! is_scalar( $code ) || '' === (string) $code ) {
            $code = 'octopush_http_' . $response->status();
        }

        if ( ! is_string( $message ) || '' === $message ) {
            $message = sprintf(
                /* translators: %d: HTTP status code */
                __( 'Octopush returned an unexpected response (HTTP %d).', 'texty' ),
                $response->status()
            );
        }

        return [
            'code'    => (string) $code,
            'message' => $message,
        ];
    }
}
