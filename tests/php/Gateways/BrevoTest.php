<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Brevo;
use Texty\Gateways\Result;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Brevo
 */
class BrevoTest extends GatewayTestCase {

    const API_KEY     = 'xkeysib-test-key-1234';
    const SENDER      = 'MyShop';
    const SEND_URL    = 'https://api.brevo.com/v3/transactionalSMS/sms';
    const ACCOUNT_URL = 'https://api.brevo.com/v3/account';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'brevo',
            [
                'api_key' => self::API_KEY,
                'sender'  => self::SENDER,
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'brevo', 'success' );

        $result = ( new Brevo() )->send_result( '+33 6-89-96-54-33', 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( '1511882900176220', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'api-key', self::API_KEY );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assertSame(
            [
                'sender'         => self::SENDER,
                'recipient'      => '33689965433',
                'content'        => 'Hello from Texty',
                'type'           => 'transactional',
                'unicodeEnabled' => false,
                'tag'            => 'texty',
            ],
            $this->request_json()
        );
    }

    public function test_large_message_id_keeps_precision() {
        $this->respond_with( 201, '{"messageId":98765432109876543210,"reference":"ab1cde2fgh3i4jklmno","smsCount":1}', [ 'content-type' => 'application/json' ] );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '98765432109876543210', $result->get_reference_id() );
    }

    public function test_201_without_message_id_is_a_failure() {
        $this->respond_with( 201, [ 'reference' => 'ab1cde2fgh3i4jklmno' ] );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'brevo', 'success' );

        ( new Brevo() )->send_result( '33689965433', 'Hi', [ 'from' => '12025550123' ] );

        $this->assertSame( '12025550123', $this->request_json()['sender'] );
    }

    public function test_invalid_sender_never_calls_the_api() {
        $result = ( new Brevo() )->send_result( '33689965433', 'Hi', [ 'from' => 'My Shop!' ] );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_unicode_is_detected_automatically() {
        $this->respond_with_fixture( 'brevo', 'success' );

        ( new Brevo() )->send_result( '33689965433', 'আপনার অর্ডার নিশ্চিত হয়েছে' );

        $this->assertTrue( $this->request_json()['unicodeEnabled'] );
    }

    public function test_gsm_extension_characters_stay_gsm() {
        $this->respond_with_fixture( 'brevo', 'success' );

        ( new Brevo() )->send_result( '33689965433', 'Total: €10 [paid] {ok} ~ ^ | \\ é' );

        $this->assertFalse( $this->request_json()['unicodeEnabled'] );
    }

    public function test_unicode_option_forces_unicode() {
        $this->respond_with_fixture( 'brevo', 'success' );

        ( new Brevo() )->send_result( '33689965433', 'Hello', [ 'unicode' => true ] );

        $this->assertTrue( $this->request_json()['unicodeEnabled'] );
    }

    public function test_template_replaces_content() {
        $this->respond_with_fixture( 'brevo', 'success' );

        ( new Brevo() )->send_result(
            '33689965433',
            'ignored',
            [
                'template_id' => '42',
                'variables'   => [ 'order' => '1001' ],
            ]
        );

        $this->assertSame(
            [
                'sender'         => self::SENDER,
                'recipient'      => '33689965433',
                'templateId'     => 42,
                'params'         => [ 'order' => '1001' ],
                'type'           => 'transactional',
                'unicodeEnabled' => false,
                'tag'            => 'texty',
            ],
            $this->request_json()
        );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'brevo', 'invalid-credentials' );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unauthorized', $result->get_error_code() );
        $this->assertSame( 'Key not found (placeholder)', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'brevo', 'invalid-number' );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'invalid_parameter', $result->get_error_code() );
        $this->assertSame( 'Invalid recipient (placeholder)', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_out_of_range_number_never_calls_the_api() {
        $result = ( new Brevo() )->send_result( '+123', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_invalid_number', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_insufficient_credits() {
        $this->respond_with_fixture( 'brevo', 'insufficient-credits' );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'not_enough_credits', $result->get_error_code() );
        $this->assertSame( 402, $result->get_http_status() );
    }

    public function test_error_code_with_spaces_is_slugged() {
        $this->respond_with(
            400,
            [
                'code'    => 'api-key not found',
                'message' => 'Key not found',
            ]
        );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertSame( 'api-key_not_found', $result->get_error_code() );
        $this->assertSame( 'Key not found', $result->get_error_message() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'brevo', 'server-error' );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'brevo_http_503', $result->get_error_code() );
        $this->assertSame( 503, $result->get_http_status() );
        $this->assertNotSame( '', $result->get_error_message() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials( 'brevo', [ 'api_key' => '', 'sender' => self::SENDER ] );

        $result = ( new Brevo() )->send_result( '33689965433', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'brevo', 'validate-ok' );

        $creds = ( new Brevo() )->validate(
            $this->validate_request(
                'brevo',
                [
                    'api_key' => ' ' . self::API_KEY . ' ',
                    'sender'  => self::SENDER,
                ]
            )
        );

        $this->assertSame(
            [
                'api_key' => self::API_KEY,
                'sender'  => self::SENDER,
            ],
            $creds
        );
        $this->assert_request( 'GET', self::ACCOUNT_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'api-key', self::API_KEY );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'brevo', 'validate-fail' );

        $result = ( new Brevo() )->validate(
            $this->validate_request(
                'brevo',
                [
                    'api_key' => 'wrong',
                    'sender'  => self::SENDER,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'unauthorized', $result->get_error_code() );
        $this->assertSame( 'Key not found (placeholder)', $result->get_error_message() );
    }

    public function test_validate_rejects_invalid_sender_without_calling_the_api() {
        $result = ( new Brevo() )->validate(
            $this->validate_request(
                'brevo',
                [
                    'api_key' => self::API_KEY,
                    'sender'  => 'TooLongSender12',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_requires_key_and_sender() {
        $result = ( new Brevo() )->validate( $this->validate_request( 'brevo', [ 'api_key' => '' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_metadata() {
        $meta = ( new Brevo() )->metadata();

        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertFalse( $meta['features']['template'] );
    }

    public function test_api_key_is_a_secret() {
        $settings = ( new Brevo() )->get_settings();

        $this->assertSame( 'password', $settings['api_key']['type'] );
        $this->assertSame( 'text', $settings['sender']['type'] );
    }
}
