<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Bandwidth Class
 *
 * Authenticates with OAuth 2.0 client credentials only: Bandwidth removes
 * Basic (API user) auth on 2026-12-02. The access token is cached in a
 * transient and refreshed once when the messaging API answers 401.
 *
 * @see https://dev.bandwidth.com/spec/messaging.yml
 * @see https://dev.bandwidth.com/docs/account/credentials/management
 *
 * @since 2.1.0
 */
class Bandwidth extends AbstractGateway {

    /**
     * Messaging API base URL
     */
    const MESSAGING_BASE = 'https://messaging.bandwidth.com/api/v2';

    /**
     * OAuth API base URL
     */
    const OAUTH_BASE = 'https://api.bandwidth.com/api/v1';

    /**
     * Transient prefix for cached access tokens
     */
    const TOKEN_TRANSIENT_PREFIX = 'texty_bandwidth_token_';

    /**
     * Seconds to expire a cached token ahead of the provider's expiry
     */
    const TOKEN_EXPIRY_MARGIN = 60;

    /**
     * Token lifetime assumed when the response has no `expires_in`
     */
    const DEFAULT_TOKEN_LIFETIME = 3600;

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'bandwidth';

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
            'docs_url'          => 'https://dev.bandwidth.com/apis/messaging/',
            'signup_url'        => 'https://www.bandwidth.com/free-trial/',
            'regions'           => [ 'US', 'CA' ],
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
        return __( 'Bandwidth', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: 1: URL to the Bandwidth App, 2: URL to the Texty setup guide
            __(
                'Send SMS with Bandwidth (US and Canada). Create OAuth API Credentials and find your 7-digit Account ID in <a href="%1$s" target="_blank">the Bandwidth App</a>. US traffic needs a registered 10DLC campaign or a verified toll-free number; carrier rejections are only reported by webhook, so Texty may log them as sent. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://app.bandwidth.com/',
            'https://github.com/getdokan/texty/wiki/Bandwidth'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/bandwidth-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds = $this->creds();

        return [
            'account_id'     => [
                'name'  => __( 'Account ID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['account_id'] ) ? $creds['account_id'] : '',
                'help'  => __( 'Your 7-digit Bandwidth Account ID, shown in the Bandwidth App.', 'texty' ),
            ],
            'client_id'      => [
                'name'  => __( 'Client ID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['client_id'] ) ? $creds['client_id'] : '',
                'help'  => __( 'From Account > API Credentials in the Bandwidth App.', 'texty' ),
            ],
            'client_secret'  => [
                'name'  => __( 'Client Secret', 'texty' ),
                'type'  => 'password',
                'value' => isset( $creds['client_secret'] ) ? $creds['client_secret'] : '',
                'help'  => __( 'Shown only once when the credential is created.', 'texty' ),
            ],
            'application_id' => [
                'name'  => __( 'Application ID', 'texty' ),
                'type'  => 'text',
                'value' => isset( $creds['application_id'] ) ? $creds['application_id'] : '',
                'help'  => __( 'The Messaging Application your From number is linked to.', 'texty' ),
            ],
            'from'           => [
                'name'         => __( 'From number / Sender ID', 'texty' ),
                'type'         => 'text',
                'value'        => isset( $creds['from'] ) ? $creds['from'] : '',
                'help'         => __( 'Your Bandwidth number in E.164 (+15551113333) or an alphanumeric sender ID (max 11 chars).', 'texty' ),
                'alphanumeric' => true,
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
        $options        = SendOptions::normalize( $options );
        $creds          = $this->creds();
        $account_id     = $this->cred( $creds, 'account_id' );
        $client_id      = $this->cred( $creds, 'client_id' );
        $client_secret  = $this->cred( $creds, 'client_secret' );
        $application_id = $this->cred( $creds, 'application_id' );
        $from           = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );

        if ( '' === $account_id || '' === $client_id || '' === $client_secret || '' === $application_id || '' === $from ) {
            return Result::failure( 'texty_missing_credentials', $this->missing_credentials_message() );
        }

        $url  = $this->base_url( self::MESSAGING_BASE, $creds ) . '/users/' . rawurlencode( $account_id ) . '/messages';
        $body = [
            'applicationId' => $application_id,
            'to'            => [ $this->normalize_number( $to ) ],
            'from'          => $from,
            'text'          => $message,
            'tag'           => 'texty',
        ];

        $token     = $this->cached_token( $client_id, $client_secret );
        $was_cache = '' !== $token;

        if ( ! $was_cache ) {
            $token = $this->fresh_token( $client_id, $client_secret, $creds );

            if ( $token instanceof Result ) {
                return $token;
            }
        }

        $response = $this->post_message( $url, $token, $body );
        $failure  = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        // A 401 means the message was not accepted, so a single retry with a
        // fresh token can't cause a duplicate send. Only a cached token can
        // be stale; a token fetched just now is not retried.
        if ( 401 === $response->status() && $was_cache ) {
            $this->forget_token( $client_id, $client_secret );

            $token = $this->fresh_token( $client_id, $client_secret, $creds );

            if ( $token instanceof Result ) {
                return $token;
            }

            $response = $this->post_message( $url, $token, $body );
            $failure  = $this->transport_failure( $response );

            if ( $failure ) {
                return $failure;
            }
        }

        if ( 202 === $response->status() ) {
            $id = $response->get( 'id' );

            return Result::success( is_scalar( $id ) ? (string) $id : '', $response );
        }

        $error = $this->api_error( $response );

        return Result::failure( $error['code'], $error['message'], $response );
    }

    /**
     * Validate a REST API request
     *
     * Checks the formats, fetches a fresh token (never the cached one) and
     * lists the account's media, which needs messaging access but has no
     * side effects. The application ID and From number can't be checked
     * through the Messaging API, so they are left to the first send.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $submitted = $this->request_creds( $request );
        $creds     = [];

        foreach ( [ 'account_id', 'client_id', 'client_secret', 'application_id', 'from' ] as $field ) {
            $creds[ $field ] = sanitize_text_field( $this->cred( $submitted, $field ) );
        }

        foreach ( $creds as $value ) {
            if ( '' === $value ) {
                return new WP_Error( 'texty_missing_credentials', $this->missing_credentials_message() );
            }
        }

        if ( 0 === strpos( strtolower( $creds['account_id'] ), 'u-' ) ) {
            return new WP_Error(
                'texty_invalid_account_id',
                __( 'This looks like a legacy Bandwidth account ID (u-…). OAuth needs the 7-digit Account ID shown in the Bandwidth App.', 'texty' )
            );
        }

        if ( ! preg_match( '/^\d{7}$/', $creds['account_id'] ) ) {
            return new WP_Error(
                'texty_invalid_account_id',
                __( 'The Bandwidth Account ID must be the 7-digit number shown in the Bandwidth App.', 'texty' )
            );
        }

        if ( ! $this->is_valid_sender( $creds['from'] ) ) {
            return new WP_Error(
                'texty_invalid_sender',
                __( 'The From value must be an E.164 number such as +15551113333, or an alphanumeric sender ID of up to 11 characters.', 'texty' )
            );
        }

        $token_response = $this->request_token( $creds['client_id'], $creds['client_secret'], $creds );

        if ( $token_response->is_transport_error() ) {
            return $token_response->get_error();
        }

        $token = $this->token_from( $token_response );

        if ( '' === $token ) {
            $error = $this->token_error( $token_response );

            return new WP_Error( $error['code'], $error['message'], $token_response->payload() );
        }

        $response = $this->http()->get(
            $this->base_url( self::MESSAGING_BASE, $creds ) . '/users/' . rawurlencode( $creds['account_id'] ) . '/media',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() ) {
            $error = $this->api_error( $response );

            return new WP_Error( $error['code'], $error['message'], $response->payload() );
        }

        $this->remember_token( $creds['client_id'], $creds['client_secret'], $token, $token_response );

        return $creds;
    }

    /**
     * POST a message with a Bearer token
     *
     * @param string $url
     * @param string $token
     * @param array  $body
     *
     * @return Response
     */
    private function post_message( $url, $token, array $body ) {
        return $this->http()->post(
            $url,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
                'json'    => $body,
            ]
        );
    }

    /**
     * Request an access token with the client credentials grant
     *
     * @param string $client_id
     * @param string $client_secret
     * @param array  $creds Credentials in use (for the base URL filter)
     *
     * @return Response
     */
    private function request_token( $client_id, $client_secret, array $creds ) {
        return $this->http()->post(
            $this->base_url( self::OAUTH_BASE, $creds ) . '/oauth2/token',
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                ],
                'form'    => [
                    'grant_type' => 'client_credentials',
                ],
            ]
        );
    }

    /**
     * Fetch a new token and cache it
     *
     * @param string $client_id
     * @param string $client_secret
     * @param array  $creds Credentials in use (for the base URL filter)
     *
     * @return string|Result Token, or the failure to return from the send
     */
    private function fresh_token( $client_id, $client_secret, array $creds ) {
        $response = $this->request_token( $client_id, $client_secret, $creds );
        $failure  = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        $token = $this->token_from( $response );

        if ( '' === $token ) {
            $error = $this->token_error( $response );

            // The token response is never a success here, so its payload
            // holds no access token.
            return Result::failure( $error['code'], $error['message'], $response );
        }

        $this->remember_token( $client_id, $client_secret, $token, $response );

        return $token;
    }

    /**
     * Read the access token from a token response
     *
     * @param Response $response
     *
     * @return string Empty when the response is not a usable token
     */
    private function token_from( Response $response ) {
        if ( 200 !== $response->status() ) {
            return '';
        }

        $token = $response->get( 'access_token' );

        return is_string( $token ) ? trim( $token ) : '';
    }

    /**
     * Error code and message from a failed token response
     *
     * @param Response $response
     *
     * @return array { code: string, message: string }
     */
    private function token_error( Response $response ) {
        $code    = $response->get( 'error' );
        $message = $response->get( 'error_description' );

        return [
            'code'    => is_string( $code ) && '' !== $code ? $code : 'bandwidth_token_http_' . $response->status(),
            'message' => is_string( $message ) && '' !== $message
                ? $message
                : __( 'Bandwidth did not issue an access token. Check the Client ID and Client Secret.', 'texty' ),
        ];
    }

    /**
     * Get a cached token
     *
     * @param string $client_id
     * @param string $client_secret
     *
     * @return string Empty when nothing is cached
     */
    private function cached_token( $client_id, $client_secret ) {
        $token = get_transient( $this->token_cache_key( $client_id, $client_secret ) );

        return is_string( $token ) ? $token : '';
    }

    /**
     * Cache a token until shortly before it expires
     *
     * Only the token string is stored.
     *
     * @param string   $client_id
     * @param string   $client_secret
     * @param string   $token
     * @param Response $response Token response, for `expires_in`
     *
     * @return void
     */
    private function remember_token( $client_id, $client_secret, $token, Response $response ) {
        $expires_in = $response->get( 'expires_in' );
        $expires_in = is_numeric( $expires_in ) ? (int) $expires_in : self::DEFAULT_TOKEN_LIFETIME;
        $ttl        = $expires_in - self::TOKEN_EXPIRY_MARGIN;

        if ( $ttl > 0 ) {
            set_transient( $this->token_cache_key( $client_id, $client_secret ), $token, $ttl );
        }
    }

    /**
     * Drop a cached token
     *
     * @param string $client_id
     * @param string $client_secret
     *
     * @return void
     */
    private function forget_token( $client_id, $client_secret ) {
        delete_transient( $this->token_cache_key( $client_id, $client_secret ) );
    }

    /**
     * Transient key for a credential pair
     *
     * Hashing both values means a rotated secret misses the cache, and the
     * key never contains the secret in clear text.
     *
     * @param string $client_id
     * @param string $client_secret
     *
     * @return string
     */
    private function token_cache_key( $client_id, $client_secret ) {
        return self::TOKEN_TRANSIENT_PREFIX . md5( $client_id . '|' . $client_secret );
    }

    /**
     * Error code and message from a Messaging API error response
     *
     * Bodies are `{ type, description, fieldErrors? }`. A rejected Bearer
     * token returns 401 with an empty body and the reason in the
     * `www-authenticate` header.
     *
     * @param Response $response
     *
     * @return array { code: string, message: string }
     */
    private function api_error( Response $response ) {
        $code    = $response->get( 'type' );
        $message = '';

        $field_name  = $response->get( 'fieldErrors.0.fieldName' );
        $field_error = $response->get( 'fieldErrors.0.description' );

        if ( is_string( $field_name ) && '' !== $field_name && is_string( $field_error ) && '' !== $field_error ) {
            $message = $field_name . ': ' . $field_error;
        } elseif ( is_string( $response->get( 'description' ) ) ) {
            $message = $response->get( 'description' );
        }

        $authenticate = $response->header( 'www-authenticate' );

        if ( ( ! is_string( $code ) || '' === $code ) && '' !== $authenticate && preg_match( '/\berror="([^"]*)"/', $authenticate, $matches ) ) {
            $code = $matches[1];
        }

        if ( '' === $message && '' !== $authenticate && preg_match( '/\berror_description="([^"]*)"/', $authenticate, $matches ) ) {
            $message = $matches[1];
        }

        return [
            'code'    => is_string( $code ) && '' !== $code ? $code : 'bandwidth_http_' . $response->status(),
            'message' => '' !== $message ? $message : __( 'Bandwidth could not accept the message.', 'texty' ),
        ];
    }

    /**
     * Normalize a phone number to E.164 with a leading `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_number( $to ) {
        return '+' . preg_replace( '/\D/', '', (string) $to );
    }

    /**
     * Whether a sender is an E.164 number or an alphanumeric sender ID
     *
     * @param string $from
     *
     * @return bool
     */
    private function is_valid_sender( $from ) {
        if ( preg_match( '/^\+[1-9]\d{6,14}$/', $from ) ) {
            return true;
        }

        return 1 === preg_match( '/^[A-Za-z0-9 \-+_&]{1,11}$/', $from ) && 1 === preg_match( '/[A-Za-z]/', $from );
    }

    /**
     * Message for missing credentials
     *
     * @return string
     */
    private function missing_credentials_message() {
        return __( 'Bandwidth Account ID, Client ID, Client Secret, Application ID and From number are required.', 'texty' );
    }
}
