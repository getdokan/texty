<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Unifonic Class
 *
 * Sends through the Unifonic REST SMS API. The AppSid travels as a
 * form-encoded body field (never in the URL), so it stays out of URLs and
 * access logs; the send pipeline scrubs it from stored payloads because the
 * field is declared as a password.
 *
 * Unifonic charges on submission, so a send is never retried once a
 * response arrived.
 *
 * @see https://docs.unifonic.com/articles/products-documentation/sms-03-sending-api
 */
class Unifonic extends AbstractGateway {

    /**
     * API base URL
     */
    const BASE_URL = 'https://el.cloud.unifonic.com';

    /**
     * Send path
     */
    const SEND_PATH = '/rest/SMS/messages';

    /**
     * Read-only report path used to check the AppSid
     */
    const VALIDATE_PATH = '/rest/SMS/Messages/GetMessagesDetails';

    /**
     * Error code Unifonic returns on success
     */
    const SUCCESS_CODE = 'ER-00';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'unifonic';

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
            'docs_url'          => 'https://docs.unifonic.com/articles/products-documentation/sms-03-sending-api',
            'signup_url'        => 'https://cloud.unifonic.com/',
            'regions'           => [ 'SA', 'AE', 'KW', 'QA', 'OM', 'BH' ],
            'features'          => [
                'unicode'       => true,
                'sender_id'     => true,
                'template'      => false,
                'balance_check' => false,
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
        return __( 'Unifonic', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the Unifonic console, 2: URL to the Texty setup guide */
            __(
                'Send SMS with Unifonic. Follow <a href="%1$s" target="_blank">this link</a> to get the AppSid from the Unifonic console. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://cloud.unifonic.com/',
            'https://github.com/getdokan/texty/wiki/Unifonic'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/unifonic-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'app_sid'   => [
                'name'  => __( 'AppSid', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'app_sid' ),
                'help'  => __( 'Create an application under Developers > Applications in the Unifonic console and copy its AppSid.', 'texty' ),
            ],
            'sender_id' => [
                'name'     => __( 'Sender ID', 'texty' ),
                'type'     => 'text',
                'required' => false,
                'value'    => $this->cred( $creds, 'sender_id' ),
                'help'     => __( 'Sender IDs must be registered and approved by Unifonic before use (in Saudi Arabia, under CST/CITC rules). Leave empty to use your application\'s default sender. Trial accounts can use UNITEST.', 'texty' ),
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
        $app_sid = $this->cred( $creds, 'app_sid' );

        if ( '' === $app_sid ) {
            return Result::failure( 'texty_missing_credentials', $this->missing_credentials_message() );
        }

        $sender = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender_id' );

        // Unicode is detected by Unifonic automatically and templates are not
        // supported for SMS, so `unicode`, `template_id` and `variables` are
        // ignored. No CorrelationID is sent: a repeated one is rejected as a
        // duplicate (409).
        $form = [ 'AppSid' => $app_sid ];

        if ( '' !== $sender ) {
            $form['SenderID'] = $sender;
        }

        $form['Recipient']    = $this->normalize_recipient( $to );
        $form['Body']         = (string) $message;
        $form['responseType'] = 'JSON';

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . self::SEND_PATH,
            [
                'headers' => $this->headers(),
                'form'    => $form,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( ! $this->is_accepted( $response ) ) {
            return Result::failure( $this->error_code( $response ), $this->error_message( $response ), $response );
        }

        $status = $response->get( 'data.Status' );

        if ( is_string( $status ) && in_array( strtolower( $status ), [ 'rejected', 'failed' ], true ) ) {
            return Result::failure(
                'unifonic_rejected',
                sprintf(
                    /* translators: %s: Message status reported by Unifonic, e.g. Rejected */
                    __( 'Unifonic accepted the request but reported the message as %s.', 'texty' ),
                    $status
                ),
                $response
            );
        }

        return Result::success( $this->message_id( $response ), $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the AppSid against the read-only GetMessagesDetails report,
     * which sends nothing and costs nothing. The Sender ID can't be checked
     * live; a wrong one only fails at send time (HTTP 480).
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds     = $this->request_creds( $request );
        $app_sid   = sanitize_text_field( $this->cred( $creds, 'app_sid' ) );
        $sender_id = sanitize_text_field( $this->cred( $creds, 'sender_id' ) );

        if ( '' === $app_sid ) {
            return new WP_Error( 'texty_missing_credentials', $this->missing_credentials_message() );
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . self::VALIDATE_PATH,
            [
                'headers' => $this->headers(),
                'form'    => [
                    'AppSid'       => $app_sid,
                    'Limit'        => 1,
                    'responseType' => 'JSON',
                ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( ! $this->is_accepted( $response ) ) {
            $code = in_array( $response->status(), [ 401, 402 ], true )
                ? 'unifonic_invalid_credentials'
                : $this->error_code( $response );

            return new WP_Error( $code, $this->error_message( $response ), $response->payload() );
        }

        return [
            'app_sid'   => $app_sid,
            'sender_id' => $sender_id,
        ];
    }

    /**
     * Request headers shared by every call
     *
     * @return array
     */
    private function headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ];
    }

    /**
     * Normalize a phone number to Unifonic's format: international digits
     * without a leading `+` or `00`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_recipient( $to ) {
        $digits = (string) preg_replace( '/\D+/', '', (string) $to );

        if ( 0 === strpos( $digits, '00' ) ) {
            $digits = substr( $digits, 2 );
        }

        return $digits;
    }

    /**
     * Whether a response is a documented success envelope: HTTP 200,
     * `success` true (bool or the string "true") and errorCode ER-00
     *
     * @param Response $response
     *
     * @return bool
     */
    private function is_accepted( Response $response ) {
        return 200 === $response->status()
            && $this->is_true( $response->get( 'success' ) )
            && self::SUCCESS_CODE === $response->get( 'errorCode' );
    }

    /**
     * Strictly read Unifonic's `success` flag, which is documented both as
     * a boolean and as the string "true"
     *
     * @param mixed $value
     *
     * @return bool
     */
    private function is_true( $value ) {
        return true === $value || ( is_string( $value ) && 'true' === strtolower( trim( $value ) ) );
    }

    /**
     * Read the provider message id as a string
     *
     * Large ids are decoded as strings by the HTTP response (JSON_BIGINT_AS_STRING);
     * smaller ones arrive as integers and are cast without loss.
     *
     * @param Response $response
     *
     * @return string
     */
    private function message_id( Response $response ) {
        $id = $response->get( 'data.MessageID' );

        if ( is_int( $id ) || is_string( $id ) ) {
            return trim( (string) $id );
        }

        return '';
    }

    /**
     * Error code from a response: the body's errorCode, else one derived
     * from the HTTP status
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $code = $response->get( 'errorCode' );

        if ( is_scalar( $code ) && ! is_bool( $code ) && '' !== trim( (string) $code ) && self::SUCCESS_CODE !== $code ) {
            return trim( (string) $code );
        }

        return 'unifonic_http_' . $response->status();
    }

    /**
     * Error message from a response: the body's message, else the meaning of
     * the HTTP status, else a generic message
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $message = $response->get( 'message' );

        if ( is_string( $message ) && '' !== trim( $message ) ) {
            return trim( $message );
        }

        $statuses = $this->status_messages();

        if ( isset( $statuses[ $response->status() ] ) ) {
            return $statuses[ $response->status() ];
        }

        return __( 'Unknown error', 'texty' );
    }

    /**
     * Documented Unifonic HTTP status meanings
     *
     * @return array
     */
    private function status_messages() {
        return [
            401 => __( 'Authentication failed', 'texty' ),
            402 => __( 'Missing AppSid parameter', 'texty' ),
            403 => __( 'Invalid IP address', 'texty' ),
            409 => __( 'Duplicate message', 'texty' ),
            410 => __( 'Invalid recipient format', 'texty' ),
            412 => __( 'Message body too long', 'texty' ),
            421 => __( 'Message body is empty', 'texty' ),
            422 => __( 'No recipient', 'texty' ),
            429 => __( 'Too many requests', 'texty' ),
            440 => __( 'Wrong sender format', 'texty' ),
            449 => __( 'Message body is empty', 'texty' ),
            459 => __( 'Authentication parameters are incorrectly base64-encoded', 'texty' ),
            460 => __( 'Invalid encoding', 'texty' ),
            480 => __( 'This user cannot use the specified SenderID', 'texty' ),
            481 => __( 'Country does not exist', 'texty' ),
            482 => __( 'Invalid destination number', 'texty' ),
            483 => __( 'Operator not found', 'texty' ),
            599 => __( 'Request failed', 'texty' ),
        ];
    }

    /**
     * Message shown when the AppSid is missing
     *
     * @return string
     */
    private function missing_credentials_message() {
        return __( 'Unifonic AppSid is required.', 'texty' );
    }
}
