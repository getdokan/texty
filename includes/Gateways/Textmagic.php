<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Textmagic Class
 *
 * @see https://docs.textmagic.com/#tag/Outbound-Messages
 */
class Textmagic extends AbstractGateway {

    /**
     * API base URL
     */
    const BASE_URL = 'https://rest.textmagic.com/api/v2';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'textmagic';

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
            'docs_url'          => 'https://docs.textmagic.com/',
            'signup_url'        => 'https://my.textmagic.com/register/',
            'regions'           => [],
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
        return __( 'Textmagic', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: %1$s: URL to the Textmagic API keys page, %2$s: URL to the setup guide
            __(
                'Send SMS with Textmagic. Follow <a href="%1$s" target="_blank">this link</a> to get your username and API key from Textmagic. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://my.textmagic.com/online/api/rest-api/keys',
            'https://github.com/getdokan/texty/wiki/Textmagic'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/textmagic-logo.png';
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
                'help'  => __( 'Your Textmagic account username (shown in Settings → API).', 'texty' ),
            ],
            'api_key'  => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_key' ),
                'help'  => __( 'Generate one at my.textmagic.com → API → Add new API key. V1 keys do not work.', 'texty' ),
            ],
            'from'     => [
                'name'         => __( 'Sender ID', 'texty' ),
                'type'         => 'text',
                'value'        => $this->cred( $creds, 'from' ),
                'required'     => false,
                'alphanumeric' => true,
                'help'         =>__( 'Optional. An approved alphanumeric Sender ID or one of your Textmagic numbers. Leave empty to use the account\'s default sender settings. If the Sender ID is not allowed for a destination, Textmagic silently uses your default sender instead.', 'texty' ),
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
                __( 'Textmagic username and API key are required.', 'texty' )
            );
        }

        // Textmagic picks GSM 03.38 or Unicode encoding from the content, and
        // has no variables for raw-number sends, so those options are ignored.
        $body = [
            'text'   => (string) $message,
            'phones' => $this->normalize_phone( $to ),
        ];

        $from = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );

        if ( '' !== $from ) {
            $body['from'] = $from;
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/messages',
            [
                'headers' => $this->auth_headers( $username, $api_key ),
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( $response->is_2xx() ) {
            $message_id = 201 === $response->status() ? $this->message_id( $response ) : null;

            if ( null === $message_id ) {
                return Result::failure(
                    'textmagic_no_message_id',
                    __( 'Textmagic accepted the request but did not return a message ID, so the message cannot be confirmed as sent.', 'texty' ),
                    $response
                );
            }

            return Result::success( $message_id, $response );
        }

        return Result::failure(
            $this->error_code( $response ),
            $this->error_message( $response ),
            $response
        );
    }

    /**
     * Validate a REST API request
     *
     * Calls GET /user, which is free and has no side effects.
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
                __( 'Textmagic username and API key are required.', 'texty' )
            );
        }

        $sanitized = [
            'username' => $username,
            'api_key'  => $api_key,
            'from'     => $from,
        ];

        $response = $this->http()->get(
            $this->base_url( self::BASE_URL, $sanitized ) . '/user',
            [
                'headers' => array_merge(
                    $this->auth_headers( $username, $api_key ),
                    [ 'Accept' => 'application/json' ]
                ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() || null === $response->get( 'id' ) ) {
            return new WP_Error(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        return $sanitized;
    }

    /**
     * Normalize a phone number to E.164 digits without the leading `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_phone( $to ) {
        return ltrim( (string) preg_replace( '/[^\d+]/', '', (string) $to ), '+' );
    }

    /**
     * Build the Textmagic auth headers
     *
     * @param string $username
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $username, $api_key ) {
        return [
            'X-TM-Username' => $username,
            'X-TM-Key'      => $api_key,
        ];
    }

    /**
     * Read the per-message id from a send response
     *
     * `messageId` is the message id. `id` is only used when the response
     * describes a single message, because for a session it is the session id.
     *
     * @param Response $response
     *
     * @return string|null
     */
    private function message_id( Response $response ) {
        $message_id = $response->get( 'messageId' );

        if ( null === $message_id && 'message' === $response->get( 'type' ) ) {
            $message_id = $response->get( 'id' );
        }

        if ( ! is_scalar( $message_id ) || '' === trim( (string) $message_id ) ) {
            return null;
        }

        return (string) $message_id;
    }

    /**
     * Error code for a failed response
     *
     * The body's `code` only repeats the HTTP status, so it is prefixed.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $code = $response->get( 'code' );

        if ( ! is_scalar( $code ) || '' === trim( (string) $code ) ) {
            $code = $response->status();
        }

        return 'textmagic_' . trim( (string) $code );
    }

    /**
     * Pull a readable error from a Textmagic error body
     *
     * Order: first field error (prefixed with the field name), first common
     * error, top-level message, then a status-based fallback.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $fields = $response->get( 'errors.fields' );

        if ( is_array( $fields ) ) {
            foreach ( $fields as $field => $errors ) {
                $error = is_array( $errors ) ? reset( $errors ) : $errors;

                if ( is_string( $error ) && '' !== $error ) {
                    return $field . ': ' . $error;
                }
            }
        }

        $common = $response->get( 'errors.common' );

        if ( is_array( $common ) ) {
            $error = reset( $common );

            if ( is_string( $error ) && '' !== $error ) {
                return $error;
            }
        }

        $message = $response->get( 'message' );

        if ( is_string( $message ) && '' !== $message ) {
            return $message;
        }

        if ( 429 === $response->status() ) {
            return __( 'Textmagic rate limit reached. Please try again in a moment.', 'texty' );
        }

        return sprintf(
            // translators: %d: HTTP status code
            __( 'Textmagic returned an unexpected response (HTTP %d).', 'texty' ),
            $response->status()
        );
    }
}
