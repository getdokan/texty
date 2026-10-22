<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * D7 Networks Class
 *
 * @see https://d7networks.com/docs/sms/send-sms/
 */
class D7Networks extends AbstractGateway {

    /**
     * Default API base URL
     */
    const BASE_URL = 'https://api.d7networks.com';

    /**
     * Send endpoint path
     */
    const SEND_PATH = '/messages/v1/send';

    /**
     * Balance endpoint path (used for credential validation)
     */
    const BALANCE_PATH = '/messages/v1/balance';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'd7networks';

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
            'docs_url'          => 'https://d7networks.com/docs/sms/send-sms/',
            'signup_url'        => 'https://app.d7networks.com',
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
        return __( 'D7 Networks', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the D7 Networks dashboard, 2: URL to the Texty setup guide */
            __(
                'Send SMS with D7 Networks. Follow <a href="%1$s" target="_blank">this link</a> to create an API token in the Developer section of the D7 dashboard. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://app.d7networks.com',
            'https://github.com/getdokan/texty/wiki/D7Networks'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/d7networks-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'api_token'  => [
                'name'  => __( 'API Token', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_token' ),
                'help'  => __( 'Create an API token in the Developer section of the D7 dashboard (app.d7networks.com).', 'texty' ),
            ],
            'originator' => [
                'name'  => __( 'Originator (Sender ID)', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'originator' ),
                'help'  => __( 'Sender name or number shown on the recipient\'s phone. Alphanumeric sender IDs may need registration in some countries.', 'texty' ),
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
        $token      = $this->cred( $creds, 'api_token' );
        $originator = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'originator' );

        if ( '' === $token || '' === $originator ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'D7 Networks API Token and Originator are required.', 'texty' )
            );
        }

        $response = $this->http()->post(
            $this->base_url( self::BASE_URL, $creds ) . self::SEND_PATH,
            [
                'headers' => $this->auth_headers( $token ),
                'json'    => [
                    'messages'        => [
                        [
                            'channel'     => 'sms',
                            'recipients'  => [ $this->normalize_number( $to ) ],
                            'content'     => $message,
                            'msg_type'    => 'text',
                            // `auto` lets D7 pick UCS-2 for non-GSM text (Bangla, Arabic, ...).
                            'data_coding' => $options['unicode'] ? 'unicode' : 'auto',
                        ],
                    ],
                    'message_globals' => [
                        'originator' => $originator,
                    ],
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( $response->is_2xx() ) {
            $status = $response->get( 'status' );

            if ( 'accepted' === $status ) {
                return Result::success( $response->get( 'request_id' ), $response );
            }

            if ( 'rejected' === $status ) {
                $error = $this->parse_error( $response );

                return Result::failure(
                    'd7networks_rejected',
                    '' !== $error['message'] ? $error['message'] : __( 'D7 Networks rejected the message.', 'texty' ),
                    $response
                );
            }
        }

        $error = $this->parse_error( $response );

        return Result::failure(
            '' !== $error['code'] ? $error['code'] : 'd7networks_http_' . $response->status(),
            '' !== $error['message'] ? $error['message'] : get_status_header_desc( $response->status() ),
            $response
        );
    }

    /**
     * Validate a REST API request
     *
     * Checks the token against the side-effect-free balance endpoint. The
     * originator can't be checked remotely, so only its presence is required.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds      = $this->request_creds( $request );
        $token      = sanitize_text_field( $this->cred( $creds, 'api_token' ) );
        $originator = sanitize_text_field( $this->cred( $creds, 'originator' ) );

        if ( '' === $token || '' === $originator ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'D7 Networks API Token and Originator are required.', 'texty' )
            );
        }

        $sanitized = [
            'api_token'  => $token,
            'originator' => $originator,
        ];

        $response = $this->http()->get(
            $this->base_url( self::BASE_URL, $sanitized ) . self::BALANCE_PATH,
            [
                'headers' => $this->auth_headers( $token ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        // The balance endpoint is only documented in D7's sample code; if it
        // ever disappears, fall back to format-only rather than block saving.
        if ( 404 === $response->status() ) {
            return $sanitized;
        }

        if ( 200 === $response->status() && is_numeric( $response->get( 'balance' ) ) ) {
            return $sanitized;
        }

        $error = $this->parse_error( $response );

        return new WP_Error(
            '' !== $error['code'] ? $error['code'] : 'd7networks_auth_failed',
            '' !== $error['message'] ? $error['message'] : __( 'Could not verify the D7 Networks API Token.', 'texty' ),
            $response->payload()
        );
    }

    /**
     * Build the request headers
     *
     * @param string $token
     *
     * @return array
     */
    private function auth_headers( $token ) {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Normalize a phone number to `+` followed by digits
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_number( $to ) {
        $digits = preg_replace( '/\D+/', '', (string) $to );

        if ( 0 === strpos( $digits, '00' ) ) {
            $digits = substr( $digits, 2 );
        }

        return '+' . $digits;
    }

    /**
     * Pull an error code and message from a D7 error body
     *
     * `detail` is an object for auth errors, a list for credit and
     * validation (FastAPI/pydantic) errors.
     *
     * @param Response $response
     *
     * @return array { code: string, message: string }
     */
    private function parse_error( Response $response ) {
        $code    = '';
        $message = '';
        $detail  = $response->get( 'detail' );

        if ( is_array( $detail ) && isset( $detail[0] ) && is_array( $detail[0] ) ) {
            $detail = $detail[0];
        }

        if ( is_array( $detail ) ) {
            if ( isset( $detail['code'] ) && is_scalar( $detail['code'] ) ) {
                $code = (string) $detail['code'];
            } elseif ( isset( $detail['type'] ) && is_scalar( $detail['type'] ) ) {
                $code = (string) $detail['type'];
            }

            if ( isset( $detail['message'] ) && is_scalar( $detail['message'] ) ) {
                $message = (string) $detail['message'];
            } elseif ( isset( $detail['msg'] ) && is_scalar( $detail['msg'] ) ) {
                $message = (string) $detail['msg'];

                if ( isset( $detail['loc'] ) && is_array( $detail['loc'] ) && ! empty( $detail['loc'] ) ) {
                    $message = sprintf(
                        /* translators: 1: Field path reported by D7 Networks, 2: Validation error message */
                        __( '%1$s: %2$s', 'texty' ),
                        implode( '.', array_map( 'strval', $detail['loc'] ) ),
                        $message
                    );
                }
            }
        } elseif ( is_string( $detail ) ) {
            $message = $detail;
        }

        if ( '' === $message ) {
            $fallback = $response->get( 'message' );
            $message  = is_scalar( $fallback ) ? (string) $fallback : '';
        }

        return [
            'code'    => $code,
            'message' => $message,
        ];
    }
}
