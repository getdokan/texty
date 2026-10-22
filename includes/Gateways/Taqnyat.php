<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Taqnyat Class
 *
 * Saudi Arabian SMS provider. Sends one message per call through the JSON
 * REST API with a Bearer token.
 *
 * @since 2.1.0
 *
 * @see https://dev.taqnyat.sa/en/doc/sms/
 */
class Taqnyat extends AbstractGateway {

    /**
     * Default API base URL
     */
    const BASE_URL = 'https://api.taqnyat.sa';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'taqnyat';

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
            'docs_url'          => 'https://dev.taqnyat.sa/en/doc/sms/',
            'signup_url'        => 'https://portal.taqnyat.sa',
            'regions'           => [ 'SA' ],
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
        return __( 'Taqnyat', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the Taqnyat portal, 2: URL to the Texty setup guide */
            __(
                'Send SMS with Taqnyat (Saudi Arabia). Create a Bearer token in the <a href="%1$s" target="_blank">Taqnyat portal</a> under Developer &rarr; Application. Your sender name must be registered and active, and API access must be allowed for your server\'s IP and country in the account security settings. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://portal.taqnyat.sa',
            'https://github.com/getdokan/texty/wiki/Taqnyat'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/taqnyat-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'token'  => [
                'name'  => __( 'Bearer Token', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'token' ),
                'help'  => __( 'Create it in the Taqnyat portal: Developer → Application → Add, select the SMS service, then copy the Bearer token.', 'texty' ),
            ],
            'sender' => [
                'name'  => __( 'Sender Name', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'sender' ),
                'help'  => __( 'Must exactly match an active sender name in your Taqnyat account (case-sensitive). Saudi sender names must be registered and approved by the operators: ask your Taqnyat account manager for the New Sender form and submit it on company letterhead with a valid commercial registration. Approval can take several days.', 'texty' ),
            ],
        ];
    }

    /**
     * Send SMS
     *
     * @since 2.1.0
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
        $token   = $this->cred( $creds, 'token' );
        $sender  = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender' );

        if ( '' === $token || '' === $sender ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Taqnyat Bearer Token and Sender Name are required.', 'texty' )
            );
        }

        $recipient = $this->normalize_number( $to );

        if ( '' === $recipient ) {
            return Result::failure(
                'taqnyat_invalid_number',
                __( 'The phone number must be in international format, e.g. 966500000000.', 'texty' )
            );
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/v1/messages',
            [
                'headers' => $this->auth_headers( $token ),
                'json'    => [
                    'recipients' => [ $recipient ],
                    'body'       => (string) $message,
                    'sender'     => $sender,
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        $status = $response->status();

        // Docs: 201. 200 is accepted defensively, but only with a message id.
        if ( 201 !== $status && 200 !== $status ) {
            return Result::failure(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response
            );
        }

        if ( $this->is_rejected( $response ) ) {
            return Result::failure(
                'taqnyat_rejected',
                __( 'Recipient rejected by Taqnyat', 'texty' ),
                $response
            );
        }

        // messageId is an int in the docs and a string in the OpenAPI spec;
        // Result::success() fails the send when it is missing or empty.
        $message_id = $response->get( 'messageId' );

        return Result::success( is_scalar( $message_id ) ? (string) $message_id : '', $response );
    }

    /**
     * Validate a REST API request
     *
     * Lists the account's sender names, which checks the token and that the
     * chosen sender exists and is active in a single side-effect-free call.
     * The balance endpoint is not used: it answers HTTP 200 to a bad token.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds  = $this->request_creds( $request );
        $token  = trim( sanitize_text_field( $this->cred( $creds, 'token' ) ) );
        $sender = trim( sanitize_text_field( $this->cred( $creds, 'sender' ) ) );

        if ( '' === $token || '' === $sender ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Taqnyat Bearer Token and Sender Name are required.', 'texty' )
            );
        }

        $response = $this->http()->get(
            $this->base_url( self::BASE_URL, $creds ) . '/v1/messages/senders',
            [
                'headers' => $this->auth_headers( $token ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        $status  = $response->status();
        $senders = $response->get( 'senders' );

        if ( ( 200 !== $status && 201 !== $status ) || ! is_array( $senders ) ) {
            return new WP_Error(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        $match = null;

        foreach ( $senders as $entry ) {
            if ( is_array( $entry ) && isset( $entry['senderName'] ) && $sender === $entry['senderName'] ) {
                $match = $entry;
                break;
            }
        }

        if ( null === $match ) {
            return new WP_Error(
                'taqnyat_invalid_sender',
                __( 'Sender name not found in your Taqnyat account. Sender names are case-sensitive.', 'texty' ),
                $response->payload()
            );
        }

        if ( ! isset( $match['status'] ) || 'active' !== $match['status'] ) {
            return new WP_Error(
                'taqnyat_sender_inactive',
                __( 'This sender name is not active in your Taqnyat account yet.', 'texty' ),
                $response->payload()
            );
        }

        return [
            'token'  => $token,
            'sender' => $sender,
        ];
    }

    /**
     * Build the Bearer auth header
     *
     * @param string $token
     *
     * @return array
     */
    private function auth_headers( $token ) {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * Normalize a phone number to international digits without `+` or `00`
     *
     * @param string $to
     *
     * @return string Empty string when the number is not in international format
     */
    private function normalize_number( $to ) {
        $number = (string) preg_replace( '/[\s\-\.\(\)]+/', '', (string) $to );

        if ( 0 === strpos( $number, '+' ) ) {
            $number = substr( $number, 1 );
        } elseif ( 0 === strpos( $number, '00' ) ) {
            $number = substr( $number, 2 );
        }

        return 1 === preg_match( '/^[1-9]\d{7,14}$/', $number ) ? $number : '';
    }

    /**
     * Whether a 2xx send response rejected the recipient
     *
     * `accepted` / `rejected` are strings shaped like arrays
     * (`"[966500000000,]"`), so they are inspected for digits rather than
     * decoded.
     *
     * @param Response $response
     *
     * @return bool
     */
    private function is_rejected( Response $response ) {
        $rejected = $response->get( 'rejected' );

        if ( is_array( $rejected ) && ! empty( $rejected ) ) {
            return true;
        }

        if ( is_string( $rejected ) && 1 === preg_match( '/\d/', $rejected ) ) {
            return true;
        }

        $total = $response->get( 'totalCount' );

        return null !== $total && is_numeric( $total ) && 0 === (int) $total;
    }

    /**
     * Derive an error code from a Taqnyat error response
     *
     * Taqnyat has no machine error codes, so the free-text message is matched.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        if ( 401 === $response->status() ) {
            return 'taqnyat_invalid_credentials';
        }

        $message = $response->get( 'message' );
        $message = is_string( $message ) ? $message : '';

        $map = [
            'Mobile(s)'          => 'taqnyat_invalid_number',
            'balance'            => 'taqnyat_insufficient_balance',
            'Sender Name'        => 'taqnyat_invalid_sender',
            'API is disabled'    => 'taqnyat_api_disabled',
            'this ip'            => 'taqnyat_ip_not_allowed',
            'this country'       => 'taqnyat_country_not_allowed',
            'not responding'     => 'taqnyat_unavailable',
            'invalid credential' => 'taqnyat_invalid_credentials',
        ];

        if ( '' !== $message ) {
            foreach ( $map as $needle => $code ) {
                if ( false !== stripos( $message, $needle ) ) {
                    return $code;
                }
            }
        }

        return 'taqnyat_' . $response->status();
    }

    /**
     * Pull a readable error message from a Taqnyat response
     *
     * @param Response $response
     *
     * @return string Falls back to the HTTP reason phrase
     */
    private function error_message( Response $response ) {
        $message = $response->get( 'message' );

        if ( is_string( $message ) && '' !== trim( $message ) ) {
            return trim( $message );
        }

        return (string) get_status_header_desc( $response->status() );
    }
}
