<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\SMSAPI;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\SMSAPI
 */
class SMSAPITest extends GatewayTestCase {

    const TOKEN = 'smsapi-oauth-token-1234';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'smsapi',
            [
                'token'  => self::TOKEN,
                'region' => 'com',
                'from'   => 'Texty',
            ]
        );
    }

    public function test_settings_and_meta_shape() {
        $gateway  = new SMSAPI();
        $settings = $gateway->get_settings();

        $this->assertSame( 'password', $settings['token']['type'] );
        $this->assertSame( 'select', $settings['region']['type'] );
        $this->assertSame( [ 'com', 'pl' ], array_keys( $settings['region']['options'] ) );
        $this->assertSame( 'com', $settings['region']['value'] );
        $this->assertFalse( $settings['from']['required'] );
        $this->assertTrue( $settings['from']['alphanumeric'] );
        $this->assertSame( [], $gateway->metadata()['regions'] );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'smsapi', 'success' );

        $result = ( new SMSAPI() )->send_result( '+44 123-456-789', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '1460969715572091219', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', 'https://api.smsapi.com/sms.do' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );
        $this->assertSame(
            [
                'to'       => '44123456789',
                'message'  => 'Hello',
                'format'   => 'json',
                'encoding' => 'utf-8',
                'from'     => 'Texty',
            ],
            $this->request_form()
        );

        $this->assertStringNotContainsString( self::TOKEN, $this->last_request()['url'] );
        $this->assertNotContains( self::TOKEN, $this->request_form() );
        $this->assert_all_responses_used();
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'smsapi', 'success' );

        ( new SMSAPI() )->send_result( '44123456789', 'Hello', [ 'from' => 'Override' ] );

        $this->assertSame( 'Override', $this->request_form()['from'] );
    }

    public function test_empty_sender_is_omitted() {
        $this->set_credentials(
            'smsapi',
            [
                'token'  => self::TOKEN,
                'region' => 'com',
                'from'   => '',
            ]
        );
        $this->respond_with_fixture( 'smsapi', 'success' );

        ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertArrayNotHasKey( 'from', $this->request_form() );
    }

    public function test_unicode_false_does_not_transliterate() {
        $this->respond_with_fixture( 'smsapi', 'success' );

        ( new SMSAPI() )->send_result( '44123456789', 'Zażółć', [ 'unicode' => false ] );

        $form = $this->request_form();

        $this->assertArrayNotHasKey( 'normalize', $form );
        $this->assertArrayNotHasKey( 'nounicode', $form );
        $this->assertSame( 'Zażółć', $form['message'] );
    }

    public function test_pl_region_uses_pl_host() {
        $this->set_credentials(
            'smsapi',
            [
                'token'  => self::TOKEN,
                'region' => 'pl',
            ]
        );
        $this->respond_with_fixture( 'smsapi', 'success' );

        $result = ( new SMSAPI() )->send_result( '48500000000', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assert_request( 'POST', 'https://api.smsapi.pl/sms.do' );
    }

    public function test_unknown_region_falls_back_to_com() {
        $this->set_credentials(
            'smsapi',
            [
                'token'  => self::TOKEN,
                'region' => 'se',
            ]
        );
        $this->respond_with_fixture( 'smsapi', 'success' );

        ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assert_request( 'POST', 'https://api.smsapi.com/sms.do' );
    }

    public function test_invalid_credentials_in_200_is_a_failure() {
        $this->respond_with_fixture( 'smsapi', 'invalid-credentials' );

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '101', $result->get_error_code() );
        $this->assertSame( 'Invalid authorization info', $result->get_error_message() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_invalid_number_in_200_is_a_failure() {
        $this->respond_with_fixture( 'smsapi', 'invalid-number' );

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '13', $result->get_error_code() );
        $this->assertSame( 'No correct phone numbers (Invalid phone number)', $result->get_error_message() );
    }

    public function test_insufficient_balance_in_200_is_a_failure() {
        $this->respond_with_fixture( 'smsapi', 'insufficient-balance' );

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '103', $result->get_error_code() );
        $this->assertSame( 'Insufficient credits on Your account', $result->get_error_message() );
    }

    public function test_invalid_sender_in_200_is_a_failure() {
        $this->respond_with_fixture( 'smsapi', 'invalid-sender' );

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '14', $result->get_error_code() );
        $this->assertSame( 'Wrong sender name', $result->get_error_message() );
    }

    public function test_200_without_message_id_is_a_failure() {
        $this->respond_with(
            200,
            [
                'count' => 0,
                'list'  => [],
            ]
        );

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_server_error_with_non_json_body() {
        $this->respond_with_fixture( 'smsapi', 'server-error' );

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsapi_http_503', $result->get_error_code() );
        $this->assertSame( 'Unknown error', $result->get_error_message() );
        $this->assertSame( 503, $result->get_http_status() );
        $this->assertCount( 1, $this->requests, 'A send must not be retried.' );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests, 'A send must not be retried.' );
    }

    public function test_missing_credentials_make_no_request() {
        $this->set_credentials( 'smsapi', [ 'token' => '' ] );

        $result = ( new SMSAPI() )->send_result( '44123456789', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_ok() {
        $this->respond_with_fixture( 'smsapi', 'validate-ok' );

        $creds = ( new SMSAPI() )->validate(
            $this->validate_request(
                'smsapi',
                [
                    'token'  => ' ' . self::TOKEN . ' ',
                    'region' => 'smsapi.pl',
                    'from'   => ' Texty ',
                ]
            )
        );

        $this->assertSame(
            [
                'token'  => self::TOKEN,
                'region' => 'pl',
                'from'   => 'Texty',
            ],
            $creds
        );

        $this->assert_request( 'GET', 'https://api.smsapi.pl/profile' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );
        $this->assertStringNotContainsString( self::TOKEN, $this->last_request()['url'] );
    }

    public function test_validate_fail() {
        $this->respond_with_fixture( 'smsapi', 'validate-fail' );

        $result = ( new SMSAPI() )->validate(
            $this->validate_request(
                'smsapi',
                [
                    'token'  => self::TOKEN,
                    'region' => 'com',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'authorization_failed', $result->get_error_code() );
        $this->assertSame( 'Authorization failed', $result->get_error_message() );
        $this->assertSame( 'authorization_failed', $result->get_error_data()['error'] );
        $this->assert_request( 'GET', 'https://api.smsapi.com/profile' );
    }

    public function test_validate_reports_missing_profile_scope() {
        $this->respond_with(
            403,
            [
                'message' => 'Forbidden',
                'error'   => 'forbidden',
            ]
        );

        $result = ( new SMSAPI() )->validate( $this->validate_request( 'smsapi', [ 'token' => self::TOKEN ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'forbidden', $result->get_error_code() );
        $this->assertStringContainsString( 'scope', $result->get_error_message() );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new SMSAPI() )->validate( $this->validate_request( 'smsapi', [ 'token' => self::TOKEN ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_rejects_missing_token() {
        $result = ( new SMSAPI() )->validate( $this->validate_request( 'smsapi', [ 'token' => '' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }
}
