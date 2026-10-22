<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Result;
use Texty\Gateways\Telnyx;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Telnyx
 */
class TelnyxTest extends GatewayTestCase {

    const KEY     = 'KEYtest-telnyx-1234';
    const FROM    = '+18445550001';
    const PROFILE = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    const URL     = 'https://api.telnyx.com/v2/messages';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'telnyx',
            [
                'api_key'              => self::KEY,
                'from'                 => self::FROM,
                'messaging_profile_id' => '',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'telnyx', 'success' );

        $result = ( new Telnyx() )->send_result( '+1 (866) 555-0001', 'Hello, World!' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( '40385f64-5717-4562-b3fc-2c963f66afa6', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::KEY );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assert_request_header( 'Content-Type', 'application/json' );

        $body = $this->request_json();

        $this->assertSame(
            [
                'to'   => '+18665550001',
                'from' => self::FROM,
                'text' => 'Hello, World!',
                'type' => 'SMS',
            ],
            $body
        );
        $this->assertArrayNotHasKey( 'encoding', $body );
        $this->assertArrayNotHasKey( 'api_key', $body );
        $this->assertArrayNotHasKey( 'messaging_profile_id', $body );
    }

    public function test_send_includes_messaging_profile_and_alphanumeric_sender() {
        $this->set_credentials(
            'telnyx',
            [
                'api_key'              => self::KEY,
                'from'                 => 'TEXTY',
                'messaging_profile_id' => self::PROFILE,
            ]
        );
        $this->respond_with_fixture( 'telnyx', 'success' );

        ( new Telnyx() )->send_result( '447700900123', 'Hi' );

        $this->assertSame(
            [
                'to'                   => '+447700900123',
                'from'                 => 'TEXTY',
                'messaging_profile_id' => self::PROFILE,
                'text'                 => 'Hi',
                'type'                 => 'SMS',
            ],
            $this->request_json()
        );
    }

    public function test_number_pool_sends_profile_without_from() {
        $this->set_credentials(
            'telnyx',
            [
                'api_key'              => self::KEY,
                'from'                 => '',
                'messaging_profile_id' => self::PROFILE,
            ]
        );
        $this->respond_with_fixture( 'telnyx', 'success' );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame(
            [
                'to'                   => '+18665550001',
                'messaging_profile_id' => self::PROFILE,
                'text'                 => 'Hi',
                'type'                 => 'SMS',
            ],
            $this->request_json()
        );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'telnyx', 'success' );

        ( new Telnyx() )->send_result( '+18665550001', 'Hi', [ 'from' => '15550009999' ] );

        $this->assertSame( '+15550009999', $this->request_json()['from'] );
    }

    public function test_unicode_option_sets_ucs2_encoding() {
        $this->respond_with_fixture( 'telnyx', 'success' );

        ( new Telnyx() )->send_result( '+18665550001', 'Hi', [ 'unicode' => true ] );

        $this->assertSame( 'ucs2', $this->request_json()['encoding'] );
    }

    public function test_template_options_are_ignored() {
        $this->respond_with_fixture( 'telnyx', 'success' );

        ( new Telnyx() )->send_result(
            '+18665550001',
            'Hi',
            [
                'template_id' => 'tpl-1',
                'variables'   => [ 'name' => 'Ada' ],
            ]
        );

        $body = $this->request_json();

        $this->assertArrayNotHasKey( 'template_id', $body );
        $this->assertArrayNotHasKey( 'variables', $body );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'telnyx', 'invalid-credentials' );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '10009', $result->get_error_code() );
        $this->assertSame( 'The required authentication headers were either invalid or not included in the request.', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'telnyx', 'invalid-number' );

        $result = ( new Telnyx() )->send_result( '123', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '40310', $result->get_error_code() );
        $this->assertSame( "The 'to' address should be a valid E.164 phone number or short code.", $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_unregistered_sender() {
        $this->respond_with_fixture( 'telnyx', 'unregistered-sender' );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '40010', $result->get_error_code() );
    }

    public function test_rejection_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'telnyx', 'rejected-in-200' );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '40012', $result->get_error_code() );
        $this->assertSame( 'The carrier rejected the destination number.', $result->get_error_message() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_200_without_message_id_is_a_failure() {
        $this->respond_with( 200, [ 'data' => [ 'errors' => [] ] ] );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'telnyx_http_200', $result->get_error_code() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'telnyx', 'server-error' );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '10037', $result->get_error_code() );
        $this->assertSame( 'Service is unavailable.', $result->get_error_message() );
        $this->assertSame( 503, $result->get_http_status() );
    }

    public function test_error_without_body_uses_http_status_code() {
        $this->respond_with( 502, '' );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'telnyx_http_502', $result->get_error_code() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_api_key_never_calls_the_api() {
        $this->set_credentials( 'telnyx', [ 'api_key' => '', 'from' => self::FROM ] );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_missing_sender_and_profile_never_calls_the_api() {
        $this->set_credentials( 'telnyx', [ 'api_key' => self::KEY ] );

        $result = ( new Telnyx() )->send_result( '+18665550001', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_key() {
        $this->respond_with_fixture( 'telnyx', 'validate-ok' );

        $creds = ( new Telnyx() )->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key' => ' ' . self::KEY . ' ',
                    'from'    => '18445550001',
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'              => self::KEY,
                'from'                 => self::FROM,
                'messaging_profile_id' => '',
            ],
            $creds
        );
        $this->assert_request( 'GET', 'https://api.telnyx.com/v2/balance' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::KEY );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assert_all_responses_used();
    }

    public function test_validate_checks_messaging_profile_when_set() {
        $this->respond_with_fixture( 'telnyx', 'validate-ok' );
        $this->respond_with_fixture( 'telnyx', 'profile-ok' );

        $creds = ( new Telnyx() )->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key'              => self::KEY,
                    'from'                 => 'TEXTY',
                    'messaging_profile_id' => self::PROFILE,
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'              => self::KEY,
                'from'                 => 'TEXTY',
                'messaging_profile_id' => self::PROFILE,
            ],
            $creds
        );
        $this->assertCount( 2, $this->requests );
        $this->assertSame( 'https://api.telnyx.com/v2/balance', $this->requests[0]['url'] );
        $this->assert_request( 'GET', 'https://api.telnyx.com/v2/messaging_profiles/' . self::PROFILE );
        $this->assert_all_responses_used();
    }

    public function test_validate_accepts_key_without_billing_scope() {
        $this->respond_with_fixture( 'telnyx', 'validate-forbidden' );
        $this->respond_with_fixture( 'telnyx', 'profile-ok' );

        $creds = ( new Telnyx() )->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key'              => self::KEY,
                    'messaging_profile_id' => self::PROFILE,
                ]
            )
        );

        $this->assertIsArray( $creds );
        $this->assertSame( self::PROFILE, $creds['messaging_profile_id'] );
        $this->assert_request( 'GET', 'https://api.telnyx.com/v2/messaging_profiles/' . self::PROFILE );
        $this->assert_all_responses_used();
    }

    public function test_validate_rejects_disabled_profile_without_leaking_secret() {
        $this->respond_with_fixture( 'telnyx', 'validate-ok' );
        $this->respond_with(
            200,
            [
                'data' => [
                    'record_type' => 'messaging_profile',
                    'id'          => self::PROFILE,
                    'enabled'     => false,
                    'v1_secret'   => 'dummy-v1-secret',
                ],
            ]
        );

        $result = ( new Telnyx() )->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key'              => self::KEY,
                    'messaging_profile_id' => self::PROFILE,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_messaging_profile_disabled', $result->get_error_code() );
        $this->assertEmpty( $result->get_error_data() );
    }

    public function test_validate_rejects_invalid_key() {
        $this->respond_with_fixture( 'telnyx', 'validate-fail' );

        $result = ( new Telnyx() )->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key' => 'wrong',
                    'from'    => self::FROM,
                ]
            )
        );

        $this->assertWPError( $result );
        // WP_Error keys its codes, so PHP turns numeric strings into ints.
        $this->assertSame( '10009', (string) $result->get_error_code() );
        $this->assertSame( '10009', $result->get_error_data()['errors'][0]['code'] );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Telnyx() )->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key' => self::KEY,
                    'from'    => self::FROM,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_api_key() {
        $result = ( new Telnyx() )->validate( $this->validate_request( 'telnyx', [ 'api_key' => '', 'from' => self::FROM ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_requires_sender_or_profile() {
        $result = ( new Telnyx() )->validate( $this->validate_request( 'telnyx', [ 'api_key' => self::KEY ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_requires_profile_for_alphanumeric_sender() {
        $result = ( new Telnyx() )->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key' => self::KEY,
                    'from'    => 'TEXTY',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_messaging_profile', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_malformed_sender_and_profile() {
        $gateway = new Telnyx();

        $sender = $gateway->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key' => self::KEY,
                    'from'    => 'TOO-LONG-SENDER-ID',
                ]
            )
        );

        $profile = $gateway->validate(
            $this->validate_request(
                'telnyx',
                [
                    'api_key'              => self::KEY,
                    'messaging_profile_id' => 'not-a-uuid',
                ]
            )
        );

        $this->assertWPError( $sender );
        $this->assertSame( 'texty_invalid_sender', $sender->get_error_code() );
        $this->assertWPError( $profile );
        $this->assertSame( 'texty_invalid_messaging_profile', $profile->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_metadata() {
        $meta = ( new Telnyx() )->metadata();

        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertTrue( $meta['features']['sender_id'] );
        $this->assertFalse( $meta['features']['template'] );
        $this->assertTrue( $meta['features']['balance_check'] );
    }

    public function test_settings_mark_api_key_as_password() {
        $settings = ( new Telnyx() )->get_settings();

        $this->assertSame( [ 'api_key', 'from', 'messaging_profile_id' ], array_keys( $settings ) );
        $this->assertSame( 'password', $settings['api_key']['type'] );
        $this->assertSame( self::KEY, $settings['api_key']['value'] );
        $this->assertArrayNotHasKey( 'required', $settings['api_key'] );
        $this->assertFalse( $settings['from']['required'] );
        $this->assertTrue( $settings['from']['alphanumeric'] );
        $this->assertFalse( $settings['messaging_profile_id']['required'] );
    }
}
