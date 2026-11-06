<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\ClickSend;
use Texty\Gateways\Result;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\ClickSend
 */
class ClickSendTest extends GatewayTestCase {

    const USERNAME = 'texty-user';
    const API_KEY  = 'clicksend-api-key-1234';
    const SEND_URL = 'https://rest.clicksend.com/v3/sms/send';
    const AUTH_URL = 'https://rest.clicksend.com/v3/account';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'clicksend',
            [
                'username' => self::USERNAME,
                'api_key'  => self::API_KEY,
                'from'     => 'sendmobile',
            ]
        );
    }

    /**
     * Expected Basic auth header value
     *
     * @return string
     */
    private function basic_auth() {
        return 'Basic ' . base64_encode( self::USERNAME . ':' . self::API_KEY ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'clicksend', 'success' );

        $result = ( new ClickSend() )->send_result( '61411111111', 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'BF7AD270-0DE2-418B-B606-71D527D9C1AE', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', $this->basic_auth() );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'messages' => [
                    [
                        'to'     => '+61411111111',
                        'body'   => 'Hello from Texty',
                        'source' => 'texty',
                        'from'   => 'sendmobile',
                    ],
                ],
            ],
            $this->request_json()
        );
    }

    public function test_number_with_plus_is_not_doubled() {
        $this->respond_with_fixture( 'clicksend', 'success' );

        ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertSame( '+61411111111', $this->request_json()['messages'][0]['to'] );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'clicksend', 'success' );

        ( new ClickSend() )->send_result( '+61411111111', 'Hi', [ 'from' => 'TEXTY' ] );

        $this->assertSame( 'TEXTY', $this->request_json()['messages'][0]['from'] );
    }

    public function test_empty_sender_omits_from_key() {
        $this->set_credentials(
            'clicksend',
            [
                'username' => self::USERNAME,
                'api_key'  => self::API_KEY,
                'from'     => '',
            ]
        );
        $this->respond_with_fixture( 'clicksend', 'success' );

        ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertArrayNotHasKey( 'from', $this->request_json()['messages'][0] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'clicksend', 'invalid-credentials' );

        $result = ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'UNAUTHORIZED', $result->get_error_code() );
        $this->assertSame( 'Authorization failed.', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_rejected_message_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'clicksend', 'invalid-number' );

        $result = ( new ClickSend() )->send_result( '+1000', 'Hello from Texty' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'INVALID_RECIPIENT', $result->get_error_code() );
        $this->assertSame( 'The recipient phone number is invalid.', $result->get_error_message() );
        $this->assertSame( 200, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_insufficient_credit_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'clicksend', 'insufficient-credit' );

        $result = ( new ClickSend() )->send_result( '+61411111111', 'Hello from Texty' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'INSUFFICIENT_CREDIT', $result->get_error_code() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_success_status_without_message_id_is_a_failure() {
        $this->respond_with(
            200,
            [
                'http_code'     => 200,
                'response_code' => 'SUCCESS',
                'response_msg'  => 'Here are your data.',
                'data'          => [
                    'messages' => [
                        [
                            'status'     => 'SUCCESS',
                            'message_id' => '',
                        ],
                    ],
                ],
            ]
        );

        $result = ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'clicksend', 'server-error' );

        $result = ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'INTERNAL_SERVER_ERROR', $result->get_error_code() );
        $this->assertSame( 'Something went wrong. Please try again later.', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_server_error_with_non_json_body() {
        $this->respond_with_fixture( 'clicksend', 'server-error-html' );

        $result = ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'clicksend_error', $result->get_error_code() );
        $this->assertSame( 502, $result->get_http_status() );
        $this->assertIsString( $result->get_raw_response() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials(
            'clicksend',
            [
                'username' => self::USERNAME,
                'api_key'  => '',
            ]
        );

        $result = ( new ClickSend() )->send_result( '+61411111111', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'clicksend', 'validate-ok' );

        $creds = ( new ClickSend() )->validate(
            $this->validate_request(
                'clicksend',
                [
                    'username' => ' ' . self::USERNAME . ' ',
                    'api_key'  => self::API_KEY,
                    'from'     => '+61411111111',
                ]
            )
        );

        $this->assertSame(
            [
                'username' => self::USERNAME,
                'api_key'  => self::API_KEY,
                'from'     => '+61411111111',
            ],
            $creds
        );
        $this->assert_request( 'GET', self::AUTH_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', $this->basic_auth() );
        $this->assert_request_header( 'Content-Type', 'application/json' );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'clicksend', 'validate-fail' );

        $result = ( new ClickSend() )->validate(
            $this->validate_request(
                'clicksend',
                [
                    'username' => self::USERNAME,
                    'api_key'  => 'wrong-key-5678',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'UNAUTHORIZED', $result->get_error_code() );
        $this->assertSame( 'Authorization failed.', $result->get_error_message() );
    }

    public function test_validate_redacts_api_keys_from_error_data() {
        $this->respond_with_fixture( 'clicksend', 'validate-unexpected' );

        $result = ( new ClickSend() )->validate(
            $this->validate_request(
                'clicksend',
                [
                    'username' => self::USERNAME,
                    'api_key'  => self::API_KEY,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'clicksend_auth_failed', $result->get_error_code() );

        $data = wp_json_encode( $result->get_error_data() );

        $this->assertStringNotContainsString( 'IJVEGTCF-VOHU-GSVF-KNKK-XHTARJXMQTXK', $data );
        $this->assertStringNotContainsString( self::API_KEY, $data );
        $this->assertStringContainsString( '[REDACTED]', $data );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new ClickSend() )->validate(
            $this->validate_request(
                'clicksend',
                [
                    'username' => self::USERNAME,
                    'api_key'  => self::API_KEY,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_rejects_invalid_sender_without_calling_the_api() {
        $result = ( new ClickSend() )->validate(
            $this->validate_request(
                'clicksend',
                [
                    'username' => self::USERNAME,
                    'api_key'  => self::API_KEY,
                    'from'     => 'TooLongSender1',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_requires_username_and_api_key() {
        $result = ( new ClickSend() )->validate( $this->validate_request( 'clicksend', [ 'username' => self::USERNAME ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_api_key_is_a_password_field() {
        $settings = ( new ClickSend() )->get_settings();

        $this->assertSame( [ 'username', 'api_key', 'from' ], array_keys( $settings ) );
        $this->assertSame( 'password', $settings['api_key']['type'] );
        $this->assertFalse( $settings['from']['required'] );
        $this->assertTrue( $settings['from']['alphanumeric'] );
        $this->assertSame( [], ( new ClickSend() )->metadata()['regions'] );
    }
}
