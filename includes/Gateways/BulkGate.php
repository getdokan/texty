<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * BulkGate Class
 *
 * Sends through the BulkGate Simple Transactional API. Credentials travel in
 * the JSON body (no auth header). BulkGate charges on submit, so a send is
 * never retried once any response (or transport failure) came back.
 *
 * @see https://www.bulkgate.com/en/docs/simple-api/simple-transactional/
 * @see https://www.bulkgate.com/en/docs/simple-api/simple-check-credit-balance/
 */
class BulkGate extends AbstractGateway {

    /**
     * API base URL
     */
    const BASE_URL = 'https://portal.bulkgate.com/api/1.0/simple';

    /**
     * BulkGate system number (no sender value needed)
     */
    const SENDER_SYSTEM = 'gSystem';

    /**
     * Registered text sender
     */
    const SENDER_TEXT = 'gText';

    /**
     * Verified own number
     */
    const SENDER_OWN = 'gOwn';

    /**
     * BulkGate Sender ID profile
     */
    const SENDER_PROFILE = 'gProfile';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'bulkgate';

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
            'docs_url'          => 'https://www.bulkgate.com/en/docs/simple-api/simple-transactional/',
            'signup_url'        => 'https://portal.bulkgate.com/sign/up',
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
        return __( 'BulkGate', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the BulkGate portal, 2: URL to the Texty setup guide */
            __(
                'Send SMS with BulkGate. Follow <a href="%1$s" target="_blank">this link</a> to create an API application and get its Application ID and Token. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://portal.bulkgate.com/',
            'https://github.com/getdokan/texty/wiki/BulkGate'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/bulkgate-logo.png';
    }

    /**
     * Get the settings
     *
     * `sender_type` is a select of BulkGate's sender enum; `sender` is
     * optional because the system number takes no value.
     *
     * @return array
     */
    public function get_settings() {
        $creds       = $this->creds();
        $sender_type = $this->cred( $creds, 'sender_type' );

        return [
            'application_id'    => [
                'name'  => __( 'Application ID', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'application_id' ),
                'help'  => __( 'BulkGate portal → Modules & APIs → your API → Settings → Login credentials.', 'texty' ),
            ],
            'application_token' => [
                'name'  => __( 'Application Token', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'application_token' ),
                'help'  => __( 'Shown under Application Token in the same Login credentials section. If you restrict API access by IP address, add this server\'s IP to the allow-list, otherwise BulkGate rejects the credentials.', 'texty' ),
            ],
            'sender_type'       => [
                'name'    => __( 'Sender Type', 'texty' ),
                'type'    => 'select',
                'value'   => '' !== $sender_type ? $sender_type : self::SENDER_SYSTEM,
                'options' => [
                    self::SENDER_SYSTEM  => __( 'BulkGate system number', 'texty' ),
                    self::SENDER_TEXT    => __( 'Text sender (registered name)', 'texty' ),
                    self::SENDER_OWN     => __( 'Own number (verified in BulkGate)', 'texty' ),
                    self::SENDER_PROFILE => __( 'Sender ID profile', 'texty' ),
                ],
                'help'    => __( 'How the sender is shown to recipients. The system number needs no sender value.', 'texty' ),
            ],
            'sender'            => [
                'name'     => __( 'Sender', 'texty' ),
                'type'     => 'text',
                'value'    => $this->cred( $creds, 'sender' ),
                'required' => false,
                'help'     => __( 'Text sender: the registered name (letters, digits and spaces, up to 11 characters). Own number: your verified number with country code. Sender ID profile: the numeric profile ID. Ignored for the system number.', 'texty' ),
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
        $app_id  = $this->cred( $creds, 'application_id' );
        $token   = $this->cred( $creds, 'application_token' );

        if ( '' === $app_id || '' === $token ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'BulkGate Application ID and Application Token are required.', 'texty' )
            );
        }

        $message = (string) $message;
        $body    = [
            'application_id'    => (int) $app_id,
            'application_token' => $token,
            'number'            => $this->normalize_number( $to ),
            'text'              => $message,
            'unicode'           => $options['unicode'] || $this->needs_unicode( $message ),
        ];

        $sender = $this->sender( $creds, $options['from'] );

        if ( null !== $sender ) {
            $body['sender_id']       = $sender['type'];
            $body['sender_id_value'] = $sender['value'];
        }

        // Single attempt only: BulkGate charges on submit, so no retry and
        // no failover to the backup host.
        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/transactional',
            [
                'json' => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        $status = $response->get( 'data.status' );

        if ( $response->is_2xx() && 'accepted' === $status ) {
            return Result::success( $response->get( 'data.sms_id' ), $response );
        }

        if ( $response->is_2xx() && is_string( $status ) && '' !== $status ) {
            return Result::failure(
                'bulkgate_not_accepted',
                sprintf(
                    /* translators: %s: message status returned by BulkGate */
                    __( 'BulkGate did not accept the message (status: %s).', 'texty' ),
                    $status
                ),
                $response
            );
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
     * Checks the field formats first, then confirms the credentials against
     * the side-effect-free `/simple/info` (credit balance) endpoint.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds       = $this->request_creds( $request );
        $app_id      = sanitize_text_field( $this->cred( $creds, 'application_id' ) );
        $token       = sanitize_text_field( $this->cred( $creds, 'application_token' ) );
        $sender_type = sanitize_text_field( $this->cred( $creds, 'sender_type' ) );
        $sender      = sanitize_text_field( $this->cred( $creds, 'sender' ) );

        if ( '' === $app_id || '' === $token ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'BulkGate Application ID and Application Token are required.', 'texty' )
            );
        }

        if ( 1 !== preg_match( '/^\d+$/', $app_id ) ) {
            return new WP_Error(
                'bulkgate_invalid_application_id',
                __( 'BulkGate Application ID must contain digits only.', 'texty' )
            );
        }

        $type = '' === $sender_type ? self::SENDER_SYSTEM : $this->canonical_sender_type( $sender_type );

        if ( null === $type ) {
            return new WP_Error(
                'bulkgate_invalid_sender_type',
                __( 'BulkGate Sender Type must be one of gSystem, gText, gOwn or gProfile.', 'texty' )
            );
        }

        $sender_error = $this->validate_sender( $type, $sender );

        if ( $sender_error instanceof WP_Error ) {
            return $sender_error;
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . '/info',
            [
                'json' => [
                    'application_id'    => (int) $app_id,
                    'application_token' => $token,
                ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        $wallet = $response->get( 'data.wallet' );

        if ( ! $response->is_2xx() || ! is_scalar( $wallet ) || '' === (string) $wallet ) {
            $error_type = $response->get( 'type' );
            $code       = is_string( $error_type ) && '' !== $error_type ? 'bulkgate_' . sanitize_key( $error_type ) : 'bulkgate_auth_failed';

            return new WP_Error(
                $code,
                sprintf(
                    /* translators: %s: error message returned by BulkGate */
                    __( '%s Check the Application ID and Token. If you restrict API access by IP address, add this server\'s IP to the allow-list.', 'texty' ),
                    $this->error_message( $response )
                ),
                $response->payload()
            );
        }

        return [
            'application_id'    => $app_id,
            'application_token' => $token,
            'sender_type'       => $type,
            'sender'            => $sender,
        ];
    }

    /**
     * Resolve the sender type and value for a send
     *
     * The saved type decides how the value is sent. A non-empty `from`
     * option replaces the saved value; when the saved type is gSystem (which
     * takes no value) the type is inferred from the override instead:
     * number-like → gOwn, anything else → gText.
     *
     * @param array  $creds
     * @param string $from  Per-message sender override
     *
     * @return array|null `[ type, value ]`, or null to let BulkGate use its system number
     */
    private function sender( array $creds, $from ) {
        $type  = $this->canonical_sender_type( $this->cred( $creds, 'sender_type' ) );
        $value = '' !== $from ? $from : $this->cred( $creds, 'sender' );

        if ( null === $type || self::SENDER_SYSTEM === $type ) {
            if ( '' === $from ) {
                return null;
            }

            $type = $this->is_number_like( $from ) ? self::SENDER_OWN : self::SENDER_TEXT;
        }

        if ( self::SENDER_OWN === $type || self::SENDER_PROFILE === $type ) {
            $value = (string) preg_replace( '/\D+/', '', $value );
        }

        if ( '' === $value ) {
            return null;
        }

        return [
            'type'  => $type,
            'value' => $value,
        ];
    }

    /**
     * Check the sender value against its type
     *
     * @param string $type  Canonical sender type
     * @param string $value Sender value
     *
     * @return WP_Error|null
     */
    private function validate_sender( $type, $value ) {
        if ( self::SENDER_TEXT === $type && 1 !== preg_match( '/^[A-Za-z0-9 ]{1,11}$/', $value ) ) {
            return new WP_Error(
                'bulkgate_invalid_sender',
                __( 'A BulkGate text sender must be 1 to 11 letters, digits or spaces.', 'texty' )
            );
        }

        if ( self::SENDER_OWN === $type && ! $this->is_number_like( $value ) ) {
            return new WP_Error(
                'bulkgate_invalid_sender',
                __( 'A BulkGate own-number sender must be a phone number with country code.', 'texty' )
            );
        }

        if ( self::SENDER_PROFILE === $type && 1 !== preg_match( '/^\d+$/', $value ) ) {
            return new WP_Error(
                'bulkgate_invalid_sender',
                __( 'A BulkGate Sender ID profile must be a numeric profile ID.', 'texty' )
            );
        }

        return null;
    }

    /**
     * Map a sender type, case-insensitively, to BulkGate's enum value
     *
     * @param string $type
     *
     * @return string|null Null for an unknown or empty type
     */
    private function canonical_sender_type( $type ) {
        $types = [
            'gsystem'  => self::SENDER_SYSTEM,
            'gtext'    => self::SENDER_TEXT,
            'gown'     => self::SENDER_OWN,
            'gprofile' => self::SENDER_PROFILE,
        ];

        $type = strtolower( trim( (string) $type ) );

        return isset( $types[ $type ] ) ? $types[ $type ] : null;
    }

    /**
     * Whether a value looks like a phone number (optional +, digits, spaces)
     *
     * @param string $value
     *
     * @return bool
     */
    private function is_number_like( $value ) {
        return 1 === preg_match( '/^\+?[0-9 ]{6,}$/', (string) $value );
    }

    /**
     * Normalize a recipient to BulkGate's format: international digits, no `+`
     *
     * A leading `00` international prefix is dropped as well.
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_number( $to ) {
        $number = (string) preg_replace( '/\D+/', '', (string) $to );

        if ( 0 === strpos( $number, '00' ) ) {
            $number = substr( $number, 2 );
        }

        return $number;
    }

    /**
     * Whether a message has characters outside the GSM 03.38 alphabet
     *
     * BulkGate defaults to 7-bit encoding, so such messages need `unicode`.
     *
     * @param string $message
     *
     * @return bool
     */
    private function needs_unicode( $message ) {
        return 1 === preg_match(
            '/[^@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà\^{}\\\\\[~\]|€\f]/u',
            $message
        );
    }

    /**
     * Error code from a BulkGate error body, or the HTTP status
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $type = $response->get( 'type' );

        if ( is_string( $type ) && '' !== $type ) {
            return $type;
        }

        return (string) $response->status();
    }

    /**
     * Readable error from a BulkGate error body (`error`, plus `detail` if set)
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $error  = $response->get( 'error' );
        $detail = $response->get( 'detail' );

        if ( ! is_string( $error ) || '' === $error ) {
            return __( 'BulkGate returned an unexpected response.', 'texty' );
        }

        if ( is_string( $detail ) && '' !== $detail ) {
            return sprintf( '%1$s (%2$s)', $error, $detail );
        }

        return $error;
    }
}
