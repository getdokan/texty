<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Vonage;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Vonage
 */
class VonageTest extends GatewayTestCase {

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'vonage',
            [
                'key'    => 'abcd1234',
                'secret' => 'vonage-secret-1234',
                'from'   => 'Texty',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'vonage', 'success' );

        $result = ( new Vonage() )->send_result( '447700900000', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'aaaaaaaa-bbbb-cccc-dddd-0123456789ab', $result->get_reference_id() );

        $this->assert_request( 'POST', 'https://rest.nexmo.com/sms/json' );
        $this->assert_request_is_safe();
        $this->assertSame(
            [
                'from'       => 'Texty',
                'text'       => 'Hello',
                'to'         => '447700900000',
                'api_key'    => 'abcd1234',
                'api_secret' => 'vonage-secret-1234',
            ],
            $this->request_form()
        );
    }

    public function test_unicode_option_sets_type() {
        $this->respond_with_fixture( 'vonage', 'success' );

        ( new Vonage() )->send_result( '447700900000', 'হ্যালো', [ 'unicode' => true ] );

        $this->assertSame( 'unicode', $this->request_form()['type'] );
    }

    public function test_invalid_credentials_inside_http_200() {
        $this->respond_with_fixture( 'vonage', 'invalid-credentials' );

        $result = ( new Vonage() )->send_result( '447700900000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '4', $result->get_error_code() );
        $this->assertSame( 'Bad Credentials', $result->get_error_message() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'vonage', 'invalid-number' );

        $result = ( new Vonage() )->send_result( 'nope', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '3', $result->get_error_code() );
    }

    public function test_server_error_with_html_body() {
        $this->respond_with_fixture( 'vonage', 'server-error' );

        $result = ( new Vonage() )->send_result( '447700900000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'vonage_error', $result->get_error_code() );
        $this->assertSame( 503, $result->get_http_status() );
        $this->assertIsString( $result->get_raw_response() );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'vonage', 'validate-ok' );

        $creds = ( new Vonage() )->validate(
            $this->validate_request(
                'vonage',
                [
                    'key'    => 'abcd1234',
                    'secret' => 'vonage-secret-1234',
                    'from'   => 'Texty',
                ]
            )
        );

        $this->assertSame( 'abcd1234', $creds['key'] );
        $this->assert_request(
            'GET',
            'https://rest.nexmo.com/account/get-balance?api_key=abcd1234&api_secret=vonage-secret-1234'
        );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'vonage', 'validate-fail' );

        $result = ( new Vonage() )->validate(
            $this->validate_request( 'vonage', [ 'key' => 'abcd1234', 'secret' => 'wrong-secret' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'authentication failed', $result->get_error_message() );
    }
}
