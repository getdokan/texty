<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Sinch Class
 *
 * Uses the standalone SMS REST API with service plan (Bearer token) auth,
 * which works in every hosting region.
 *
 * @see https://developers.sinch.com/docs/sms/api-reference/sms/batches/sendsms
 *
 * @since 2.1.0
 */
class Sinch extends AbstractGateway {

    /**
     * Base URL pattern, `{region}` is one of `regions()`
     */
    const BASE_URL = 'https://{region}.sms.api.sinch.com';

    /**
     * Region used when none (or an unknown one) is saved
     */
    const DEFAULT_REGION = 'us';

    /**
     * Longest alphanumeric sender ID accepted
     */
    const MAX_ALPHA_SENDER = 11;

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'sinch';

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
            'docs_url'          => 'https://developers.sinch.com/docs/sms/api-reference',
            'signup_url'        => 'https://dashboard.sinch.com/signup',
            'regions'           => array_keys( $this->regions() ),
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
        return __( 'Sinch', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            /* translators: 1: URL to the Sinch SMS REST API settings, 2: URL to the setup guide */
            __(
                'Send SMS with the Sinch SMS API. The standalone SMS API is available to existing Sinch SMS customers; new Sinch accounts may only get the Conversation API. Follow <a href="%1$s" target="_blank">this link</a> to get the Service Plan ID and API Token. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://dashboard.sinch.com/sms/api/rest',
            'https://github.com/getdokan/texty/wiki/Sinch'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/sinch-logo.png';
    }

    /**
     * Get the settings
     *
     * @return array
     */
    public function get_settings() {
        $creds  = $this->creds();
        $region = $this->cred( $creds, 'region' );

        return [
            'service_plan_id' => [
                'name'  => __( 'Service Plan ID', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'service_plan_id' ),
                'help'  => __( 'Sinch Customer Dashboard → SMS → APIs → REST configuration', 'texty' ),
            ],
            'api_token'       => [
                'name'  => __( 'API Token', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'api_token' ),
                'help'  => __( 'The API token shown next to your Service Plan ID', 'texty' ),
            ],
            'region'          => [
                'name'    => __( 'Region', 'texty' ),
                'type'    => 'select',
                'options' => $this->regions(),
                'value'   => '' !== $region ? $region : self::DEFAULT_REGION,
                'help'    => __( 'Must match the region where your service plan was created.', 'texty' ),
            ],
            'from'            => [
                'name'         => __( 'From (Sender)', 'texty' ),
                'type'         => 'text',
                'value'        => $this->cred( $creds, 'from' ),
                'help'         => __( 'Phone number (E.164), short code, or alphanumeric sender. Can be left empty if your account has an Automatic Default Originator configured.', 'texty' ),
                'required'     => false,
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
        $options = SendOptions::normalize( $options );
        $creds   = $this->creds();
        $plan_id = $this->cred( $creds, 'service_plan_id' );
        $token   = $this->cred( $creds, 'api_token' );

        if ( '' === $plan_id || '' === $token ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'Sinch Service Plan ID and API Token are required.', 'texty' )
            );
        }

        $from = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'from' );
        $from = $this->normalize_sender( $from );

        // `unicode`, `template_id` and `variables` have no Sinch equivalent:
        // encoding is chosen by Sinch and Texty replaces tokens itself.
        $body = [];

        if ( '' !== $from ) {
            $body['from'] = $from;
        }

        $body['to']   = [ $this->normalize_number( $to ) ];
        $body['body'] = (string) $message;
        $body['type'] = 'mt_text';

        $response = $this->http()->post(
            $this->endpoint( $plan_id, $creds ),
            [
                'headers' => $this->auth_headers( $token ),
                'json'    => $body,
            ]
        );

        $failure = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 201 === $response->status() ) {
            return Result::success( $response->get( 'id' ), $response );
        }

        list( $code, $error ) = $this->error_details( $response );

        return Result::failure( $code, $error, $response );
    }

    /**
     * Validate a REST API request
     *
     * Lists one batch, which checks the token, service plan and region
     * together without sending anything.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $creds   = $this->request_creds( $request );
        $plan_id = sanitize_text_field( $this->cred( $creds, 'service_plan_id' ) );
        $token   = sanitize_text_field( $this->cred( $creds, 'api_token' ) );
        $region  = strtolower( sanitize_text_field( $this->cred( $creds, 'region' ) ) );
        $from    = $this->normalize_sender( sanitize_text_field( $this->cred( $creds, 'from' ) ) );

        if ( '' === $plan_id || '' === $token ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'Sinch Service Plan ID and API Token are required.', 'texty' )
            );
        }

        if ( '' === $region ) {
            $region = self::DEFAULT_REGION;
        }

        if ( ! in_array( $region, array_keys( $this->regions() ), true ) ) {
            return new WP_Error(
                'texty_sinch_invalid_region',
                sprintf(
                    /* translators: %s: comma-separated list of region codes, e.g. "us, eu, au" */
                    __( 'Invalid Sinch region. Use one of: %s', 'texty' ),
                    implode( ', ', array_keys( $this->regions() ) )
                )
            );
        }

        if ( '' !== $from && ! $this->is_numeric_sender( $from ) && strlen( $from ) > self::MAX_ALPHA_SENDER ) {
            return new WP_Error(
                'texty_sinch_invalid_sender',
                sprintf(
                    /* translators: %d: maximum number of characters */
                    __( 'An alphanumeric sender can be at most %d characters long.', 'texty' ),
                    self::MAX_ALPHA_SENDER
                )
            );
        }

        $sanitized = [
            'service_plan_id' => $plan_id,
            'api_token'       => $token,
            'region'          => $region,
            'from'            => $from,
        ];

        $response = $this->http()->get(
            $this->endpoint( $plan_id, $sanitized ),
            [
                'headers' => $this->auth_headers( $token ),
                'query'   => [ 'page_size' => 1 ],
            ]
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() ) {
            list( $code, $error ) = $this->error_details( $response );

            return new WP_Error( $code, $error, $response->payload() );
        }

        return $sanitized;
    }

    /**
     * Supported hosting regions
     *
     * @return array Region code => label
     */
    private function regions() {
        return [
            'us' => __( 'United States', 'texty' ),
            'eu' => __( 'Europe', 'texty' ),
            'au' => __( 'Australia', 'texty' ),
            'br' => __( 'Brazil', 'texty' ),
            'ca' => __( 'Canada', 'texty' ),
        ];
    }

    /**
     * Batches endpoint for a service plan in the configured region
     *
     * @param string $plan_id
     * @param array  $creds
     *
     * @return string
     */
    private function endpoint( $plan_id, array $creds ) {
        $region = strtolower( $this->cred( $creds, 'region' ) );

        if ( ! in_array( $region, array_keys( $this->regions() ), true ) ) {
            $region = self::DEFAULT_REGION;
        }

        $base = $this->base_url( str_replace( '{region}', $region, self::BASE_URL ), $creds );

        return $base . '/xms/v1/' . rawurlencode( $plan_id ) . '/batches';
    }

    /**
     * Build the Bearer auth header
     *
     * @param string $token
     *
     * @return array
     */
    private function auth_headers( $token ) {
        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * Normalize a recipient to E.164 with a leading `+`
     *
     * @param string $to
     *
     * @return string
     */
    private function normalize_number( $to ) {
        return '+' . ltrim( (string) preg_replace( '/[^\d+]/', '', (string) $to ), '+' );
    }

    /**
     * Normalize a sender: numbers lose spaces and punctuation (keeping `+`),
     * alphanumeric senders are kept as-is
     *
     * @param string $from
     *
     * @return string
     */
    private function normalize_sender( $from ) {
        $from = trim( (string) $from );

        if ( '' === $from || ! $this->is_numeric_sender( $from ) ) {
            return $from;
        }

        return (string) preg_replace( '/[^\d+]/', '', $from );
    }

    /**
     * Whether a sender is a phone number or short code rather than alphanumeric
     *
     * @param string $from
     *
     * @return bool
     */
    private function is_numeric_sender( $from ) {
        return 1 === preg_match( '/^\+?[\d\s().-]+$/', $from );
    }

    /**
     * Error code and message for a non-success response
     *
     * 400/403 carry `{ code, text }`; 401, 404, 429 and 5xx may have an empty
     * or non-JSON body, so those fall back to fixed messages.
     *
     * @param Response $response
     *
     * @return array [ code, message ]
     */
    private function error_details( Response $response ) {
        $code   = $response->get( 'code' );
        $text   = $response->get( 'text' );
        $status = $response->status();

        $code = is_scalar( $code ) && '' !== (string) $code ? (string) $code : '';
        $text = is_string( $text ) && '' !== $text ? $text : '';

        if ( 401 === $status ) {
            return [
                '' !== $code ? $code : 'sinch_unauthorized',
                '' !== $text ? $text : __( 'Invalid Sinch API token or service plan ID. Check that the region matches the one your service plan was created in.', 'texty' ),
            ];
        }

        if ( 404 === $status ) {
            return [
                '' !== $code ? $code : 'sinch_not_found',
                '' !== $text ? $text : __( 'Sinch service plan not found. Check the Service Plan ID and the region.', 'texty' ),
            ];
        }

        if ( 429 === $status ) {
            return [
                '' !== $code ? $code : 'sinch_rate_limited',
                '' !== $text ? $text : __( 'Sinch rate limit reached. Try again later.', 'texty' ),
            ];
        }

        if ( '' !== $code || '' !== $text ) {
            return [
                '' !== $code ? $code : 'sinch_error',
                $text,
            ];
        }

        return [
            'sinch_error',
            sprintf(
                /* translators: %d: HTTP status code */
                __( 'Sinch returned an unexpected response (HTTP %d).', 'texty' ),
                $status
            ),
        ];
    }
}
