<?php

namespace Texty\Api;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound webhook receiver.
 *
 * Receives inbound SMS posts from gateway providers (currently Twilio) and
 * processes compliance keywords (STOP / START) against the opt-out list.
 *
 * Endpoint:
 *   POST /texty/v1/inbound/twilio
 *
 * Twilio webhook configuration:
 *   In the Twilio console, set the SMS "A MESSAGE COMES IN" webhook to:
 *     https://<your-site>/wp-json/texty/v1/inbound/twilio
 *
 * Authentication is via Twilio's `X-Twilio-Signature` header — verified with
 * the auth token stored in `texty_settings[twilio][token]`. The endpoint is
 * intentionally public (no `manage_options` check) because gateways post from
 * their own infrastructure.
 *
 * @since 1.2.0
 */
class Inbound extends Base {

    /**
     * Initialize.
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'inbound';
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/twilio',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'handle_twilio' ],
                    'permission_callback' => '__return_true',
                ],
            ]
        );
    }

    /**
     * Handle a Twilio inbound SMS webhook.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_twilio( $request ) {
        $verified = $this->verify_twilio_signature( $request );

        if ( is_wp_error( $verified ) ) {
            return $verified;
        }

        $from = sanitize_text_field( (string) $request->get_param( 'From' ) );
        $body = (string) $request->get_param( 'Body' );

        if ( '' === $from ) {
            return new WP_Error(
                'texty_inbound_missing_from',
                __( 'Missing From parameter.', 'texty' ),
                [ 'status' => 400 ]
            );
        }

        $service = texty()->notification_settings();
        $keyword = $service->detect_keyword( $body );

        $reply = '';

        if ( 'opt_out' === $keyword ) {
            $service->opt_out( $from );
            $reply = __(
                'You have been unsubscribed and will no longer receive messages. Reply START to resubscribe.',
                'texty'
            );
        } elseif ( 'opt_in' === $keyword ) {
            $service->opt_in( $from );
            $reply = __(
                'You have been resubscribed to notifications. Reply STOP to unsubscribe.',
                'texty'
            );
        }

        /**
         * Fires after an inbound SMS is processed.
         *
         * @since 1.2.0
         *
         * @param string $from    Normalized sender phone number.
         * @param string $body    Raw message body.
         * @param string $keyword Detected keyword: '', 'opt_out', or 'opt_in'.
         */
        do_action( 'texty_inbound_message', $from, $body, $keyword );

        return $this->twiml_response( $reply );
    }

    /**
     * Verify the Twilio request signature.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return true|WP_Error
     */
    private function verify_twilio_signature( $request ) {
        $signature = $request->get_header( 'x_twilio_signature' );

        if ( empty( $signature ) ) {
            return new WP_Error(
                'texty_inbound_missing_signature',
                __( 'Missing Twilio signature.', 'texty' ),
                [ 'status' => 401 ]
            );
        }

        $creds = texty()->settings()->get( 'twilio' );
        $token = is_array( $creds ) && isset( $creds['token'] ) ? (string) $creds['token'] : '';

        if ( '' === $token ) {
            return new WP_Error(
                'texty_inbound_not_configured',
                __( 'Twilio gateway is not configured.', 'texty' ),
                [ 'status' => 503 ]
            );
        }

        $params = $request->get_params();

        // Twilio signature is computed only over POST body params, not headers.
        // Drop framework-injected REST routing keys so they don't poison the hash.
        unset( $params['rest_route'] );

        ksort( $params );

        $url = $this->current_url();

        $data = $url;
        foreach ( $params as $key => $value ) {
            $data .= $key . ( is_scalar( $value ) ? (string) $value : '' );
        }

        $expected = base64_encode( hash_hmac( 'sha1', $data, $token, true ) );

        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error(
                'texty_inbound_invalid_signature',
                __( 'Invalid Twilio signature.', 'texty' ),
                [ 'status' => 401 ]
            );
        }

        return true;
    }

    /**
     * Best-effort current request URL (used for signature verification).
     *
     * @return string
     */
    private function current_url() {
        $scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        return $scheme . '://' . $host . $uri;
    }

    /**
     * Build a TwiML response (XML envelope Twilio expects).
     *
     * @param string $message Optional reply message body.
     *
     * @return WP_REST_Response
     */
    private function twiml_response( $message ) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Response>';

        if ( '' !== $message ) {
            $xml .= '<Message>' . esc_html( $message ) . '</Message>';
        }

        $xml .= '</Response>';

        $response = new WP_REST_Response( $xml );
        $response->header( 'Content-Type', 'text/xml; charset=UTF-8' );

        return $response;
    }
}
