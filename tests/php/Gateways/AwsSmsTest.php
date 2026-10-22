<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\AwsSms;
use Texty\Http\SigV4;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\AwsSms
 */
class AwsSmsTest extends GatewayTestCase {

    const KEY_ID   = 'AKIAIOSFODNN7EXAMPLE';
    const SECRET   = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    const NOW      = 1440938160; // 2015-08-30T12:36:00Z
    const AMZ_DATE = '20150830T123600Z';
    const URL      = 'https://sms-voice.us-east-1.amazonaws.com/';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'aws_sms',
            [
                'access_key_id'        => self::KEY_ID,
                'secret_access_key'    => self::SECRET,
                'region'               => 'us-east-1',
                'origination_identity' => '+18005550100',
            ],
            false
        );
    }

    /**
     * Gateway with a frozen clock
     *
     * @return AwsSms
     */
    private function gateway() {
        return ( new AwsSms() )->set_clock( self::NOW );
    }

    /**
     * Recompute the expected Authorization header for the last request
     *
     * @param string $operation
     * @param string $region
     * @param string $url
     *
     * @return string
     */
    private function expected_authorization( $operation, $region = 'us-east-1', $url = self::URL ) {
        $signer  = new SigV4( self::KEY_ID, self::SECRET, $region, 'sms-voice', self::NOW );
        $headers = $signer->sign(
            'POST',
            $url,
            [
                'Content-Type' => 'application/x-amz-json-1.0',
                'X-Amz-Target' => 'PinpointSMSVoiceV2.' . $operation,
            ],
            $this->last_request()['body']
        );

        return $headers['Authorization'];
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'aws_sms', 'success' );

        $result = $this->gateway()->send_result( '+880 1712-345678', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'e5f1c2a0-0000-4000-8000-000000000001', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Content-Type', 'application/x-amz-json-1.0' );
        $this->assert_request_header( 'X-Amz-Target', 'PinpointSMSVoiceV2.SendTextMessage' );
        $this->assert_request_header( 'X-Amz-Date', self::AMZ_DATE );

        $headers = array_change_key_case( $this->last_request()['headers'], CASE_LOWER );

        $this->assertStringStartsWith(
            'AWS4-HMAC-SHA256 Credential=' . self::KEY_ID . '/20150830/us-east-1/sms-voice/aws4_request, SignedHeaders=content-type;host;x-amz-date;x-amz-target, Signature=',
            $headers['authorization']
        );
        $this->assertMatchesRegularExpression( '/Signature=[0-9a-f]{64}$/', $headers['authorization'] );
        // The signature covers the exact body bytes that were sent.
        $this->assertSame( $this->expected_authorization( 'SendTextMessage' ), $headers['authorization'] );

        $this->assertSame(
            [
                'DestinationPhoneNumber' => '+8801712345678',
                'MessageBody'            => 'Hello',
                'MessageType'            => 'TRANSACTIONAL',
                'OriginationIdentity'    => '+18005550100',
            ],
            $this->request_json()
        );

        $this->assertStringNotContainsString( self::SECRET, (string) $this->last_request()['body'] );
        $this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $result->to_array() ) );
    }

    public function test_number_without_plus_is_normalized() {
        $this->respond_with_fixture( 'aws_sms', 'success' );

        $this->gateway()->send_result( '8801712345678', 'Hello' );

        $this->assertSame( '+8801712345678', $this->request_json()['DestinationPhoneNumber'] );
    }

    public function test_from_option_overrides_saved_identity() {
        $this->respond_with_fixture( 'aws_sms', 'success' );

        $this->gateway()->send_result( '+8801712345678', 'Hi', [ 'from' => 'TEXTY' ] );

        $this->assertSame( 'TEXTY', $this->request_json()['OriginationIdentity'] );
    }

    public function test_empty_identity_is_omitted() {
        $this->set_credentials(
            'aws_sms',
            [
                'access_key_id'     => self::KEY_ID,
                'secret_access_key' => self::SECRET,
                'region'            => 'us-east-1',
            ],
            false
        );
        $this->respond_with_fixture( 'aws_sms', 'success' );

        $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertArrayNotHasKey( 'OriginationIdentity', $this->request_json() );
    }

    public function test_region_drives_the_endpoint_and_scope() {
        $this->set_credentials(
            'aws_sms',
            [
                'access_key_id'     => self::KEY_ID,
                'secret_access_key' => self::SECRET,
                'region'            => 'eu-west-2',
            ],
            false
        );
        $this->respond_with_fixture( 'aws_sms', 'success' );

        $this->gateway()->send_result( '+447700900123', 'Hi' );

        $url = 'https://sms-voice.eu-west-2.amazonaws.com/';

        $this->assert_request( 'POST', $url );
        $this->assert_request_header( 'Authorization', $this->expected_authorization( 'SendTextMessage', 'eu-west-2', $url ) );
        $this->assertStringContainsString( '/20150830/eu-west-2/sms-voice/aws4_request', $this->last_request()['headers']['Authorization'] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'aws_sms', 'invalid-credentials' );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'UnrecognizedClientException', $result->get_error_code() );
        $this->assertSame( 'The security token included in the request is invalid.', $result->get_error_message() );
        $this->assertSame( 403, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_signature() {
        $this->respond_with_fixture( 'aws_sms', 'invalid-signature' );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'InvalidSignatureException', $result->get_error_code() );
    }

    public function test_invalid_number_from_aws() {
        $this->respond_with_fixture( 'aws_sms', 'invalid-number' );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'ValidationException', $result->get_error_code() );
        $this->assertSame( 'Invalid destination phone number. (reason: INVALID_PARAMETER)', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_malformed_number_never_calls_the_api() {
        $result = $this->gateway()->send_result( '12', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'aws_sms_invalid_number', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_empty_message_never_calls_the_api() {
        $result = $this->gateway()->send_result( '+8801712345678', '   ' );

        $this->assertSame( 'aws_sms_invalid_message', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_quota_exceeded() {
        $this->respond_with_fixture( 'aws_sms', 'quota-exceeded' );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'ServiceQuotaExceededException', $result->get_error_code() );
        $this->assertSame( 'The monthly SMS spend limit has been reached. (reason: MONTHLY_SPEND_LIMIT_REACHED_FOR_TEXT)', $result->get_error_message() );
    }

    public function test_error_code_falls_back_to_header() {
        $this->respond_with_fixture( 'aws_sms', 'throttling-header-only' );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'ThrottlingException', $result->get_error_code() );
        $this->assertSame( 'Rate exceeded', $result->get_error_message() );
    }

    public function test_200_without_message_id_is_a_failure() {
        $this->respond_with( 200, [ 'RequestId' => 'abc' ] );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'aws_sms', 'server-error' );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'InternalServerException', $result->get_error_code() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_server_error_with_empty_body_uses_status_code() {
        $this->respond_with( 503, '' );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertSame( 'aws_sms_http_503', $result->get_error_code() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assert_all_responses_used();
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials(
            'aws_sms',
            [
                'access_key_id'     => '',
                'secret_access_key' => '',
            ],
            false
        );

        $result = $this->gateway()->send_result( '+8801712345678', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'aws_sms', 'validate-ok' );

        $creds = $this->gateway()->validate(
            $this->validate_request(
                'aws_sms',
                [
                    'access_key_id'        => ' ' . self::KEY_ID . ' ',
                    'secret_access_key'    => self::SECRET,
                    'region'               => '',
                    'origination_identity' => '+18005550100',
                ]
            )
        );

        $this->assertSame(
            [
                'access_key_id'        => self::KEY_ID,
                'secret_access_key'    => self::SECRET,
                'region'               => 'us-east-1',
                'origination_identity' => '+18005550100',
            ],
            $creds
        );

        $this->assert_request( 'POST', self::URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'X-Amz-Target', 'PinpointSMSVoiceV2.DescribeAccountAttributes' );
        $this->assert_request_header( 'X-Amz-Date', self::AMZ_DATE );
        $this->assert_request_header( 'Authorization', $this->expected_authorization( 'DescribeAccountAttributes' ) );
        $this->assertSame( '{}', $this->last_request()['body'] );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'aws_sms', 'validate-fail' );

        $result = $this->gateway()->validate(
            $this->validate_request(
                'aws_sms',
                [
                    'access_key_id'     => self::KEY_ID,
                    'secret_access_key' => 'wrong',
                    'region'            => 'us-east-1',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'UnrecognizedClientException', $result->get_error_code() );
        $this->assertSame(
            'AWS rejected the access key ID or secret access key: The security token included in the request is invalid.',
            $result->get_error_message()
        );
        $this->assertSame( 'UnrecognizedClientException', $result->get_error_data()['__type'] );
    }

    public function test_validate_reports_missing_iam_permission() {
        $this->respond_with_fixture( 'aws_sms', 'access-denied' );

        $result = $this->gateway()->validate(
            $this->validate_request(
                'aws_sms',
                [
                    'access_key_id'     => self::KEY_ID,
                    'secret_access_key' => self::SECRET,
                    'region'            => 'us-east-1',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'AccessDeniedException', $result->get_error_code() );
        $this->assertStringContainsString( 'sms-voice:DescribeAccountAttributes', $result->get_error_message() );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = $this->gateway()->validate(
            $this->validate_request(
                'aws_sms',
                [
                    'access_key_id'     => self::KEY_ID,
                    'secret_access_key' => self::SECRET,
                    'region'            => 'us-east-1',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_keys() {
        $result = $this->gateway()->validate( $this->validate_request( 'aws_sms', [ 'access_key_id' => self::KEY_ID ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_temporary_credentials() {
        $result = $this->gateway()->validate(
            $this->validate_request(
                'aws_sms',
                [
                    'access_key_id'     => 'ASIAIOSFODNN7EXAMPLE',
                    'secret_access_key' => self::SECRET,
                    'region'            => 'us-east-1',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'aws_sms_temporary_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_unknown_region() {
        $result = $this->gateway()->validate(
            $this->validate_request(
                'aws_sms',
                [
                    'access_key_id'     => self::KEY_ID,
                    'secret_access_key' => self::SECRET,
                    'region'            => 'mars-north-1',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'aws_sms_invalid_region', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_secret_is_a_password_field() {
        $settings = ( new AwsSms() )->get_settings();

        $this->assertSame( 'password', $settings['secret_access_key']['type'] );
        $this->assertSame( 'select', $settings['region']['type'] );
        $this->assertSame( 'us-east-1', $settings['region']['options']['us-east-1'] );
        $this->assertSame( 'us-east-1', $settings['region']['value'] );
        $this->assertFalse( $settings['origination_identity']['required'] );
        $this->assertSame( [], ( new AwsSms() )->metadata()['regions'] );
        $this->assertSame(
            [ 'access_key_id', 'secret_access_key', 'region', 'origination_identity' ],
            array_keys( $settings )
        );
    }
}
