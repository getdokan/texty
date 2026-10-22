<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Brevo Class
 *
 * Sends through the synchronous transactional SMS endpoint, which returns
 * the message id together with the segment count and credit usage.
 *
 * @see https://developers.brevo.com/reference/send-transac-sms
 *
 * @since 2.1.0
 */
class Brevo extends AbstractGateway {

    /**
     * API base URL
     */
    const BASE_URL = 'https://api.brevo.com/v3';

    /**
     * Send path (synchronous)
     */
    const SEND_PATH = '/transactionalSMS/sms';

    /**
     * Account path, used for side-effect-free key validation
     */
    const ACCOUNT_PATH = '/account';

    /**
     * Tag attached to every message, for filtering Brevo stats
     */
    const TAG = 'texty';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'brevo';

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
            'docs_url'          => 'https://developers.brevo.com/docs/transactional-sms-endpoints',
            'signup_url'        => 'https://onboarding.brevo.com/account/register',
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
        return __( 'Brevo', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: 1: URL to the Brevo API keys page, 2: URL to the Texty setup guide
            __(
                'Send SMS with Brevo. Follow <a href="%1$s" target="_blank">this link</a> to create an API key in Brevo. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://account.brevo.com/advanced/api',
            'https://github.com/getdokan/texty/wiki/Brevo'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/brevo-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'api_key' => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['api_key'] ) ? $creds['api_key'] : '',
                'help'  => __( 'Create one in Brevo under SMTP & API > API Keys. If Authorized IPs is turned on for your Brevo account, add this server\'s IP address there too.', 'texty' ),
            ],
            'sender'  => [
                'name'  => __( 'Sender', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['sender'] ) ? $creds['sender'] : '',
                'help'  => __( 'Up to 11 letters/digits, or up to 15 digits for a numeric sender.', 'texty' ),
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
        $api_key = $this->cred( $creds, 'api_key' );
        $sender  = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender' );

        if ( '' === $api_key || '' === $sender ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Brevo API Key and Sender are required.', 'texty' )
            );
        }

        if ( ! $this->is_valid_sender( $sender ) ) {
            return Result::failure(
                'texty_invalid_sender',
                __( 'The Brevo sender must be up to 11 letters/digits, or up to 15 digits for a numeric sender.', 'texty' )
            );
        }

        $recipient = $this->normalize_number( $to );

        if ( '' === $recipient ) {
            return Result::failure(
                'texty_invalid_number',
                __( 'The phone number must contain between 6 and 15 digits, including the country code.', 'texty' )
            );
        }

        $body = [
            'sender'    => $sender,
            'recipient' => $recipient,
        ];

        if ( '' !== $options['template_id'] ) {
            if ( ! ctype_digit( $options['template_id'] ) ) {
                return Result::failure(
                    'texty_invalid_template',
                    __( 'The Brevo template ID must be a number.', 'texty' )
                );
            }

            $body['templateId'] = (int) $options['template_id'];

            if ( ! empty( $options['variables'] ) ) {
                $body['params'] = $options['variables'];
            }
        } else {
            $body['content'] = (string) $message;
        }

        $body['type']           = 'transactional';
        $body['unicodeEnabled'] = $options['unicode'] || $this->needs_unicode( (string) $message );
        $body['tag']            = self::TAG;

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . self::SEND_PATH,
            [
                'headers' => $this->auth_headers( $api_key ),
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        // Success needs both the documented 201 and a message id. Ids are
        // longs that can exceed 2^53; Response::json() keeps big ints as strings.
        if ( 201 === $response->status() ) {
            return Result::success( $response->get( 'messageId' ), $response );
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
     * Checks the sender format locally, then the API key against the
     * side-effect-free `GET /v3/account` endpoint.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds   = $this->request_creds( $request );
        $api_key = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $sender  = sanitize_text_field( $this->cred( $creds, 'sender' ) );

        if ( '' === $api_key || '' === $sender ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Brevo API Key and Sender are required.', 'texty' )
            );
        }

        if ( ! $this->is_valid_sender( $sender ) ) {
            return new WP_Error(
                'texty_invalid_sender',
                __( 'The Brevo sender must be up to 11 letters/digits, or up to 15 digits for a numeric sender.', 'texty' )
            );
        }

        $sanitized = [
            'api_key' => $api_key,
            'sender'  => $sender,
        ];

        $response = $this->http()->get(
            $this->base_url( self::BASE_URL, $sanitized ) . self::ACCOUNT_PATH,
            [
                'headers' => $this->auth_headers( $api_key ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() ) {
            return new WP_Error(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        return $sanitized;
    }

    /**
     * Build the request headers
     *
     * Brevo takes the raw key in an `api-key` header (no Bearer/Basic prefix).
     *
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $api_key ) {
        return [
            'api-key' => $api_key,
            'Accept'  => 'application/json',
        ];
    }

    /**
     * Normalize a phone number to the digits Brevo expects
     *
     * Strips `+`, spaces, dashes and any other non-digit, then requires
     * 6–15 digits to remain.
     *
     * @param string $to
     *
     * @return string Digits only, or '' when the number is out of range
     */
    private function normalize_number( $to ) {
        $digits = preg_replace( '/\D+/', '', (string) $to );
        $length = strlen( (string) $digits );

        return $length >= 6 && $length <= 15 ? (string) $digits : '';
    }

    /**
     * Whether a sender name matches Brevo's rules
     *
     * Alphanumeric senders: up to 11 letters/digits. Numeric senders: up to
     * 15 digits. No spaces or punctuation.
     *
     * @param string $sender
     *
     * @return bool
     */
    private function is_valid_sender( $sender ) {
        return 1 === preg_match( '/^(?:[A-Za-z0-9]{1,11}|[0-9]{1,15})$/', (string) $sender );
    }

    /**
     * Whether a message has characters outside the GSM-7 alphabet
     *
     * Covers the GSM 03.38 basic set and its extension table.
     *
     * @param string $message
     *
     * @return bool
     */
    private function needs_unicode( $message ) {
        return 1 !== preg_match( '/\A[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà\^{}\\\\\[~\]|€\f]*\z/u', $message );
    }

    /**
     * Error code from a Brevo error body
     *
     * Some documented codes contain spaces (`api-key not found`), so the
     * code is lower-cased and reduced to a slug. Falls back to the status.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $code = $response->get( 'code' );

        if ( is_scalar( $code ) ) {
            $slug = trim( (string) preg_replace( '/[^a-z0-9_-]+/', '_', strtolower( (string) $code ) ), '_' );

            if ( '' !== $slug ) {
                return $slug;
            }
        }

        return 'brevo_http_' . $response->status();
    }

    /**
     * Error message from a Brevo error body, verbatim when present
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $message = $response->get( 'message' );

        if ( is_string( $message ) && '' !== trim( $message ) ) {
            return $message;
        }

        return sprintf(
            // translators: %d: HTTP status code
            __( 'Brevo returned an unexpected response (HTTP %d).', 'texty' ),
            $response->status()
        );
    }
}
