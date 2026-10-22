<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * ClickSend Class
 *
 * @since 2.1.0
 *
 * @see https://developers.clicksend.com/docs/
 */
class ClickSend extends AbstractGateway {

    /**
     * Default API base URL
     */
    const BASE_URL = 'https://rest.clicksend.com/v3';

    /**
     * Value sent as `source` on every message
     */
    const SOURCE = 'texty';

    /**
     * Status ClickSend uses for an accepted request and message
     */
    const STATUS_SUCCESS = 'SUCCESS';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'clicksend';

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
            'docs_url'          => 'https://developers.clicksend.com/docs/',
            'signup_url'        => 'https://dashboard.clicksend.com/#/signup/step1/',
            'regions'           => [],
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
        return __( 'ClickSend', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the ClickSend dashboard API credentials, 2: URL to the Texty setup guide */
            __(
                'Send SMS with ClickSend. Follow <a href="%1$s" target="_blank">this link</a> to get your API username and API key from ClickSend. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://dashboard.clicksend.com/',
            'https://github.com/getdokan/texty/wiki/ClickSend'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/clicksend-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'username' => [
                'name'  => __( 'Username', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'username' ),
                'help'  => __( 'Your ClickSend API username (shown under API Credentials in the dashboard).', 'texty' ),
            ],
            'api_key'  => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_key' ),
                'help'  => __( 'Your ClickSend API key (Dashboard → API Credentials). Your account password is not supported.', 'texty' ),
            ],
            'from'     => [
                'name'         => __( 'Sender', 'texty' ),
                'type'         => 'text',
                'value'        => $this->cred( $creds, 'from' ),
                'help'         => __( 'Sender ID (up to 11 letters or digits) or dedicated number. Leave blank to use a ClickSend shared number. Some countries, including the US and Canada, do not support alphanumeric sender IDs.', 'texty' ),
                'required'     => false,
                'alphanumeric' => true,
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
                __( 'ClickSend username and API key are required.', 'texty' )
            );
        }

        $sms = [
            'to'     => $this->normalize_number( $to ),
            'body'   => (string) $message,
            'source' => self::SOURCE,
        ];

        // Unicode is auto-detected by ClickSend and there is no template-at-send
        // field, so `unicode`, `template_id` and `variables` are ignored.
        $from = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );

        // An empty sender means "use a ClickSend shared number", so the key is
        // omitted rather than sent blank.
        if ( '' !== $from ) {
            $sms['from'] = $from;
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/sms/send',
            [
                'headers' => $this->auth_headers( $username, $api_key ),
                'json'    => [
                    'messages' => [ $sms ],
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        $message_status = $response->get( 'data.messages.0.status' );

        if (
            200 === $response->status()
            && self::STATUS_SUCCESS === $response->get( 'response_code' )
            && self::STATUS_SUCCESS === $message_status
        ) {
            return Result::success( $response->get( 'data.messages.0.message_id' ), $response );
        }

        // A per-message rejection can arrive inside an otherwise successful
        // envelope, so check the message entry before the envelope.
        if ( is_string( $message_status ) && '' !== $message_status && self::STATUS_SUCCESS !== $message_status ) {
            return Result::failure(
                $message_status,
                $this->status_message( $message_status, $response ),
                $response
            );
        }

        $code = $response->get( 'response_code' );

        if ( ! is_string( $code ) || '' === $code || self::STATUS_SUCCESS === $code ) {
            $code = 'clicksend_error';
        }

        return Result::failure( $code, $this->response_message( $response ), $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the credentials against the read-only `GET /account` endpoint.
     * That response includes sub-account API keys, so it is never returned
     * unredacted.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds    = $this->request_creds( $request );
        $username = sanitize_text_field( $this->cred( $creds, 'username' ) );
        $api_key  = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $from     = sanitize_text_field( $this->cred( $creds, 'from' ) );

        if ( '' === $username || '' === $api_key ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'ClickSend username and API key are required.', 'texty' )
            );
        }

        if ( '' !== $from && ! $this->is_valid_sender( $from ) ) {
            return new WP_Error(
                'texty_invalid_sender',
                __( 'The ClickSend sender must be a phone number or an alphanumeric sender ID of up to 11 letters or digits, without spaces.', 'texty' )
            );
        }

        $sanitized = [
            'username' => $username,
            'api_key'  => $api_key,
            'from'     => $from,
        ];

        $response = $this->http()->get(
            $this->base_url( self::BASE_URL, $sanitized ) . '/account',
            [
                'headers' => array_merge(
                    $this->auth_headers( $username, $api_key ),
                    [ 'Content-Type' => 'application/json' ]
                ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if (
            200 !== $response->status()
            || self::STATUS_SUCCESS !== $response->get( 'response_code' )
            || null === $response->get( 'data.user_id' )
        ) {
            $code = $response->get( 'response_code' );

            if ( ! is_string( $code ) || '' === $code || self::STATUS_SUCCESS === $code ) {
                $code = 'clicksend_auth_failed';
            }

            return new WP_Error(
                $code,
                $this->response_message( $response ),
                $this->scrub_payload( $response->payload(), $api_key )
            );
        }

        return $sanitized;
    }

    /**
     * Normalize a phone number to E.164 with a leading `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_number( $to ) {
        return '+' . preg_replace( '/\D+/', '', (string) $to );
    }

    /**
     * Whether a sender is a phone number or a valid alphanumeric sender ID
     *
     * @param string $from
     *
     * @return bool
     */
    private function is_valid_sender( $from ) {
        if ( 1 === preg_match( '/^\+?\d+$/', $from ) ) {
            return true;
        }

        return 1 === preg_match( '/^[A-Za-z0-9]{1,11}$/', $from );
    }

    /**
     * Build the Basic auth header
     *
     * @param string $username
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $username, $api_key ) {
        return [
            'Authorization' => 'Basic ' . base64_encode( $username . ':' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        ];
    }

    /**
     * Readable text for a per-message status code
     *
     * @param string   $status
     * @param Response $response
     *
     * @return string
     */
    private function status_message( $status, Response $response ) {
        $messages = [
            'INVALID_RECIPIENT'     => __( 'The recipient phone number is invalid.', 'texty' ),
            'INSUFFICIENT_CREDIT'   => __( 'Your ClickSend account does not have enough credit.', 'texty' ),
            'INVALID_SENDER_ID'     => __( 'The sender ID is invalid or not allowed for this destination.', 'texty' ),
            'THROTTLED'             => __( 'ClickSend blocked an identical message recently sent to the same recipient.', 'texty' ),
            'ACCOUNT_NOT_ACTIVATED' => __( 'Your ClickSend account is not activated.', 'texty' ),
            'EMPTY_MESSAGE'         => __( 'The message is empty.', 'texty' ),
        ];

        return isset( $messages[ $status ] ) ? $messages[ $status ] : $this->response_message( $response );
    }

    /**
     * Pull the envelope message from a ClickSend response
     *
     * @param Response $response
     *
     * @return string
     */
    private function response_message( Response $response ) {
        $message = $response->get( 'response_msg' );

        if ( is_string( $message ) && '' !== $message ) {
            return $message;
        }

        return __( 'ClickSend could not process the request.', 'texty' );
    }

    /**
     * Remove API keys from a provider payload before it leaves the gateway
     *
     * `GET /account` echoes sub-account API keys, which may differ from the
     * submitted key, so every `api_key` field is blanked and the submitted
     * key is scrubbed anywhere else it appears.
     *
     * @param array|string $payload
     * @param string       $api_key
     *
     * @return array|string
     */
    private function scrub_payload( $payload, $api_key ) {
        return Secrets::redact( $this->redact_key_fields( $payload ), [ $api_key ] );
    }

    /**
     * Recursively replace the value of every `api_key` field
     *
     * @param mixed $payload
     *
     * @return mixed
     */
    private function redact_key_fields( $payload ) {
        if ( ! is_array( $payload ) ) {
            return $payload;
        }

        foreach ( $payload as $key => $value ) {
            if ( 'api_key' === $key ) {
                $payload[ $key ] = Secrets::REDACTED;
                continue;
            }

            $payload[ $key ] = $this->redact_key_fields( $value );
        }

        return $payload;
    }
}
