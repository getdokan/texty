<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\BulkSMS;
use Texty\Gateways\Result;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\BulkSMS
 */
class BulkSMSTest extends GatewayTestCase {

    const TOKEN_ID     = 'BBDE1B476E03498AA768F66A286AABDC-01-B';
    const TOKEN_SECRET = '9jSbVDK20!MXdfRGiIIFu#ffUE8*S';
    const SEND_URL     = 'https://api.bulksms.com/v1/messages?auto-unicode=true';
    const PROFILE_URL  = 'https://api.bulksms.com/v1/profile';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'bulksms',
            [
                'token_id'     => self::TOKEN_ID,
                'token_secret' => self::TOKEN_SECRET,
                'from'         => 'TEXTY',
            ]
        );
    }

    /**
     * Expected Basic auth header
     *
     * @param string $secret
     *
     * @return string
     */
    private function basic_auth( $secret = self::TOKEN_SECRET ) {
        return 'Basic ' . base64_encode( self::TOKEN_ID . ':' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'bulksms', 'success' );

        $result = ( new BulkSMS() )->send_result( '+27001234567', 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( '1918293012', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', $this->basic_auth() );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'to'                  => '+27001234567',
                'body'                => 'Hello from Texty',
                'from'                => 'TEXTY',
                'longMessageMaxParts' => 10,
            ],
            $this->request_json()
        );
    }

    public function test_number_without_plus_is_normalized() {
        $this->respond_with_fixture( 'bulksms', 'success' );

        ( new BulkSMS() )->send_result( '27001234567', 'Hello from Texty' );

        $this->assertSame( '+27001234567', $this->request_json()['to'] );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'bulksms', 'success' );

        ( new BulkSMS() )->send_result( '+27001234567', 'Hi', [ 'from' => '+27007654321' ] );

        $this->assertSame( '+27007654321', $this->request_json()['from'] );
    }

    public function test_from_is_omitted_when_empty() {
        $this->set_credentials(
            'bulksms',
            [
                'token_id'     => self::TOKEN_ID,
                'token_secret' => self::TOKEN_SECRET,
                'from'         => '',
            ]
        );
        $this->respond_with_fixture( 'bulksms', 'success' );

        ( new BulkSMS() )->send_result( '+27001234567', 'Hi' );

        $this->assertArrayNotHasKey( 'from', $this->request_json() );
    }

    public function test_unicode_option_sets_encoding() {
        $this->respond_with_fixture( 'bulksms', 'success' );

        ( new BulkSMS() )->send_result( '+27001234567', 'হ্যালো', [ 'unicode' => true ] );

        $body = $this->request_json();

        $this->assertSame( 'UNICODE', $body['encoding'] );
        $this->assertSame( 'হ্যালো', $body['body'] );
    }

    public function test_template_options_are_ignored() {
        $this->respond_with_fixture( 'bulksms', 'success' );

        ( new BulkSMS() )->send_result(
            '+27001234567',
            'Hi',
            [
                'template_id' => 'tpl-1',
                'variables'   => [ 'name' => 'Ann' ],
            ]
        );

        $body = $this->request_json();

        $this->assertArrayNotHasKey( 'template_id', $body );
        $this->assertArrayNotHasKey( 'variables', $body );
        $this->assertArrayNotHasKey( 'encoding', $body );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'bulksms', 'invalid-credentials' );

        $result = ( new BulkSMS() )->send_result( '+27001234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bulksms_authentication-failed', $result->get_error_code() );
        $this->assertSame( 'The supplied credentials are not valid.', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'bulksms', 'invalid-number' );

        $result = ( new BulkSMS() )->send_result( 'abc', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bulksms_bad-request', $result->get_error_code() );
        $this->assertSame( "The phone number 'abc' is not a valid international number.", $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_failed_message_inside_201_is_a_failure() {
        $this->respond_with_fixture( 'bulksms', 'failed-in-201' );

        $result = ( new BulkSMS() )->send_result( '+999123', 'Hello from Texty' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bulksms_failed.not_sent', $result->get_error_code() );
        $this->assertSame( 'BulkSMS could not find a route for the phone number.', $result->get_error_message() );
        $this->assertSame( 201, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_insufficient_credits_type_without_slash() {
        $this->respond_with_fixture( 'bulksms', 'insufficient-credits' );

        $result = ( new BulkSMS() )->send_result( '+27001234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bulksms_insufficient-credits', $result->get_error_code() );
        $this->assertSame( 'Insufficient Credits', $result->get_error_message() );
        $this->assertSame( 403, $result->get_http_status() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'bulksms', 'server-error' );

        $result = ( new BulkSMS() )->send_result( '+27001234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bulksms_internal-server-error', $result->get_error_code() );
        $this->assertSame( 'Internal Server Error', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_non_json_server_error() {
        $this->respond_with_fixture( 'bulksms', 'server-error-raw' );

        $result = ( new BulkSMS() )->send_result( '+27001234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bulksms_http_503', $result->get_error_code() );
        $this->assertSame( 'BulkSMS returned an unexpected response (HTTP 503).', $result->get_error_message() );
        $this->assertSame( 503, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new BulkSMS() )->send_result( '+27001234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 'cURL error 28: Operation timed out', $result->get_error_message() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'bulksms', [ 'token_id' => self::TOKEN_ID ] );

        $result = ( new BulkSMS() )->send_result( '+27001234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'bulksms', 'validate-ok' );

        $creds = ( new BulkSMS() )->validate(
            $this->validate_request(
                'bulksms',
                [
                    'token_id'     => ' ' . self::TOKEN_ID . ' ',
                    'token_secret' => self::TOKEN_SECRET,
                    'from'         => 'TEXTY',
                ]
            )
        );

        $this->assertSame(
            [
                'token_id'     => self::TOKEN_ID,
                'token_secret' => self::TOKEN_SECRET,
                'from'         => 'TEXTY',
            ],
            $creds
        );

        $this->assert_request( 'GET', self::PROFILE_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', $this->basic_auth() );
        $this->assert_all_responses_used();
    }

    public function test_validate_keeps_special_characters_in_secret() {
        $secret = 'ab%41<c>d!#*';

        $this->respond_with_fixture( 'bulksms', 'validate-ok' );

        $creds = ( new BulkSMS() )->validate(
            $this->validate_request(
                'bulksms',
                [
                    'token_id'     => self::TOKEN_ID,
                    'token_secret' => $secret,
                ]
            )
        );

        $this->assertSame( $secret, $creds['token_secret'] );
        $this->assertSame( '', $creds['from'] );
        $this->assert_request_header( 'Authorization', $this->basic_auth( $secret ) );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'bulksms', 'validate-fail' );

        $result = ( new BulkSMS() )->validate(
            $this->validate_request(
                'bulksms',
                [
                    'token_id'     => self::TOKEN_ID,
                    'token_secret' => 'wrong',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'bulksms_authentication-failed', $result->get_error_code() );
        $this->assertSame( 'The supplied credentials are not valid.', $result->get_error_message() );
        $this->assertSame( 401, $result->get_error_data()['status'] );
    }

    public function test_validate_requires_token_id_and_secret() {
        $result = ( new BulkSMS() )->validate( $this->validate_request( 'bulksms', [ 'token_id' => self::TOKEN_ID ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_bad_sender_without_request() {
        $result = ( new BulkSMS() )->validate(
            $this->validate_request(
                'bulksms',
                [
                    'token_id'     => self::TOKEN_ID,
                    'token_secret' => self::TOKEN_SECRET,
                    'from'         => 'TOOLONGSENDERID',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new BulkSMS() )->validate(
            $this->validate_request(
                'bulksms',
                [
                    'token_id'     => self::TOKEN_ID,
                    'token_secret' => self::TOKEN_SECRET,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_metadata() {
        $meta = ( new BulkSMS() )->metadata();

        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertTrue( $meta['features']['sender_id'] );
        $this->assertFalse( $meta['features']['template'] );
        $this->assertTrue( $meta['features']['balance_check'] );
    }
}
