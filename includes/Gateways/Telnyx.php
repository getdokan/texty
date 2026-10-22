<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Telnyx Class
 *
 * @since 2.1.0
 *
 * @see https://developers.telnyx.com/api/messaging/send-message
 */
class Telnyx extends AbstractGateway {

    /**
     * Default API base URL
     */
    const BASE_URL = 'https://api.telnyx.com/v2';

    /**
     * Sender kinds
     */
    const SENDER_NUMBER       = 'number';
    const SENDER_SHORT_CODE   = 'short_code';
    const SENDER_ALPHANUMERIC = 'alphanumeric';
    const SENDER_INVALID      = 'invalid';

    /**
     * Recipient statuses that mean Telnyx accepted the message
     *
     * @var string[]
     */
    private static $accepted_statuses = [ 'queued', 'sending', 'sent' ];

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'telnyx';

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
            'docs_url'          => 'https://developers.telnyx.com/api/messaging/send-message',
            'signup_url'        => 'https://telnyx.com/sign-up',
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
        return __( 'Telnyx', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the Telnyx API keys page, 2: URL to the Texty setup guide */
            __(
                'Send SMS with Telnyx. Follow <a href="%1$s" target="_blank">this link</a> to get your API key. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://portal.telnyx.com/#/app/api-keys',
            'https://github.com/getdokan/texty/wiki/Telnyx'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/telnyx-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'api_key'              => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_key' ),
                'help'  => __( 'Mission Control Portal → Account Settings → Keys & Credentials → API Keys.', 'texty' ),
            ],
            'from'                 => [
                'name'         => __( 'From Number / Sender ID', 'texty' ),
                'type'         => 'text',
                'value'        => $this->cred( $creds, 'from' ),
                'required'     => false,
                'alphanumeric' => true,
                'help'         => __( 'E.164 number (+15551234567), short code, or alphanumeric sender ID owned by your account. An alphanumeric sender ID also needs the Messaging Profile ID. US numbers must be registered for 10DLC (or toll-free verified) in Telnyx before they can send.', 'texty' ),
            ],
            'messaging_profile_id' => [
                'name'     => __( 'Messaging Profile ID', 'texty' ),
                'type'     => 'text',
                'value'    => $this->cred( $creds, 'messaging_profile_id' ),
                'required' => false,
                'help'     => __( 'Required for alphanumeric sender IDs (together with the sender ID above) and for number pools (leave the sender empty). Optional when sending from a number or short code.', 'texty' ),
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
        $options    = SendOptions::normalize( $options );
        $creds      = $this->creds();
        $api_key    = $this->cred( $creds, 'api_key' );
        $from       = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );
        $profile_id = $this->cred( $creds, 'messaging_profile_id' );

        if ( '' === $api_key || ( '' === $from && '' === $profile_id ) ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Telnyx API Key and a From Number / Sender ID or Messaging Profile ID are required.', 'texty' )
            );
        }

        $body = [
            'to' => $this->normalize_to( $to ),
        ];

        if ( '' !== $from ) {
            $body['from'] = $this->normalize_from( $from );
        }

        if ( '' !== $profile_id ) {
            $body['messaging_profile_id'] = $profile_id;
        }

        $body['text'] = (string) $message;
        $body['type'] = 'SMS';

        // Default encoding is "auto" (smart encoding). Never send "gsm7":
        // Telnyx rejects the message if any character doesn't fit.
        if ( true === $options['unicode'] ) {
            $body['encoding'] = 'ucs2';
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/messages',
            [
                'headers' => $this->auth_headers( $api_key ),
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        $id = $response->get( 'data.id' );

        if ( 200 === $response->status() && is_scalar( $id ) && '' !== (string) $id && empty( $response->get( 'data.errors' ) ) ) {
            $status = $response->get( 'data.to.0.status' );

            if ( null === $status || in_array( $status, self::$accepted_statuses, true ) ) {
                return Result::success( $id, $response );
            }

            return Result::failure(
                'telnyx_rejected',
                sprintf(
                    /* translators: %s: recipient status reported by Telnyx */
                    __( 'Telnyx did not accept the message (status: %s).', 'texty' ),
                    is_scalar( $status ) ? (string) $status : ''
                ),
                $response
            );
        }

        $error = $this->error( $response );

        return Result::failure( $error['code'], $error['message'], $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the field formats, then calls the read-only `GET /balance`
     * (and `GET /messaging_profiles/{id}` when a profile is set).
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $submitted = $this->request_creds( $request );
        $creds     = [
            'api_key'              => sanitize_text_field( $this->cred( $submitted, 'api_key' ) ),
            'from'                 => sanitize_text_field( $this->cred( $submitted, 'from' ) ),
            'messaging_profile_id' => sanitize_text_field( $this->cred( $submitted, 'messaging_profile_id' ) ),
        ];

        if ( '' === $creds['api_key'] ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Telnyx API Key is required.', 'texty' )
            );
        }

        if ( '' === $creds['from'] && '' === $creds['messaging_profile_id'] ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Telnyx needs a From Number / Sender ID or a Messaging Profile ID.', 'texty' )
            );
        }

        if ( '' !== $creds['from'] ) {
            $kind = $this->sender_kind( $creds['from'] );

            if ( self::SENDER_INVALID === $kind ) {
                return new WP_Error(
                    'texty_invalid_sender',
                    __( 'Enter the sender as an E.164 number (+15551234567), a short code, or an alphanumeric sender ID of up to 11 characters.', 'texty' )
                );
            }

            if ( self::SENDER_ALPHANUMERIC === $kind && '' === $creds['messaging_profile_id'] ) {
                return new WP_Error(
                    'texty_missing_messaging_profile',
                    __( 'Telnyx requires a Messaging Profile ID when sending from an alphanumeric sender ID.', 'texty' )
                );
            }

            $creds['from'] = $this->normalize_from( $creds['from'] );
        }

        if ( '' !== $creds['messaging_profile_id'] && ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $creds['messaging_profile_id'] ) ) {
            return new WP_Error(
                'texty_invalid_messaging_profile',
                __( 'The Messaging Profile ID should look like abc85f64-5717-4562-b3fc-2c9600000000.', 'texty' )
            );
        }

        $base_url = $this->base_url( self::BASE_URL, $creds );
        $headers  = $this->auth_headers( $creds['api_key'] );

        $response = $this->http()->get( $base_url . '/balance', [ 'headers' => $headers ] );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        // A key without billing scope gets 403 / 10010 here but can still send.
        $no_billing_scope = 403 === $response->status() && '10010' === $this->error( $response )['code'];

        if ( 200 !== $response->status() && ! $no_billing_scope ) {
            $error = $this->error( $response );

            return new WP_Error( $error['code'], $error['message'], $response->payload() );
        }

        if ( '' === $creds['messaging_profile_id'] ) {
            return $creds;
        }

        $response = $this->http()->get(
            $base_url . '/messaging_profiles/' . rawurlencode( $creds['messaging_profile_id'] ),
            [ 'headers' => $headers ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() ) {
            $error = $this->error( $response );

            return new WP_Error( $error['code'], $error['message'], $response->payload() );
        }

        // The profile body includes `v1_secret`, so it is never attached as error data.
        if ( false === $response->get( 'data.enabled' ) ) {
            return new WP_Error(
                'texty_messaging_profile_disabled',
                __( 'This Telnyx messaging profile is disabled.', 'texty' )
            );
        }

        return $creds;
    }

    /**
     * Request headers with Bearer auth
     *
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $api_key ) {
        return [
            'Authorization' => 'Bearer ' . $api_key,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Normalize a recipient to E.164 with a leading `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_to( $to ) {
        return '+' . preg_replace( '/\D+/', '', (string) $to );
    }

    /**
     * Classify a sender value
     *
     * A leading `+`, or 9-15 digits, is a phone number; 3-8 bare digits are a
     * short code; anything else of up to 11 letters, digits and spaces is an
     * alphanumeric sender ID.
     *
     * @param string $from
     *
     * @return string One of the SENDER_* constants
     */
    private function sender_kind( $from ) {
        $from = trim( (string) $from );

        if ( '+' === substr( $from, 0, 1 ) ) {
            return preg_match( '/^\+[1-9]\d{6,14}$/', $from ) ? self::SENDER_NUMBER : self::SENDER_INVALID;
        }

        if ( preg_match( '/^\d{3,8}$/', $from ) ) {
            return self::SENDER_SHORT_CODE;
        }

        if ( preg_match( '/^[1-9]\d{8,14}$/', $from ) ) {
            return self::SENDER_NUMBER;
        }

        if ( preg_match( '/^[A-Za-z0-9 ]{1,11}$/', $from ) && preg_match( '/[A-Za-z ]/', $from ) ) {
            return self::SENDER_ALPHANUMERIC;
        }

        return self::SENDER_INVALID;
    }

    /**
     * Give phone-number senders a leading `+`; leave other senders as they are
     *
     * @param string $from
     *
     * @return string
     */
    private function normalize_from( $from ) {
        $from = trim( (string) $from );

        if ( self::SENDER_NUMBER === $this->sender_kind( $from ) && '+' !== substr( $from, 0, 1 ) ) {
            return '+' . $from;
        }

        return $from;
    }

    /**
     * Pull the first error out of a Telnyx response
     *
     * Checks the top-level `errors` envelope (4xx/5xx) first, then
     * `data.errors` (rejections inside a 200).
     *
     * @param Response $response
     *
     * @return array { code: string, message: string }
     */
    private function error( Response $response ) {
        $error = $response->get( 'errors.0' );

        if ( ! is_array( $error ) ) {
            $error = $response->get( 'data.errors.0' );
        }

        $error = is_array( $error ) ? $error : [];

        $code = isset( $error['code'] ) && is_scalar( $error['code'] ) ? trim( (string) $error['code'] ) : '';

        if ( '' === $code ) {
            $code = 'telnyx_http_' . $response->status();
        }

        $message = '';

        foreach ( [ 'detail', 'title' ] as $field ) {
            if ( isset( $error[ $field ] ) && is_string( $error[ $field ] ) && '' !== $error[ $field ] ) {
                $message = $error[ $field ];
                break;
            }
        }

        return [
            'code'    => $code,
            'message' => $message,
        ];
    }
}
