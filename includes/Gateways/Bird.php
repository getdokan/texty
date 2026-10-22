<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Bird Class
 *
 * Sends through Bird API v1 (`https://{region}.platform.bird.com/v1`), not
 * the legacy workspace/channel API on `api.bird.com`.
 *
 * @since 2.1.0
 *
 * @see https://bird.com/docs/api/reference/create-sms-message
 */
class Bird extends AbstractGateway {

    /**
     * Base URL template, `{region}` is the data region (us1, eu1)
     */
    const API_BASE = 'https://{region}.platform.bird.com';

    /**
     * Messages path, used for both send (POST) and validate (GET)
     */
    const MESSAGES_PATH = '/v1/sms/messages';

    /**
     * Region used when neither the setting nor the key names one
     */
    const DEFAULT_REGION = 'us1';

    /**
     * Category used when none is saved
     */
    const DEFAULT_CATEGORY = 'transactional';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'bird';

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
            'docs_url'          => 'https://bird.com/docs/guides/sms/sending-sms',
            'signup_url'        => 'https://bird.com/dashboard/w/api-keys',
            'regions'           => [],
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
     * @since 2.1.0
     *
     * @return string
     */
    public function name() {
        return __( 'Bird', 'texty' );
    }

    /**
     * Get the description
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: 1: URL to the Bird API keys page, 2: URL to the Texty setup guide for Bird
            __(
                'Send SMS with Bird. Follow <a href="%1$s" target="_blank">this link</a> to create an API key in your Bird workspace. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://bird.com/dashboard/w/api-keys',
            'https://github.com/getdokan/texty/wiki/Bird'
        );
    }

    /**
     * Get the logo
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/bird.svg';
    }

    /**
     * Get the settings
     *
     * @since 2.1.0
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
                'help'  => __( 'Workspace API key from Bird dashboard > Platform tools > API keys. It needs the sms scope with write access.', 'texty' ),
            ],
            'region'   => [
                'name'    => __( 'Region', 'texty' ),
                'type'    => 'select',
                'options' => [
                    'us1' => __( 'United States (us1)', 'texty' ),
                    'eu1' => __( 'Europe (eu1)', 'texty' ),
                ],
                'value'   => '' !== $this->cred( $creds, 'region' ) ? $this->cred( $creds, 'region' ) : self::DEFAULT_REGION,
                'help'    => __( 'Data region of your Bird workspace. It must match the region in your API key (bk_us1_... or bk_eu1_...).', 'texty' ),
            ],
            'from'     => [
                'name'         => __( 'Sender', 'texty' ),
                'type'         => 'text',
                'alphanumeric' => true,
                'value'        => $this->cred( $creds, 'from' ),
                'help'         => __( 'A sender your Bird workspace holds: an owned number in E.164 format (+15557654321), a short code, or a registered alphanumeric sender ID (3-11 characters).', 'texty' ),
            ],
            'category' => [
                'name'    => __( 'Message Category', 'texty' ),
                'type'    => 'select',
                'options' => [
                    'transactional'  => __( 'Transactional', 'texty' ),
                    'marketing'      => __( 'Marketing', 'texty' ),
                    'authentication' => __( 'Authentication', 'texty' ),
                    'service'        => __( 'Service', 'texty' ),
                ],
                'value'   => '' !== $this->cred( $creds, 'category' ) ? $this->cred( $creds, 'category' ) : self::DEFAULT_CATEGORY,
                'help'    => __( 'Why you are sending. Bird requires a category on every message.', 'texty' ),
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
        $api_key = $this->cred( $creds, 'api_key' );
        $from    = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );

        if ( '' === $api_key || '' === $from ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Bird API Key and Sender are required.', 'texty' )
            );
        }

        $category = $this->cred( $creds, 'category' );

        if ( ! in_array( $category, $this->categories(), true ) ) {
            $category = self::DEFAULT_CATEGORY;
        }

        // Bird detects GSM-7 / UCS-2 by itself, so `unicode` needs no field.
        // Templates are not supported yet (metadata `template: false`).
        $response = $this->http()->post(
            $this->messages_url( $api_key, $creds ),
            [
                'headers' => $this->auth_headers( $api_key ),
                'json'    => [
                    'to'       => $this->normalize_phone( $to ),
                    'from'     => $from,
                    'text'     => $message,
                    'category' => $category,
                ],
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        // `status` and `last_error` in the 202 body describe async delivery
        // state and are deliberately ignored.
        $id = $response->get( 'id' );

        if ( 202 === $response->status() && is_string( $id ) && '' !== $id ) {
            return Result::success( $id, $response );
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
     * Lists one message, which needs only the sms scope a send already
     * needs and has no side effects.
     *
     * @since 2.1.0
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds    = $this->request_creds( $request );
        $api_key  = sanitize_text_field( $this->cred( $creds, 'api_key' ) );
        $region   = strtolower( sanitize_text_field( $this->cred( $creds, 'region' ) ) );
        $from     = sanitize_text_field( $this->cred( $creds, 'from' ) );
        $category = strtolower( sanitize_text_field( $this->cred( $creds, 'category' ) ) );

        if ( '' === $api_key || '' === $from ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Bird API Key and Sender are required.', 'texty' )
            );
        }

        $key_region = $this->key_region( $api_key );

        if ( '' === $key_region ) {
            return new WP_Error(
                'bird_invalid_key_format',
                __( 'This does not look like a Bird workspace API key. It should start with bk_us1_ or bk_eu1_.', 'texty' )
            );
        }

        if ( '' === $region ) {
            $region = in_array( $key_region, $this->regions(), true ) ? $key_region : self::DEFAULT_REGION;
        }

        if ( ! in_array( $region, $this->regions(), true ) ) {
            return new WP_Error(
                'bird_invalid_region',
                __( 'Bird Region must be us1 or eu1.', 'texty' )
            );
        }

        if ( $key_region !== $region ) {
            return new WP_Error(
                'bird_region_mismatch',
                sprintf(
                    // translators: 1: region in the API key, 2: region selected in the settings
                    __( 'This API key belongs to the %1$s region, but the Region setting is %2$s. Set the Region to match the key.', 'texty' ),
                    $key_region,
                    $region
                )
            );
        }

        if ( ! $this->is_valid_sender( $from ) ) {
            return new WP_Error(
                'bird_invalid_sender',
                __( 'Bird Sender must be a phone number, a short code, or an alphanumeric sender ID of 3-11 characters.', 'texty' )
            );
        }

        if ( '' === $category ) {
            $category = self::DEFAULT_CATEGORY;
        }

        if ( ! in_array( $category, $this->categories(), true ) ) {
            return new WP_Error(
                'bird_invalid_category',
                __( 'Bird Message Category must be transactional, marketing, authentication or service.', 'texty' )
            );
        }

        $validated = [
            'api_key'  => $api_key,
            'region'   => $region,
            'from'     => $from,
            'category' => $category,
        ];

        $response = $this->http()->get(
            $this->messages_url( $api_key, $validated ),
            [
                'headers' => $this->auth_headers( $api_key ),
                'query'   => [ 'limit' => 1 ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() || ! is_array( $response->get( 'data' ) ) ) {
            return new WP_Error(
                $this->error_code( $response ),
                $this->error_message( $response ),
                $response->payload()
            );
        }

        return $validated;
    }

    /**
     * Allowed data regions
     *
     * @return string[]
     */
    private function regions() {
        return [ 'us1', 'eu1' ];
    }

    /**
     * Allowed message categories
     *
     * @return string[]
     */
    private function categories() {
        return [ 'transactional', 'marketing', 'authentication', 'service' ];
    }

    /**
     * Read the region segment from a workspace key (`bk_{region}_...`)
     *
     * @param string $api_key
     *
     * @return string Region, or '' when the key is not a workspace key
     */
    private function key_region( $api_key ) {
        if ( preg_match( '/^bk_([a-z]{2}\d)_.+$/', $api_key, $matches ) ) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Resolve the region for a request
     *
     * The saved setting wins; otherwise the key prefix; otherwise us1.
     *
     * @param string $api_key
     * @param array  $creds
     *
     * @return string
     */
    private function resolve_region( $api_key, array $creds ) {
        $region = strtolower( $this->cred( $creds, 'region' ) );

        if ( in_array( $region, $this->regions(), true ) ) {
            return $region;
        }

        $key_region = $this->key_region( $api_key );

        return in_array( $key_region, $this->regions(), true ) ? $key_region : self::DEFAULT_REGION;
    }

    /**
     * Messages endpoint for the region in use
     *
     * @param string $api_key
     * @param array  $creds
     *
     * @return string
     */
    private function messages_url( $api_key, array $creds ) {
        $base = str_replace( '{region}', $this->resolve_region( $api_key, $creds ), self::API_BASE );

        return $this->base_url( $base, $creds ) . self::MESSAGES_PATH;
    }

    /**
     * Build the Bearer auth header
     *
     * @param string $api_key
     *
     * @return array
     */
    private function auth_headers( $api_key ) {
        return [
            'Authorization' => 'Bearer ' . $api_key,
        ];
    }

    /**
     * Normalize a phone number to E.164 with a leading `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_phone( $to ) {
        return '+' . ltrim( (string) preg_replace( '/[^\d+]/', '', (string) $to ), '+' );
    }

    /**
     * Check the sender format: a number / short code, or an alphanumeric ID
     *
     * @param string $from
     *
     * @return bool
     */
    private function is_valid_sender( $from ) {
        if ( preg_match( '/^\+?\d{3,15}$/', $from ) ) {
            return true;
        }

        return 1 === preg_match( '/^[A-Za-z0-9 _.\-]{3,11}$/', $from ) && 1 === preg_match( '/[A-Za-z]/', $from );
    }

    /**
     * Pull the error code from a Bird error body
     *
     * Reads the v1 `error.code` envelope, then a top-level `code`, then
     * falls back to `bird_http_<status>`.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        foreach ( [ 'error.code', 'code' ] as $path ) {
            $code = $response->get( $path );

            if ( is_scalar( $code ) && '' !== (string) $code ) {
                return (string) $code;
            }
        }

        return 'bird_http_' . $response->status();
    }

    /**
     * Build a readable error message from a Bird error body
     *
     * `error.message`, plus `error.name` (e.g. SMSInvalidRecipient) and any
     * `error.details[*]` as `param: message`.
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $message = $response->get( 'error.message' );

        if ( ! is_string( $message ) || '' === $message ) {
            $message = $response->get( 'message' );
        }

        $message = is_string( $message ) ? $message : '';
        $name    = $response->get( 'error.name' );

        if ( is_string( $name ) && '' !== $name ) {
            $message = '' === $message ? $name : $message . ' (' . $name . ')';
        }

        $details = $response->get( 'error.details' );
        $parts   = [];

        if ( is_array( $details ) ) {
            foreach ( $details as $detail ) {
                if ( ! is_array( $detail ) || ! isset( $detail['message'] ) || ! is_string( $detail['message'] ) ) {
                    continue;
                }

                $parts[] = isset( $detail['param'] ) && is_string( $detail['param'] ) && '' !== $detail['param']
                    ? $detail['param'] . ': ' . $detail['message']
                    : $detail['message'];
            }
        }

        if ( ! empty( $parts ) ) {
            $message = ( '' === $message ? '' : $message . ' ' ) . implode( '; ', $parts );
        }

        return $message;
    }
}
