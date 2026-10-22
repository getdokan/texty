<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\BulkGate;
use Texty\Gateways\Metadata;
use Texty\Gateways\Result;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\BulkGate
 */
class BulkGateTest extends GatewayTestCase {

    const APP_ID    = '451';
    const TOKEN     = 'bulkgate-test-token-1234';
    const SEND_URL  = 'https://portal.bulkgate.com/api/1.0/simple/transactional';
    const INFO_URL  = 'https://portal.bulkgate.com/api/1.0/simple/info';
    const RECIPIENT = '+447700900000';

    public function set_up() {
        parent::set_up();

        $this->set_bulkgate_credentials( 'gText', 'MyShop' );
    }

    /**
     * Save BulkGate credentials with the given sender settings
     *
     * @param string $sender_type
     * @param string $sender
     *
     * @return void
     */
    private function set_bulkgate_credentials( $sender_type, $sender ) {
        $this->set_credentials(
            'bulkgate',
            [
                'application_id'    => self::APP_ID,
                'application_token' => self::TOKEN,
                'sender_type'       => $sender_type,
                'sender'            => $sender,
            ]
        );
    }

    /**
     * Assert the last request carried no Authorization header
     *
     * @return void
     */
    private function assert_no_auth_header() {
        $headers = array_change_key_case( $this->last_request()['headers'], CASE_LOWER );

        $this->assertArrayNotHasKey( 'authorization', $headers );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'bulkgate', 'success' );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'tmpde1bcd4b1d1', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_no_auth_header();
        $this->assertSame(
            [
                'application_id'    => 451,
                'application_token' => self::TOKEN,
                'number'            => '447700900000',
                'text'              => 'Hello from Texty',
                'unicode'           => false,
                'sender_id'         => 'gText',
                'sender_id_value'   => 'MyShop',
            ],
            $this->request_json()
        );
        $this->assert_all_responses_used();
    }

    public function test_number_is_normalized_to_digits() {
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( '00 44 (7700) 900-000', 'Hi' );

        $this->assertSame( '447700900000', $this->request_json()['number'] );
    }

    public function test_system_sender_omits_sender_fields() {
        $this->set_bulkgate_credentials( 'gSystem', 'ignored' );
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $body = $this->request_json();

        $this->assertArrayNotHasKey( 'sender_id', $body );
        $this->assertArrayNotHasKey( 'sender_id_value', $body );
    }

    public function test_own_number_sender_is_sent_as_digits() {
        $this->set_bulkgate_credentials( 'gOwn', '+420 777 777 777' );
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $body = $this->request_json();

        $this->assertSame( 'gOwn', $body['sender_id'] );
        $this->assertSame( '420777777777', $body['sender_id_value'] );
    }

    public function test_profile_sender() {
        $this->set_bulkgate_credentials( 'gprofile', '423' );
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $body = $this->request_json();

        $this->assertSame( 'gProfile', $body['sender_id'] );
        $this->assertSame( '423', $body['sender_id_value'] );
    }

    public function test_from_option_overrides_saved_sender_value() {
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi', [ 'from' => 'TEXTY' ] );

        $body = $this->request_json();

        $this->assertSame( 'gText', $body['sender_id'] );
        $this->assertSame( 'TEXTY', $body['sender_id_value'] );
    }

    public function test_from_option_with_system_sender_infers_text_type() {
        $this->set_bulkgate_credentials( 'gSystem', '' );
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi', [ 'from' => 'TEXTY' ] );

        $body = $this->request_json();

        $this->assertSame( 'gText', $body['sender_id'] );
        $this->assertSame( 'TEXTY', $body['sender_id_value'] );
    }

    public function test_from_option_with_system_sender_infers_own_number_type() {
        $this->set_bulkgate_credentials( 'gSystem', '' );
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi', [ 'from' => '+420 777 777 777' ] );

        $body = $this->request_json();

        $this->assertSame( 'gOwn', $body['sender_id'] );
        $this->assertSame( '420777777777', $body['sender_id_value'] );
    }

    public function test_unicode_option_is_sent() {
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi', [ 'unicode' => true ] );

        $this->assertTrue( $this->request_json()['unicode'] );
    }

    public function test_non_gsm_message_switches_unicode_on() {
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, 'Привет' );

        $this->assertTrue( $this->request_json()['unicode'] );
    }

    public function test_gsm_message_with_extended_characters_stays_7bit() {
        $this->respond_with_fixture( 'bulkgate', 'success' );

        ( new BulkGate() )->send_result( self::RECIPIENT, "Total: €10 [paid] {ok} ~ | ^ \\ ÄÖÑÜ é\nThanks!" );

        $this->assertFalse( $this->request_json()['unicode'] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'bulkgate', 'invalid-credentials' );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unknown_identity', $result->get_error_code() );
        $this->assertSame( 'Unknown identity / unauthorized / empty application_id', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'bulkgate', 'invalid-number' );

        $result = ( new BulkGate() )->send_result( '123', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'invalid_phone_number', $result->get_error_code() );
        $this->assertSame( 'Invalid phone number', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_invalid_sender_appends_detail() {
        $this->set_bulkgate_credentials( 'gOwn', '420777777777' );
        $this->respond_with_fixture( 'bulkgate', 'invalid-sender' );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'invalid_sender', $result->get_error_code() );
        $this->assertSame( 'Own sender not validated (420777777777)', $result->get_error_message() );
    }

    public function test_2xx_with_other_status_is_a_failure() {
        $this->respond_with(
            200,
            [
                'data' => [
                    'status' => 'rejected',
                    'sms_id' => 'tmpde1bcd4b1d1',
                    'number' => '447700900000',
                ],
            ]
        );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bulkgate_not_accepted', $result->get_error_code() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_2xx_without_data_is_a_failure() {
        $this->respond_with( 200, [ 'foo' => 'bar' ] );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '200', $result->get_error_code() );
    }

    public function test_accepted_without_sms_id_is_a_failure() {
        $this->respond_with( 200, [ 'data' => [ 'status' => 'accepted' ] ] );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'bulkgate', 'server-error' );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '500', $result->get_error_code() );
        $this->assertSame( 500, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_transport_error_is_not_retried() {
        $this->respond_with_transport_error();

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials(
            'bulkgate',
            [
                'application_id'    => '',
                'application_token' => '',
            ]
        );

        $result = ( new BulkGate() )->send_result( self::RECIPIENT, 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_direct_send_returns_reference_id_for_logging() {
        $this->respond_with_fixture( 'bulkgate', 'success' );
        $this->respond_with_fixture( 'bulkgate', 'invalid-number' );

        $ok   = ( new BulkGate() )->send( self::RECIPIENT, 'Hi' );
        $fail = ( new BulkGate() )->send( '123', 'Hi' );

        $this->assertIsArray( $ok );
        $this->assertSame( 'tmpde1bcd4b1d1', $ok['reference_id'] );
        $this->assertWPError( $fail );
        $this->assertSame( 'invalid_phone_number', $fail->get_error_code() );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'bulkgate', 'validate-ok' );

        $creds = ( new BulkGate() )->validate(
            $this->validate_request(
                'bulkgate',
                [
                    'application_id'    => ' ' . self::APP_ID . ' ',
                    'application_token' => self::TOKEN,
                    'sender_type'       => 'gtext',
                    'sender'            => 'MyShop',
                ]
            )
        );

        $this->assertSame(
            [
                'application_id'    => self::APP_ID,
                'application_token' => self::TOKEN,
                'sender_type'       => 'gText',
                'sender'            => 'MyShop',
            ],
            $creds
        );

        $this->assert_request( 'POST', self::INFO_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_no_auth_header();
        $this->assertSame(
            [
                'application_id'    => 451,
                'application_token' => self::TOKEN,
            ],
            $this->request_json()
        );
    }

    public function test_validate_defaults_empty_sender_type_to_system() {
        $this->respond_with_fixture( 'bulkgate', 'validate-ok' );

        $creds = ( new BulkGate() )->validate(
            $this->validate_request(
                'bulkgate',
                [
                    'application_id'    => self::APP_ID,
                    'application_token' => self::TOKEN,
                ]
            )
        );

        $this->assertIsArray( $creds );
        $this->assertSame( 'gSystem', $creds['sender_type'] );
        $this->assertSame( '', $creds['sender'] );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'bulkgate', 'validate-fail' );

        $result = ( new BulkGate() )->validate(
            $this->validate_request(
                'bulkgate',
                [
                    'application_id'    => self::APP_ID,
                    'application_token' => 'wrong',
                    'sender_type'       => 'gSystem',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'bulkgate_unknown_identity', $result->get_error_code() );
        $this->assertStringContainsString( 'Unknown identity / unauthorized / empty application_id', $result->get_error_message() );
        $this->assertStringContainsString( 'allow-list', $result->get_error_message() );
        $this->assertSame( $this->load_fixture( 'bulkgate', 'validate-fail' )['body'], $result->get_error_data() );
    }

    public function test_validate_returns_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new BulkGate() )->validate(
            $this->validate_request(
                'bulkgate',
                [
                    'application_id'    => self::APP_ID,
                    'application_token' => self::TOKEN,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_id_and_token() {
        $result = ( new BulkGate() )->validate( $this->validate_request( 'bulkgate', [ 'application_id' => self::APP_ID ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    /**
     * @dataProvider invalid_format_provider
     *
     * @param array  $creds
     * @param string $code
     */
    public function test_validate_rejects_bad_formats_without_a_request( array $creds, $code ) {
        $creds = array_merge(
            [
                'application_id'    => self::APP_ID,
                'application_token' => self::TOKEN,
            ],
            $creds
        );

        $result = ( new BulkGate() )->validate( $this->validate_request( 'bulkgate', $creds ) );

        $this->assertWPError( $result );
        $this->assertSame( $code, $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    /**
     * Credential sets that fail the format checks
     *
     * @return array
     */
    public function invalid_format_provider() {
        return [
            'non-numeric application id' => [ [ 'application_id' => 'abc' ], 'bulkgate_invalid_application_id' ],
            'unknown sender type'        => [ [ 'sender_type' => 'gShort' ], 'bulkgate_invalid_sender_type' ],
            'text sender too long'       => [ [ 'sender_type' => 'gText', 'sender' => 'TwelveChars1' ], 'bulkgate_invalid_sender' ],
            'text sender empty'          => [ [ 'sender_type' => 'gText', 'sender' => '' ], 'bulkgate_invalid_sender' ],
            'own sender not a number'    => [ [ 'sender_type' => 'gOwn', 'sender' => 'MyShop' ], 'bulkgate_invalid_sender' ],
            'profile sender not numeric' => [ [ 'sender_type' => 'gProfile', 'sender' => 'profile:423' ], 'bulkgate_invalid_sender' ],
        ];
    }

    public function test_metadata() {
        $meta = ( new BulkGate() )->metadata();

        $this->assertSame( Metadata::STATUS_BETA, $meta['status'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertTrue( $meta['features']['sender_id'] );
        $this->assertFalse( $meta['features']['template'] );
        $this->assertTrue( $meta['features']['balance_check'] );
    }

    public function test_token_is_a_password_field() {
        $settings = ( new BulkGate() )->get_settings();

        $this->assertSame(
            [ 'application_id', 'application_token', 'sender_type', 'sender' ],
            array_keys( $settings )
        );
        $this->assertSame( 'password', $settings['application_token']['type'] );
        $this->assertSame( 'select', $settings['sender_type']['type'] );
        $this->assertSame(
            [ 'gSystem', 'gText', 'gOwn', 'gProfile' ],
            array_keys( $settings['sender_type']['options'] )
        );
        $this->assertSame( 'gText', $settings['sender_type']['value'] );
        $this->assertFalse( $settings['sender']['required'] );
        $this->assertArrayNotHasKey( 'required', $settings['application_id'] );
    }
}
