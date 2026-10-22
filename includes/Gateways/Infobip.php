<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Infobip Class
 *
 * Sends through the SMS API v3 on the account's personal base URL
 * (`https://<id>.api.infobip.com`). The base URL is user-supplied, so it is
 * checked against a host allowlist before the API key is ever sent to it.
 *
 * @since 2.1.0
 *
 * @see https://www.infobip.com/docs/api/channels/sms/outbound-sms/send-message/send-sms-messages
 */
class Infobip extends AbstractGateway {

    /**
     * Send endpoint path, relative to the account base URL
     */
    const SEND_PATH = '/sms/3/messages';

    /**
     * Balance endpoint path, used for side-effect-free validation
     */
    const BALANCE_PATH = '/account/1/balance';

    /**
     * Hosts an Infobip base URL may point at
     */
    const HOST_PATTERN = '/^([a-z0-9-]+\.)?api\.infobip\.com$/i';

    /**
     * Status groups that mean the message was taken by Infobip
     */
    const ACCEPTED_GROUPS = [ 'PENDING', 'ACCEPTED', 'DELIVERED' ];

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'infobip';

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
            'docs_url'          => 'https://www.infobip.com/docs/api/channels/sms/outbound-sms/send-message/send-sms-messages',
            'signup_url'        => 'https://www.infobip.com/signup',
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
        return __( 'Infobip', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the Infobip portal, 2: URL to the Texty setup guide */
            __(
                'Send SMS with Infobip. Follow <a href="%1$s" target="_blank">this link</a> to get your API key and base URL from the Infobip portal. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://portal.infobip.com/',
            'https://github.com/getdokan/texty/wiki/Infobip'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/infobip-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'api_key'  => [
                'name'  => __( 'API Key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_key' ),
                'help'  => __( 'Create an API key in the Infobip portal under Developers > API keys. It needs the sms:message:send scope (add account-management:manage to enable balance checks).', 'texty' ),
            ],
            'base_url' => [
                'name'  => __( 'Base URL', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'base_url' ),
                'help'  => __( 'Your personal base URL shown on the Infobip portal homepage, e.g. xxxxx.api.infobip.com.', 'texty' ),
            ],
            'sender'   => [
                'name'     => __( 'Sender', 'texty' ),
                'type'     => 'text',
                'value'    => $this->cred( $creds, 'sender' ),
                'help'     => __( 'Alphanumeric sender ID or number registered on your Infobip account. Trial accounts can use ServiceSMS and can only send to their own verified number.', 'texty' ),
                'required' => false,
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
        $api_key  = $this->cred( $creds, 'api_key' );
        $raw_base = $this->cred( $creds, 'base_url' );

        if ( '' === $api_key || '' === $raw_base ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Infobip API Key and Base URL are required.', 'texty' )
            );
        }

        $base = $this->normalize_base_url( $raw_base );

        if ( '' === $base ) {
            return Result::failure( 'infobip_invalid_base_url', $this->invalid_base_url_message() );
        }

        $sender = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'sender' );
        $entry  = [];

        if ( '' !== $sender ) {
            $entry['sender'] = $sender;
        }

        $entry['destinations'] = [
            [ 'to' => $this->normalize_phone( $to ) ],
        ];
        $entry['content']      = [ 'text' => (string) $message ];

        $response = $this->http()->post(
            $this->base_url( $base, $creds ) . self::SEND_PATH,
            [
                'headers' => $this->auth_headers( $api_key ),
                'json'    => [
                    'messages' => [ $entry ],
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 200 === $response->status() ) {
            $group = $response->get( 'messages.0.status.groupName' );

            if ( is_string( $group ) && in_array( strtoupper( $group ), self::ACCEPTED_GROUPS, true ) ) {
                return Result::success( $response->get( 'messages.0.messageId' ), $response );
            }

            // Per-message rejection inside a 200 (REJECTED, UNDELIVERABLE, EXPIRED).
            if ( null !== $response->get( 'messages.0.status' ) ) {
                $code = $response->get( 'messages.0.status.name' );
                $text = $response->get( 'messages.0.status.description' );

                return Result::failure(
                    is_scalar( $code ) && '' !== (string) $code ? (string) $code : 'infobip_rejected',
                    is_string( $text ) ? $text : '',
                    $response
                );
            }

            return Result::failure(
                'infobip_unexpected_response',
                __( 'Infobip returned a response without a message status.', 'texty' ),
                $response
            );
        }

        $error = $this->parse_error( $response );

        return Result::failure( $error['code'], $error['message'], $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the base URL against the host allowlist, then calls the
     * side-effect-free balance endpoint. A 403 means the key authenticated
     * but lacks the `account-management:manage` scope (e.g. a send-only key),
     * so it is accepted.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds    = $this->request_creds( $request );
        $api_key  = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $raw_base = sanitize_text_field( $this->cred( $creds, 'base_url' ) );
        $sender   = sanitize_text_field( $this->cred( $creds, 'sender' ) );

        if ( '' === $api_key || '' === $raw_base ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Infobip API Key and Base URL are required.', 'texty' )
            );
        }

        $base = $this->normalize_base_url( $raw_base );

        if ( '' === $base ) {
            return new WP_Error( 'infobip_invalid_base_url', $this->invalid_base_url_message() );
        }

        $clean = [
            'api_key'  => $api_key,
            'base_url' => $base,
            'sender'   => $sender,
        ];

        $response = $this->http()->get(
            $this->base_url( $base, $clean ) . self::BALANCE_PATH,
            [
                'headers' => $this->auth_headers( $api_key ),
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 === $response->status() || 403 === $response->status() ) {
            return $clean;
        }

        if ( 401 === $response->status() ) {
            return new WP_Error(
                'infobip_invalid_credentials',
                __( 'Infobip rejected the API key. Check the key and the base URL.', 'texty' ),
                $response->payload()
            );
        }

        $error = $this->parse_error( $response );

        return new WP_Error( $error['code'], $error['message'], $response->payload() );
    }

    /**
     * Normalize a user-supplied base URL and check it against the allowlist
     *
     * Adds `https://` when no scheme is given, drops any path, query or
     * trailing slash, and rejects non-https schemes, credentials in the URL,
     * non-default ports and hosts outside `*.api.infobip.com`.
     *
     * @since 2.1.0
     *
     * @param string $url
     *
     * @return string `https://<host>` or '' when the URL is not allowed
     */
    protected function normalize_base_url( $url ) {
        $url = trim( (string) $url );

        if ( '' === $url ) {
            return '';
        }

        if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
            $url = 'https://' . $url;
        }

        $parts = wp_parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        if ( 'https' !== strtolower( $parts['scheme'] ) ) {
            return '';
        }

        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return '';
        }

        if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
            return '';
        }

        $host = strtolower( $parts['host'] );

        return $this->is_allowed_host( $host ) ? 'https://' . $host : '';
    }

    /**
     * Whether a host may receive the API key
     *
     * @param string $host Lower-cased host name
     *
     * @return bool
     */
    private function is_allowed_host( $host ) {
        if ( preg_match( self::HOST_PATTERN, $host ) ) {
            return true;
        }

        /**
         * Filter extra hosts an Infobip base URL may point at.
         *
         * By default only `api.infobip.com` and `<subdomain>.api.infobip.com`
         * are accepted, so the API key can't be sent to an arbitrary host.
         *
         * @since 2.1.0
         *
         * @param string[] $hosts Exact host names to allow, e.g. `sms.example-infobip-host.com`
         * @param string   $host  Host being checked
         */
        $extra = apply_filters( 'texty_infobip_allowed_hosts', [], $host );
        $extra = is_array( $extra ) ? array_map( 'strtolower', array_filter( $extra, 'is_string' ) ) : [];

        return in_array( $host, $extra, true );
    }

    /**
     * Error message for a base URL that failed the allowlist
     *
     * @return string
     */
    private function invalid_base_url_message() {
        return __( 'Infobip Base URL must be your https://xxxxx.api.infobip.com address from the Infobip portal.', 'texty' );
    }

    /**
     * Normalize a phone number to international format without `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_phone( $to ) {
        return ltrim( (string) preg_replace( '/[^0-9+]/', '', (string) $to ), '+' );
    }

    /**
     * Build the request headers
     *
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $api_key ) {
        return [
            'Authorization' => 'App ' . $api_key,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Pull the error code and message from an Infobip error body
     *
     * Handles the v3 `ApiError` shape (`errorCode` / `description` /
     * `violations`) and the legacy `requestError.serviceException` shape
     * used by v2 and `/account/1/*` endpoints.
     *
     * @param Response $response
     *
     * @return array { code: string, message: string }
     */
    private function parse_error( Response $response ) {
        $code = $response->get( 'errorCode' );

        if ( is_scalar( $code ) && '' !== (string) $code ) {
            $message   = $response->get( 'description' );
            $message   = is_string( $message ) ? $message : '';
            $property  = $response->get( 'violations.0.property' );
            $violation = $response->get( 'violations.0.violation' );

            if ( is_string( $violation ) && '' !== $violation ) {
                $detail  = is_string( $property ) && '' !== $property ? $property . ': ' . $violation : $violation;
                $message = trim( $message . ' ' . $detail );
            }

            return [
                'code'    => (string) $code,
                'message' => $message,
            ];
        }

        $code = $response->get( 'requestError.serviceException.messageId' );

        if ( is_scalar( $code ) && '' !== (string) $code ) {
            $message = $response->get( 'requestError.serviceException.text' );

            return [
                'code'    => (string) $code,
                'message' => is_string( $message ) ? $message : '',
            ];
        }

        return [
            'code'    => (string) $response->status(),
            'message' => '',
        ];
    }
}
