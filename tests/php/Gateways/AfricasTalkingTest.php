<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\AfricasTalking;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\AfricasTalking
 */
class AfricasTalkingTest extends GatewayTestCase {

    const USERNAME    = 'myapp';
    const API_KEY     = 'atsk_live_key_1234567890';
    const LIVE_URL    = 'https://api.africastalking.com/version1/messaging';
    const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'africastalking',
            [
                'username'    => self::USERNAME,
                'api_key'     => self::API_KEY,
                'sender_id'   => 'MYSHOP',
                'environment' => 'live',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'africastalking', 'success' );

        $result = ( new AfricasTalking() )->send_result( '+254 705-336634', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'ATXid_03cb5ecf11ce748794c0f9a85c276259', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', self::LIVE_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'apiKey', self::API_KEY );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assert_request_header( 'Content-Type', 'application/x-www-form-urlencoded' );
        $this->assertSame(
            [
                'username' => self::USERNAME,
                'to'       => '+254705336634',
                'message'  => 'Hello',
                'from'     => 'MYSHOP',
            ],
            $this->request_form()
        );
        $this->assert_all_responses_used();
    }

    public function test_number_without_plus_is_normalized() {
        $this->respond_with_fixture( 'africastalking', 'success' );

        ( new AfricasTalking() )->send_result( '254705336634', 'Hello' );

        $this->assertSame( '+254705336634', $this->request_form()['to'] );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'africastalking', 'success' );

        ( new AfricasTalking() )->send_result( '+254705336634', 'Hello', [ 'from' => 'PROMO' ] );

        $this->assertSame( 'PROMO', $this->request_form()['from'] );
    }

    public function test_from_is_omitted_when_no_sender_is_set() {
        $this->set_credentials(
            'africastalking',
            [
                'username'    => self::USERNAME,
                'api_key'     => self::API_KEY,
                'sender_id'   => '',
                'environment' => 'live',
            ]
        );
        $this->respond_with_fixture( 'africastalking', 'success' );

        ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertArrayNotHasKey( 'from', $this->request_form() );
    }

    public function test_sandbox_environment_uses_sandbox_host() {
        $this->set_credentials(
            'africastalking',
            [
                'username'    => 'sandbox',
                'api_key'     => self::API_KEY,
                'sender_id'   => '',
                'environment' => 'sandbox',
            ]
        );
        $this->respond_with_fixture( 'africastalking', 'success' );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assert_request( 'POST', self::SANDBOX_URL );
        $this->assertSame( 'sandbox', $this->request_form()['username'] );
    }

    public function test_invalid_credentials_with_plain_text_body() {
        $this->respond_with_fixture( 'africastalking', 'invalid-credentials' );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'africastalking_http_401', $result->get_error_code() );
        $this->assertSame( 'The supplied authentication is invalid', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertSame( 'The supplied authentication is invalid', $result->get_raw_response() );
    }

    public function test_invalid_number_inside_201_is_a_failure() {
        $this->respond_with_fixture( 'africastalking', 'invalid-number' );

        $result = ( new AfricasTalking() )->send_result( '+2547', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'africastalking_403', $result->get_error_code() );
        $this->assertSame( 'InvalidPhoneNumber', $result->get_error_message() );
        $this->assertSame( 201, $result->get_http_status() );
    }

    public function test_insufficient_balance_inside_201_is_a_failure() {
        $this->respond_with_fixture( 'africastalking', 'insufficient-balance' );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'africastalking_405', $result->get_error_code() );
        $this->assertSame( 'InsufficientBalance', $result->get_error_message() );
    }

    public function test_empty_recipients_is_a_failure() {
        $this->respond_with_fixture( 'africastalking', 'no-recipients' );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'africastalking_rejected', $result->get_error_code() );
        $this->assertSame( 'InvalidSenderId', $result->get_error_message() );
    }

    public function test_accepted_status_without_message_id_is_a_failure() {
        $this->respond_with(
            201,
            [
                'SMSMessageData' => [
                    'Message'    => 'Sent to 1/1 Total Cost: KES 0.8000',
                    'Recipients' => [
                        [
                            'statusCode' => 101,
                            'number'     => '+254705336634',
                            'status'     => 'Success',
                            'messageId'  => 'None',
                        ],
                    ],
                ],
            ]
        );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'africastalking', 'server-error' );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'africastalking_http_500', $result->get_error_code() );
        $this->assertSame( 500, $result->get_http_status() );
        $this->assertSame( 'Internal Server Error', $result->get_raw_response() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_api_key_never_appears_in_result() {
        $this->respond_with_fixture( 'africastalking', 'invalid-credentials' );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertStringNotContainsString( self::API_KEY, (string) wp_json_encode( $result->to_array() ) );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'africastalking', [ 'username' => self::USERNAME ] );

        $result = ( new AfricasTalking() )->send_result( '+254705336634', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'africastalking', 'validate-ok' );

        $creds = ( new AfricasTalking() )->validate(
            $this->validate_request(
                'africastalking',
                [
                    'username'    => ' ' . self::USERNAME . ' ',
                    'api_key'     => self::API_KEY,
                    'sender_id'   => 'MYSHOP',
                    'environment' => 'live',
                ]
            )
        );

        $this->assertSame(
            [
                'username'    => self::USERNAME,
                'api_key'     => self::API_KEY,
                'sender_id'   => 'MYSHOP',
                'environment' => 'live',
            ],
            $creds
        );
        $this->assert_request( 'GET', 'https://api.africastalking.com/version1/user?username=myapp' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'apiKey', self::API_KEY );
        $this->assert_request_header( 'Accept', 'application/json' );
    }

    public function test_validate_sandbox_uses_sandbox_host() {
        $this->respond_with_fixture( 'africastalking', 'validate-ok' );

        $creds = ( new AfricasTalking() )->validate(
            $this->validate_request(
                'africastalking',
                [
                    'username'    => 'sandbox',
                    'api_key'     => self::API_KEY,
                    'sender_id'   => '',
                    'environment' => 'sandbox',
                ]
            )
        );

        $this->assertIsArray( $creds );
        $this->assertSame( 'sandbox', $creds['environment'] );
        $this->assert_request( 'GET', 'https://api.sandbox.africastalking.com/version1/user?username=sandbox' );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'africastalking', 'validate-fail' );

        $result = ( new AfricasTalking() )->validate(
            $this->validate_request(
                'africastalking',
                [
                    'username'    => self::USERNAME,
                    'api_key'     => 'wrong-key',
                    'environment' => 'live',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'africastalking_http_401', $result->get_error_code() );
        $this->assertSame( 'The supplied authentication is invalid', $result->get_error_message() );
        $this->assertSame( [ 'errorMessage' => 'The supplied authentication is invalid' ], $result->get_error_data() );
    }

    public function test_validate_rejects_missing_credentials() {
        $result = ( new AfricasTalking() )->validate(
            $this->validate_request( 'africastalking', [ 'username' => self::USERNAME, 'api_key' => '' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_non_sandbox_username_in_sandbox() {
        $result = ( new AfricasTalking() )->validate(
            $this->validate_request(
                'africastalking',
                [
                    'username'    => self::USERNAME,
                    'api_key'     => self::API_KEY,
                    'environment' => 'sandbox',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_username', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_unknown_environment() {
        $result = ( new AfricasTalking() )->validate(
            $this->validate_request(
                'africastalking',
                [
                    'username'    => self::USERNAME,
                    'api_key'     => self::API_KEY,
                    'environment' => 'staging',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_environment', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new AfricasTalking() )->validate(
            $this->validate_request(
                'africastalking',
                [
                    'username'    => self::USERNAME,
                    'api_key'     => self::API_KEY,
                    'environment' => 'live',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }
}
