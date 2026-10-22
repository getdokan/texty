<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use Texty\Http\SigV4;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * AWS End User Messaging SMS Class
 *
 * Talks to the SMS and Voice v2 API (`PinpointSMSVoiceV2`, AWS JSON 1.0)
 * with SigV4-signed requests. No AWS SDK is bundled.
 *
 * @see https://docs.aws.amazon.com/pinpoint/latest/apireference_smsvoicev2/API_SendTextMessage.html
 *
 * @since 2.1.0
 */
class AwsSms extends AbstractGateway {

    /**
     * Default API host, `%s` is the region
     */
    const ENDPOINT = 'https://sms-voice.%s.amazonaws.com';

    /**
     * SigV4 service signing name
     */
    const SERVICE = 'sms-voice';

    /**
     * AWS JSON 1.0 target prefix
     */
    const TARGET_PREFIX = 'PinpointSMSVoiceV2';

    /**
     * AWS JSON 1.0 content type
     */
    const CONTENT_TYPE = 'application/x-amz-json-1.0';

    /**
     * Region used when none is saved
     */
    const DEFAULT_REGION = 'us-east-1';

    /**
     * Registry key
     *
     * @var string
     */
    protected $key = 'aws_sms';

    /**
     * Clock passed to the signer (null = `time()`)
     *
     * @var callable|int|null
     */
    private $clock = null;

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
            'docs_url'          => 'https://docs.aws.amazon.com/sms-voice/latest/userguide/what-is-sms-mms.html',
            'signup_url'        => 'https://aws.amazon.com/end-user-messaging/',
            'regions'           => [],
            'features'          => [
                'unicode'       => true,
                'sender_id'     => true,
                'template'      => false,
                'balance_check' => false,
            ],
            'validation'        => 'live',
            'template_required' => false,
            'sandbox'           => true,
        ];
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function name() {
        return __( 'AWS End User Messaging SMS', 'texty' );
    }

    /**
     * Get the description
     *
     * @return string
     */
    public function description() {
        return sprintf(
            // translators: 1: URL to the AWS IAM console, 2: URL to the setup guide
            __(
                'Send SMS with AWS End User Messaging SMS. Create an IAM user access key in the <a href="%1$s" target="_blank">AWS IAM console</a>. Follow <a href="%2$s" target="_blank">these instructions</a> to configure the gateway.',
                'texty'
            ),
            'https://console.aws.amazon.com/iam/home#/users',
            'https://github.com/getdokan/texty/wiki/AwsSms'
        );
    }

    /**
     * Get the logo
     *
     * @return string
     */
    public function logo() {
        return TEXTY_URL . '/assets/images/gateways/aws_sms-logo.png';
    }

    /**
     * Get the settings
     *
     * `region` is a select over `regions()` (validate() enforces the same
     * whitelist); `origination_identity` is optional.
     *
     * @return array
     */
    public function get_settings() {
        $creds   = $this->creds();
        $regions = $this->regions();

        return [
            'access_key_id'        => [
                'name'  => __( 'Access key ID', 'texty' ),
                'type'  => 'text',
                'value' => $this->cred( $creds, 'access_key_id' ),
                'help'  => __( 'IAM user access key ID (starts with AKIA). Temporary STS credentials (ASIA keys with a session token) are not supported. The IAM user needs the sms-voice:SendTextMessage and sms-voice:DescribeAccountAttributes permissions.', 'texty' ),
            ],
            'secret_access_key'    => [
                'name'  => __( 'Secret access key', 'texty' ),
                'type'  => 'password',
                'value' => $this->cred( $creds, 'secret_access_key' ),
                'help'  => __( 'Shown once when the access key is created.', 'texty' ),
            ],
            'region'               => [
                'name'    => __( 'Region', 'texty' ),
                'type'    => 'select',
                'value'   => '' !== $this->cred( $creds, 'region' ) ? $this->cred( $creds, 'region' ) : self::DEFAULT_REGION,
                'options' => array_combine( $regions, $regions ),
                'help'    => __( 'Region where your phone number / sender ID and SMS sandbox status live. Each region has its own sandbox and spend limit.', 'texty' ),
            ],
            'origination_identity' => [
                'name'     => __( 'Origination identity (optional)', 'texty' ),
                'type'     => 'text',
                'value'    => $this->cred( $creds, 'origination_identity' ),
                'required' => false,
                'help'     => __( 'Phone number (E.164), PhoneNumberId, SenderId, PoolId or their ARN. Leave blank to let AWS pick. Shared resources require the full ARN.', 'texty' ),
            ],
        ];
    }

    /**
     * Use a fixed clock for request signing (tests)
     *
     * @since 2.1.0
     *
     * @param callable|int|null $clock Unix timestamp, callable returning one, or null for `time()`
     *
     * @return $this
     */
    public function set_clock( $clock ) {
        $this->clock = $clock;

        return $this;
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
        $options       = SendOptions::normalize( $options );
        $creds         = $this->creds();
        $access_key_id = $this->cred( $creds, 'access_key_id' );
        $secret        = $this->cred( $creds, 'secret_access_key' );

        if ( '' === $access_key_id || '' === $secret ) {
            return Result::failure(
                'texty_missing_credentials',
                __( 'AWS access key ID and secret access key are required.', 'texty' )
            );
        }

        $region = $this->region( $creds );

        if ( ! in_array( $region, $this->regions(), true ) ) {
            return Result::failure( 'aws_sms_invalid_region', $this->invalid_region_message( $region ) );
        }

        $destination = $this->normalize_number( $to );

        if ( '' === $destination ) {
            return Result::failure(
                'aws_sms_invalid_number',
                // translators: %s: recipient phone number
                sprintf( __( '%s is not a valid E.164 phone number.', 'texty' ), $to )
            );
        }

        $message = (string) $message;

        if ( '' === trim( $message ) || $this->length( $message ) > 1600 ) {
            return Result::failure(
                'aws_sms_invalid_message',
                __( 'The message must contain between 1 and 1600 characters.', 'texty' )
            );
        }

        $origination = '' !== $options['from'] ? $options['from'] : $this->cred( $creds, 'origination_identity' );

        if ( '' !== $origination && ! $this->is_valid_identity( $origination ) ) {
            return Result::failure( 'aws_sms_invalid_origination_identity', $this->invalid_identity_message() );
        }

        // Unicode is chosen by AWS automatically; India DLT parameters are not supported yet.
        $payload = [
            'DestinationPhoneNumber' => $destination,
            'MessageBody'            => $message,
            'MessageType'            => 'TRANSACTIONAL',
        ];

        if ( '' !== $origination ) {
            $payload['OriginationIdentity'] = $origination;
        }

        $response = $this->call( 'SendTextMessage', $payload, $access_key_id, $secret, $region, $creds );
        $failure  = $this->transport_failure( $response );

        if ( $failure ) {
            return $failure;
        }

        if ( 200 === $response->status() ) {
            // Result::success() turns a missing MessageId into a failure.
            return Result::success( $response->get( 'MessageId' ), $response );
        }

        return Result::failure( $this->error_code( $response ), $this->error_message( $response ), $response );
    }

    /**
     * Validate a REST API request
     *
     * Calls the read-only, free `DescribeAccountAttributes` operation to
     * prove the keys, signature and IAM permission work in the region.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|array
     */
    public function validate( $request ) {
        $submitted = $this->request_creds( $request );
        $creds     = [
            'access_key_id'        => sanitize_text_field( $this->cred( $submitted, 'access_key_id' ) ),
            'secret_access_key'    => sanitize_text_field( $this->cred( $submitted, 'secret_access_key' ) ),
            'region'               => sanitize_text_field( $this->cred( $submitted, 'region' ) ),
            'origination_identity' => sanitize_text_field( $this->cred( $submitted, 'origination_identity' ) ),
        ];

        if ( '' === $creds['access_key_id'] || '' === $creds['secret_access_key'] ) {
            return new WP_Error(
                'texty_missing_credentials',
                __( 'AWS access key ID and secret access key are required.', 'texty' )
            );
        }

        if ( '' === $creds['region'] ) {
            $creds['region'] = self::DEFAULT_REGION;
        }

        if ( ! in_array( $creds['region'], $this->regions(), true ) ) {
            return new WP_Error( 'aws_sms_invalid_region', $this->invalid_region_message( $creds['region'] ) );
        }

        if ( 0 === strpos( $creds['access_key_id'], 'ASIA' ) ) {
            return new WP_Error(
                'aws_sms_temporary_credentials',
                __( 'Temporary AWS credentials (access keys starting with ASIA) are not supported. Use a long-term IAM user access key (starting with AKIA).', 'texty' )
            );
        }

        if ( ! preg_match( '/^[A-Z0-9]{16,128}$/', $creds['access_key_id'] ) ) {
            return new WP_Error(
                'aws_sms_invalid_access_key',
                __( 'The access key ID looks invalid. It should be 16 to 128 upper-case letters and digits, usually starting with AKIA.', 'texty' )
            );
        }

        if ( '' !== $creds['origination_identity'] && ! $this->is_valid_identity( $creds['origination_identity'] ) ) {
            return new WP_Error( 'aws_sms_invalid_origination_identity', $this->invalid_identity_message() );
        }

        $response = $this->call(
            'DescribeAccountAttributes',
            [],
            $creds['access_key_id'],
            $creds['secret_access_key'],
            $creds['region'],
            $creds
        );

        if ( $response->is_transport_error() ) {
            return $response->get_error();
        }

        if ( 200 !== $response->status() || ! is_array( $response->get( 'AccountAttributes' ) ) ) {
            $code = $this->error_code( $response );

            return new WP_Error( $code, $this->validate_error_message( $code, $response ), $response->payload() );
        }

        return $creds;
    }

    /**
     * Regions the gateway can send from
     *
     * @since 2.1.0
     *
     * @return string[]
     */
    protected function regions() {
        $regions = [
            'us-east-1',
            'us-east-2',
            'us-west-1',
            'us-west-2',
            'af-south-1',
            'ap-south-1',
            'ap-south-2',
            'ap-southeast-1',
            'ap-southeast-2',
            'ap-southeast-3',
            'ap-southeast-4',
            'ap-southeast-6',
            'ap-northeast-1',
            'ap-northeast-2',
            'ap-northeast-3',
            'ap-east-2',
            'ca-central-1',
            'ca-west-1',
            'eu-central-1',
            'eu-central-2',
            'eu-west-1',
            'eu-west-2',
            'eu-west-3',
            'eu-south-1',
            'eu-south-2',
            'eu-north-1',
            'il-central-1',
            'mx-central-1',
            'me-south-1',
            'me-central-1',
            'sa-east-1',
        ];

        /**
         * Filter the AWS regions offered for AWS End User Messaging SMS.
         *
         * GovCloud and China partitions are excluded by default because they
         * need separate partition credentials.
         *
         * @since 2.1.0
         *
         * @param string[] $regions AWS region codes
         */
        $regions = apply_filters( 'texty_aws_sms_regions', $regions );

        return array_values( array_filter( array_map( 'strval', (array) $regions ) ) );
    }

    /**
     * Sign and send one AWS JSON 1.0 operation
     *
     * The body is serialized once and those exact bytes are signed and sent.
     *
     * @param string $operation     Operation name, e.g. SendTextMessage
     * @param array  $payload       Request parameters
     * @param string $access_key_id
     * @param string $secret
     * @param string $region
     * @param array  $creds         Credentials in use (for the base URL filter)
     *
     * @return Response
     */
    private function call( $operation, array $payload, $access_key_id, $secret, $region, array $creds ) {
        $url  = $this->base_url( sprintf( self::ENDPOINT, $region ), $creds ) . '/';
        $body = empty( $payload ) ? '{}' : wp_json_encode( $payload );

        if ( ! is_string( $body ) ) {
            return Response::from_error(
                new WP_Error( 'aws_sms_encode_failed', __( 'The request body could not be encoded as JSON.', 'texty' ) )
            );
        }

        $signer  = new SigV4( $access_key_id, $secret, $region, self::SERVICE, $this->clock );
        $headers = $signer->sign(
            'POST',
            $url,
            [
                'Content-Type' => self::CONTENT_TYPE,
                'X-Amz-Target' => self::TARGET_PREFIX . '.' . $operation,
            ],
            $body
        );

        return $this->http()->post(
            $url,
            [
                'headers' => $headers,
                'body'    => $body,
            ]
        );
    }

    /**
     * Saved region, falling back to the default
     *
     * @param array $creds
     *
     * @return string
     */
    private function region( array $creds ) {
        $region = $this->cred( $creds, 'region' );

        return '' !== $region ? $region : self::DEFAULT_REGION;
    }

    /**
     * Normalize a phone number to E.164 with a leading +
     *
     * @param string $to
     *
     * @return string Empty string when the number is not valid E.164
     */
    private function normalize_number( $to ) {
        $number = '+' . ltrim( (string) preg_replace( '/[^0-9+]/', '', (string) $to ), '+' );

        // E.164: at most 15 digits; nothing real is shorter than 7.
        return preg_match( '/^\+[1-9][0-9]{6,14}$/', $number ) ? $number : '';
    }

    /**
     * Whether a value matches the documented OriginationIdentity pattern
     *
     * @param string $identity
     *
     * @return bool
     */
    private function is_valid_identity( $identity ) {
        return 1 === preg_match( '#^[A-Za-z0-9_:/+\-]{1,256}$#', $identity );
    }

    /**
     * Character length of a message
     *
     * @param string $message
     *
     * @return int
     */
    private function length( $message ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $message, 'UTF-8' ) : strlen( $message );
    }

    /**
     * Error code from an AWS JSON error response
     *
     * Reads `__type`, then `code`, then the `x-amzn-ErrorType` header, and
     * strips any namespace (`...#Name`) or URI suffix (`Name:http://...`).
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_code( Response $response ) {
        $candidates = [
            $response->get( '__type' ),
            $response->get( 'code' ),
            $response->header( 'x-amzn-ErrorType' ),
        ];

        foreach ( $candidates as $candidate ) {
            if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
                continue;
            }

            $code = explode( ':', trim( $candidate ), 2 )[0];
            $hash = strpos( $code, '#' );

            if ( false !== $hash ) {
                $code = substr( $code, $hash + 1 );
            }

            if ( '' !== $code ) {
                return $code;
            }
        }

        return 'aws_sms_http_' . $response->status();
    }

    /**
     * Error message from an AWS JSON error response, with the Reason if any
     *
     * @param Response $response
     *
     * @return string
     */
    private function error_message( Response $response ) {
        $message = '';

        foreach ( [ 'message', 'Message' ] as $field ) {
            $value = $response->get( $field );

            if ( is_string( $value ) && '' !== $value ) {
                $message = $value;
                break;
            }
        }

        $reason = $response->get( 'Reason' );

        if ( is_string( $reason ) && '' !== $reason ) {
            return '' === $message
                ? $reason
                // translators: 1: AWS error message, 2: AWS error reason code
                : sprintf( __( '%1$s (reason: %2$s)', 'texty' ), $message, $reason );
        }

        return $message;
    }

    /**
     * Friendlier validate() error message for common auth failures
     *
     * @param string   $code
     * @param Response $response
     *
     * @return string
     */
    private function validate_error_message( $code, Response $response ) {
        $message = $this->error_message( $response );

        if ( in_array( $code, [ 'UnrecognizedClientException', 'InvalidSignatureException', 'IncompleteSignature' ], true ) ) {
            return sprintf(
                // translators: %s: AWS error message
                __( 'AWS rejected the access key ID or secret access key: %s', 'texty' ),
                $message
            );
        }

        if ( 'AccessDeniedException' === $code ) {
            return sprintf(
                // translators: 1: IAM action name, 2: AWS error message
                __( 'The access key is valid but the IAM user is not allowed to call %1$s: %2$s', 'texty' ),
                'sms-voice:DescribeAccountAttributes',
                $message
            );
        }

        return '' !== $message ? $message : __( 'AWS could not verify the credentials.', 'texty' );
    }

    /**
     * Error message for an unsupported region
     *
     * @param string $region
     *
     * @return string
     */
    private function invalid_region_message( $region ) {
        return sprintf(
            // translators: %s: region code entered by the user
            __( '%s is not a supported AWS region for End User Messaging SMS.', 'texty' ),
            $region
        );
    }

    /**
     * Error message for a malformed origination identity
     *
     * @return string
     */
    private function invalid_identity_message() {
        return __( 'The origination identity must be 1 to 256 letters, digits or the characters _ : / + -.', 'texty' );
    }
}
