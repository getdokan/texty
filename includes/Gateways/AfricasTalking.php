<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Africa's Talking Class
 *
 * Sends through the legacy `/version1/messaging` endpoint that all three
 * official SDKs (PHP, Node, Python) use. The Environment setting picks the
 * live or sandbox host; the sandbox app username is always `sandbox`.
 *
 * @see https://github.com/AfricasTalkingLtd/africastalking-php
 * @see https://help.africastalking.com/en/articles/742491-why-did-my-messages-fail
 *
 * @since 2.1.0
 */
class AfricasTalking extends AbstractGateway {

    /**
     * Live API base URL
     */
    const LIVE_URL = 'https://api.africastalking.com/version1';

    /**
     * Sandbox API base URL
     */
    const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1';

    /**
     * Username the sandbox app always uses
     */
    const SANDBOX_USERNAME = 'sandbox';

    /**
     * Longest accepted sender ID
     */
    const SENDER_ID_MAX_LENGTH = 11;

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'africastalking';

    /**
     * Per-recipient status codes that mean the message was accepted
     * (100 Processed, 101 Sent, 102 Queued)
     *
     * @var int[]
     */
    private $accepted_codes = [ 100, 101, 102 ];

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
            'docs_url'          => 'https://developers.africastalking.com/docs/sms/overview',
            'signup_url'        => 'https://account.africastalking.com/auth/register',
            'regions'           => [ 'KE', 'UG', 'TZ', 'RW', 'MW', 'ZM', 'NG', 'ET', 'CI', 'ZA', 'GH' ],
            'features'          => [
                'unicode'       => true,
                'sender_id'     => true,
                'template'      => false,
                'balance_check' => true,
            ],
            'validation'        => 'live',
            'template_required' => false,
            'sandbox'           => true,
        ];
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'Africa\'s Talking', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the Africa's Talking account dashboard, 2: URL to the Texty setup guide */
            __(
                'Send SMS with Africa\'s Talking. Follow <a href="%1$s" target="_blank">this link</a> to get your app username and API key. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://account.africastalking.com/apps',
            'https://github.com/getdokan/texty/wiki/AfricasTalking'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/africastalking-logo.png';
    }

    /**
     * Get the settings
     *
     * The environment select is also whitelisted in validate(), which only
     * accepts `live` or `sandbox`.
     *
     * @return array
     */
    public function get_settings() {
        $creds       = $this->creds();
        $environment = $this->cred( $creds, 'environment' );

        return [
            'username'    => [
                'name'  => __( 'Username', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'username' ),
                'help'  => __( 'Your Africa\'s Talking app username. Use "sandbox" for the Sandbox environment.', 'texty' ),
            ],
            'api_key'     => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_key' ),
                'help'  => __( 'Generate it under your app\'s Settings → API Key. The sandbox app has its own key.', 'texty' ),
            ],
            'sender_id'   => [
                'name'     => __( 'Sender ID', 'texty' ),
                'type'     => 'text',
                'value'    => $this->cred( $creds, 'sender_id' ),
                'help'     => __( 'Registered alphanumeric sender ID or short code. Leave blank to use the default (AFRICASTKNG).', 'texty' ),
                'required' => false,
            ],
            'environment' => [
                'name'    => __( 'Environment', 'texty' ),
                'type'    => 'select',
                'options' => [
                    'live'    => __( 'Live', 'texty' ),
                    'sandbox' => __( 'Sandbox', 'texty' ),
                ],
                'value'   => '' !== $environment ? $environment : 'live',
                'help'    => __( 'Sandbox sends to the Africa\'s Talking simulator for free.', 'texty' ),
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
        $options  = SendOptions::normalize( $options );
        $creds    = $this->creds();
        $username = $this->cred( $creds, 'username' );
        $api_key  = $this->cred( $creds, 'api_key' );

        if ( '' === $username || '' === $api_key ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Africa\'s Talking username and API key are required.', 'texty' )
            );
        }

        $number = $this->normalize_number( $to );

        if ( '' === $number ) {
            return Result::failure(
                'texty_invalid_number',
                __( 'The recipient phone number is not valid.', 'texty' )
            );
        }

        $form = [
            'username' => $username,
            'to'       => $number,
            'message'  => $message,
        ];

        // An empty `from` is rejected by the API, so only send it when set.
        $from = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender_id' );

        if ( '' !== $from ) {
            $form['from'] = $from;
        }

        $response = $this->http()->post(
            $this->api_url( $creds ) . '/messaging',
            [
                'headers' => array_merge(
                    $this->auth_headers( $api_key ),
                    [ 'Content-Type' => 'application/x-www-form-urlencoded' ]
                ),
                'form'    => $form,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( ! $response->is_2xx() ) {
            return $this->http_failure( $response );
        }

        return $this->parse_send_response( $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the format first, then calls the side-effect-free
     * `GET /version1/user` (application data / balance) endpoint.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds       = $this->request_creds( $request );
        $username    = sanitize_text_field( $this->cred( $creds, 'username' ) );
        $api_key     = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $sender_id   = sanitize_text_field( $this->cred( $creds, 'sender_id' ) );
        $environment = strtolower( sanitize_text_field( $this->cred( $creds, 'environment' ) ) );
        $environment = '' !== $environment ? $environment : 'live';

        if ( '' === $username || '' === $api_key ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Africa\'s Talking username and API key are required.', 'texty' )
            );
        }

        if ( ! in_array( $environment, [ 'live', 'sandbox' ], true ) ) {
            return new WP_Error(
                'texty_invalid_environment',
                __( 'Environment must be "live" or "sandbox".', 'texty' )
            );
        }

        if ( 'sandbox' === $environment && self::SANDBOX_USERNAME !== $username ) {
            return new WP_Error(
                'texty_invalid_username',
                __( 'Sandbox username must be "sandbox".', 'texty' )
            );
        }

        if ( strlen( $sender_id ) > self::SENDER_ID_MAX_LENGTH ) {
            return new WP_Error(
                'texty_invalid_sender_id',
                sprintf(
                    /* translators: %d: maximum number of characters */
                    __( 'Sender ID must be at most %d characters.', 'texty' ),
                    self::SENDER_ID_MAX_LENGTH
                )
            );
        }

        $validated = [
            'username'    => $username,
            'api_key'     => $api_key,
            'sender_id'   => $sender_id,
            'environment' => $environment,
        ];

        $response = $this->http()->get(
            $this->api_url( $validated ) . '/user',
            [
                'headers' => $this->auth_headers( $api_key ),
                'query'   => [ 'username' => $username ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() || null === $response->get( 'UserData' ) ) {
            $message = $this->error_message( $response );

            return new WP_Error(
                200 === $response->status() ? 'africastalking_invalid_response' : 'africastalking_http_' . $response->status(),
                '' !== $message ? $message : __( 'Africa\'s Talking could not verify these credentials.', 'texty' ),
                $response->payload()
            );
        }

        return $validated;
    }

    /**
     * Map a 2xx send response to a result
     *
     * The HTTP status stays 2xx even when the message is rejected, so the
     * single recipient's `statusCode` decides success.
     *
     * @param Response $response
     *
     * @return Result
     */
    private function parse_send_response( Response $response ) {
        $recipient = $response->get( 'SMSMessageData.Recipients.0' );

        if ( ! is_array( $recipient ) ) {
            $summary = $response->get( 'SMSMessageData.Message' );

            if ( null === $response->get( 'SMSMessageData' ) ) {
                return Result::failure(
                    'africastalking_invalid_response',
                    __( 'Africa\'s Talking returned an unexpected response.', 'texty' ),
                    $response
                );
            }

            return Result::failure(
                'africastalking_rejected',
                is_string( $summary ) ? $summary : '',
                $response
            );
        }

        $code   = isset( $recipient['statusCode'] ) && is_numeric( $recipient['statusCode'] ) ? (int) $recipient['statusCode'] : null;
        $status = isset( $recipient['status'] ) && is_string( $recipient['status'] ) ? $recipient['status'] : '';

        if ( null === $code || ! in_array( $code, $this->accepted_codes, true ) ) {
            return Result::failure(
                null === $code ? 'africastalking_rejected' : 'africastalking_' . $code,
                $status,
                $response
            );
        }

        $message_id = isset( $recipient['messageId'] ) && is_scalar( $recipient['messageId'] ) ? trim( (string) $recipient['messageId'] ) : '';

        // Failed recipients are believed to carry the literal "None".
        if ( 'None' === $message_id ) {
            $message_id = '';
        }

        return Result::success( $message_id, $response );
    }

    /**
     * Map a non-2xx send response to a result
     *
     * @param Response $response
     *
     * @return Result
     */
    private function http_failure( Response $response ) {
        $code = 'africastalking_http_' . $response->status();

        // 5xx bodies are not meant for users; keep them in raw_response only.
        if ( $response->status() >= 500 ) {
            return Result::failure( $code, '', $response );
        }

        return Result::failure( $code, $this->error_message( $response ), $response );
    }

    /**
     * Pull a readable error from a JSON or text/plain error body
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $json = $response->json();

        if ( null !== $json ) {
            $message = $response->get( 'errorMessage' );

            return is_string( $message ) ? trim( $message ) : '';
        }

        $body = trim( wp_strip_all_tags( $response->body() ) );

        return strlen( $body ) > 200 ? substr( $body, 0, 200 ) : $body;
    }

    /**
     * Resolve the API base URL for the configured environment
     *
     * @param array $creds
     *
     * @return string
     */
    private function api_url( array $creds ) {
        $default = 'sandbox' === $this->cred( $creds, 'environment' ) ? self::SANDBOX_URL : self::LIVE_URL;

        return $this->base_url( $default, $creds );
    }

    /**
     * Build the auth headers
     *
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $api_key ) {
        return [
            'apiKey' => $api_key,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Normalize a number to E.164 with a leading `+`
     *
     * @param string $to
     *
     * @return string Empty string when no digits are left
     */
    private function normalize_number( $to ) {
        $digits = preg_replace( '/\D/', '', (string) $to );

        return '' === $digits ? '' : '+' . $digits;
    }
}
