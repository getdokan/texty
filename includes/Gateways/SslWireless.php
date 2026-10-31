<?php

namespace Texty\Gateways;

use Texty\EgressIp;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * SSL Wireless iSMS Plus (Bangladesh) Class
 *
 * @see https://ismsplus.sslwireless.com/api-documentation
 */
class SslWireless implements GatewayInterface, HasDocLink, RequiresIpWhitelist {

    /**
     * Single SMS API endpoint
     */
    const ENDPOINT = 'https://smsplus.sslwireless.com/api/v3/send-sms';

    /**
     * Max length of the client SMS reference (csms_id) accepted by the API
     */
    const CSMS_ID_LENGTH = 20;

    /**
     * API status code for a request from a non-whitelisted IP
     */
    const IP_BLACKLISTED = 4003;

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'SSL Wireless', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: URL to the SSL Wireless iSMS Plus portal
            __(
                'Send SMS in Bangladesh with SSL Wireless iSMS Plus. Get your API token and Sender ID from the <a href="%1$s" target="_blank">iSMS Plus portal</a>, and whitelist your server IP there.',
                'texty'
            ),
            'https://ismsplus.sslwireless.com/'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/common-sms.png';
    }

    /**
     * Get the sign-up link
     *
     * @return string
     */
    public function doc_link() {
        return 'https://ismsplus.sslwireless.com/';
    }

    /**
     * Where IPs are whitelisted.
     *
     * The iSMS Plus portal lists whitelisted IPs under Management → User IP
     * List; adding one is done by SSL Wireless support.
     *
     * @return string
     */
    public function ip_whitelist_url() {
        return 'https://ismsplus.sslwireless.com/admin/user-ip-list';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = texty()->settings()->get( 'sslwireless' );

        return [
            'api_token' => [
                'name'  => __( 'API Token', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['api_token'] ) ? $creds['api_token'] : '',
                'help'  => '',
            ],
            'sid'       => [
                'name'  => __( 'SID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['sid'] ) ? $creds['sid'] : '',
                'help'  => __( 'Stakeholder / Sender ID provided by SSL Wireless.', 'texty' ),
            ],
        ];
    }

    /**
     * Send SMS
     *
     * @param string $to
     * @param string $message
     *
     * @return WP_Error|array
     */
    public function send( $to, $message ) {
        $creds = texty()->settings()->get( 'sslwireless' );

        if ( ! is_array( $creds ) || empty( $creds['api_token'] ) || empty( $creds['sid'] ) ) {
            return new WP_Error( 'sslwireless_missing_credentials', __( 'SSL Wireless API token and SID are not configured.', 'texty' ) );
        }

        $body = [
            'api_token' => $creds['api_token'],
            'sid'       => $creds['sid'],
            // API expects digits only (e.g. 88019XXXXXXXX), no leading "+".
            'msisdn'    => preg_replace( '/\D/', '', (string) $to ),
            'sms'       => $message,
            'csms_id'   => $this->generate_csms_id(),
        ];

        // SSL Wireless whitelists IPv4 addresses: force IPv4 so a dual-stack
        // server can't leave over IPv6 and be rejected despite a whitelisted
        // IPv4 address.
        $response = texty()->egress_ip()->with_ipv4(
            self::ENDPOINT,
            static function () use ( $body ) {
                return wp_remote_post(
                    self::ENDPOINT,
                    [
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Accept'       => 'application/json',
                        ],
                        'body'    => wp_json_encode( $body ),
                        'timeout' => 30,
                    ]
                );
            }
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $raw    = wp_remote_retrieve_body( $response );
        $result = json_decode( $raw );
        $info   = ( $result && ! empty( $result->smsinfo ) && is_array( $result->smsinfo ) ) ? $result->smsinfo[0] : null;

        // Request-level status AND the per-recipient status must both be SUCCESS.
        $ok = $result
            && isset( $result->status ) && 'SUCCESS' === $result->status
            && ( null === $info || ! isset( $info->sms_status ) || 'SUCCESS' === $info->sms_status );

        if ( ! $ok ) {
            $code = isset( $result->status_code ) ? 'sslwireless_' . $result->status_code : 'sslwireless_failed';

            // 4003 "IP Blacklisted": record which server was refused (behind a
            // load balancer only some nodes may be whitelisted) and name the
            // exact IP to whitelist.
            if ( isset( $result->status_code ) && self::IP_BLACKLISTED === (int) $result->status_code ) {
                $ip = texty()->egress_ip()->report( EgressIp::RESULT_BLOCKED, 'sslwireless' );

                if ( '' !== $ip ) {
                    $error = sprintf(
                        /* translators: %s: this server's outgoing IPv4 address */
                        __( 'IP Blacklisted: ask SSL Wireless to whitelist %s for your iSMS Plus account.', 'texty' ),
                        $ip
                    );

                    return new WP_Error( $code, $error, $result );
                }
            }

            if ( ! empty( $result->error_message ) ) {
                $error = (string) $result->error_message;
            } elseif ( $info && ! empty( $info->status_message ) ) {
                $error = (string) $info->status_message;
            } else {
                $error = __( 'SSL Wireless rejected the request.', 'texty' );
            }

            // Not a JSON reply (5xx, HTML error page, WAF block): keep the HTTP
            // status and a trimmed body so the log row says what happened.
            if ( ! is_object( $result ) ) {
                $http_code = (int) wp_remote_retrieve_response_code( $response );

                if ( ! $http_code || 200 !== $http_code ) {
                    $code = 'sslwireless_http_' . ( $http_code ? $http_code : 'error' );
                }

                $result = [
                    'http_code' => $http_code,
                    'body'      => substr( wp_strip_all_tags( (string) $raw ), 0, 500 ),
                ];
            }

            return new WP_Error( $code, $error, $result );
        }

        texty()->egress_ip()->report( EgressIp::RESULT_ALLOWED, 'sslwireless' );

        return [
            'success'      => true,
            'reference_id' => ( $info && isset( $info->reference_id ) ) ? (string) $info->reference_id : $body['csms_id'],
        ];
    }

    /**
     * Validate a REST API request
     *
     * SSL Wireless exposes no balance/auth endpoint, so only presence of the
     * credentials can be checked here; a bad token surfaces on the first send.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|mixed
     */
    public function validate( $request ) {
        $creds = $request->get_param( 'sslwireless' );

        if ( ! is_array( $creds ) || empty( $creds['api_token'] ) || empty( $creds['sid'] ) ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'SSL Wireless API token and SID are required.', 'texty' )
            );
        }

        return [
            'api_token' => sanitize_text_field( $creds['api_token'] ),
            'sid'       => sanitize_text_field( $creds['sid'] ),
        ];
    }

    /**
     * Generate a unique alphanumeric client SMS reference (max 20 chars)
     *
     * @return string
     */
    protected function generate_csms_id() {
        return substr( 'T' . time() . wp_generate_password( self::CSMS_ID_LENGTH, false, false ), 0, self::CSMS_ID_LENGTH );
    }
}
