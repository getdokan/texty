<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Octopush;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Octopush
 */
class OctopushTest extends GatewayTestCase {

    const SEND_URL = 'https://api.octopush.com/v1/public/sms-campaign/send';

    const BALANCE_URL = 'https://api.octopush.com/v1/public/wallet/check-balance';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'octopush',
            [
                'api_login' => 'you@example.com',
                'api_key'   => 'octopush-key-1234',
                'sender'    => 'MyShop',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'octopush', 'success' );

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'sms_5f3fbce61266e', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'api-login', 'you@example.com' );
        $this->assert_request_header( 'api-key', 'octopush-key-1234' );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'recipients' => [
                    [ 'phone_number' => '+33600000001' ],
                ],
                'text'       => 'Hello',
                'type'       => 'sms_premium',
                'purpose'    => 'alert',
                'sender'     => 'MyShop',
            ],
            $this->request_json()
        );
        $this->assert_all_responses_used();
    }

    public function test_number_with_plus_and_spaces_is_normalized() {
        $this->respond_with_fixture( 'octopush', 'success' );

        ( new Octopush() )->send_result( '+33 6 00 00 00 01', 'Hello' );

        $this->assertSame( '+33600000001', $this->request_json()['recipients'][0]['phone_number'] );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'octopush', 'success' );

        ( new Octopush() )->send_result( '33600000001', 'Hello', [ 'from' => 'Promo' ] );

        $this->assertSame( 'Promo', $this->request_json()['sender'] );
    }

    public function test_sender_is_omitted_when_empty() {
        $this->set_credentials(
            'octopush',
            [
                'api_login' => 'you@example.com',
                'api_key'   => 'octopush-key-1234',
                'sender'    => '',
            ]
        );
        $this->respond_with_fixture( 'octopush', 'success' );

        ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertArrayNotHasKey( 'sender', $this->request_json() );
    }

    public function test_unicode_option_disables_text_optimization() {
        $this->respond_with_fixture( 'octopush', 'success' );

        ( new Octopush() )->send_result( '33600000001', 'Héllo ✓', [ 'unicode' => true ] );

        $body = $this->request_json();

        $this->assertArrayHasKey( 'auto_optimize_text', $body );
        $this->assertFalse( $body['auto_optimize_text'] );
        $this->assertSame( 'Héllo ✓', $body['text'] );
    }

    public function test_auto_optimize_text_is_omitted_without_unicode() {
        $this->respond_with_fixture( 'octopush', 'success' );

        ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertArrayNotHasKey( 'auto_optimize_text', $this->request_json() );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'octopush', 'invalid-credentials' );

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '401', (string) $result->get_error_code() );
        $this->assertSame( 'Authentication failure', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_number() {
        $this->respond_with_fixture( 'octopush', 'invalid-number' );

        $result = ( new Octopush() )->send_result( '123', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '181', $result->get_error_code() );
        $this->assertSame( 'Phone number invalid.', $result->get_error_message() );
    }

    public function test_stop_missing_is_a_failure() {
        $this->respond_with_fixture( 'octopush', 'stop-missing' );

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '121', $result->get_error_code() );
        $this->assertSame( 'Mention STOP is missing.', $result->get_error_message() );
    }

    public function test_zero_contacts_inside_201_is_a_failure() {
        $this->respond_with_fixture( 'octopush', 'no-contacts' );

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'octopush_no_valid_recipient', $result->get_error_code() );
    }

    public function test_201_without_ticket_is_a_failure() {
        $this->respond_with( 201, [ 'number_of_contacts' => 1 ] );

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
    }

    public function test_server_error_with_non_json_body() {
        $this->respond_with_fixture( 'octopush', 'server-error' );

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'octopush_http_500', $result->get_error_code() );
        $this->assertSame( 500, $result->get_http_status() );
        $this->assertSame( 'Internal Server Error', $result->get_raw_response() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'octopush', [ 'api_login' => 'you@example.com' ] );

        $result = ( new Octopush() )->send_result( '33600000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_ok() {
        $this->respond_with_fixture( 'octopush', 'validate-ok' );

        $creds = ( new Octopush() )->validate(
            $this->validate_request(
                'octopush',
                [
                    'api_login' => ' you@example.com ',
                    'api_key'   => ' octopush-key-1234 ',
                    'sender'    => 'MyShop',
                ]
            )
        );

        $this->assertSame(
            [
                'api_login' => 'you@example.com',
                'api_key'   => 'octopush-key-1234',
                'sender'    => 'MyShop',
            ],
            $creds
        );

        $this->assert_request( 'GET', self::BALANCE_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'api-login', 'you@example.com' );
        $this->assert_request_header( 'api-key', 'octopush-key-1234' );
        $this->assertNull( $this->last_request()['body'] );
    }

    public function test_validate_fail() {
        $this->respond_with_fixture( 'octopush', 'validate-fail' );

        $result = ( new Octopush() )->validate(
            $this->validate_request(
                'octopush',
                [
                    'api_login' => 'you@example.com',
                    'api_key'   => 'wrong',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( '401', (string) $result->get_error_code() );
        $this->assertSame( 'Authentication failure', $result->get_error_message() );
        $this->assertSame(
            [
                'code'    => 401,
                'message' => 'Authentication failure',
            ],
            $result->get_error_data()
        );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Octopush() )->validate(
            $this->validate_request(
                'octopush',
                [
                    'api_login' => 'you@example.com',
                    'api_key'   => 'octopush-key-1234',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_rejects_invalid_sender_without_request() {
        $result = ( new Octopush() )->validate(
            $this->validate_request(
                'octopush',
                [
                    'api_login' => 'you@example.com',
                    'api_key'   => 'octopush-key-1234',
                    'sender'    => 'AB',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'octopush_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_missing_credentials() {
        $result = ( new Octopush() )->validate(
            $this->validate_request(
                'octopush',
                [
                    'api_login' => 'you@example.com',
                    'api_key'   => '',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_settings_shape() {
        $settings = ( new Octopush() )->get_settings();

        $this->assertSame( [ 'api_login', 'api_key', 'sender' ], array_keys( $settings ) );
        $this->assertSame( 'password', $settings['api_key']['type'] );
        $this->assertFalse( $settings['sender']['required'] );
        $this->assertArrayNotHasKey( 'required', $settings['api_login'] );
        $this->assertArrayNotHasKey( 'required', $settings['api_key'] );
    }

    public function test_metadata() {
        $meta = ( new Octopush() )->metadata();

        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertSame( [ 'FR', 'EU' ], $meta['regions'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertTrue( $meta['features']['sender_id'] );
        $this->assertTrue( $meta['features']['balance_check'] );
        $this->assertFalse( $meta['features']['template'] );
    }
}
