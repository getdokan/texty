<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Bird;
use Texty\Gateways\Metadata;
use Texty\Gateways\Result;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Bird
 */
class BirdTest extends GatewayTestCase {

    const KEY    = 'bk_us1_testkey1234567890abcdef';
    const EU_KEY = 'bk_eu1_testkey1234567890abcdef';
    const FROM   = '+15557654321';
    const URL    = 'https://us1.platform.bird.com/v1/sms/messages';
    const EU_URL = 'https://eu1.platform.bird.com/v1/sms/messages';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'bird',
            [
                'api_key'  => self::KEY,
                'region'   => 'us1',
                'from'     => self::FROM,
                'category' => 'transactional',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'bird', 'success' );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'sms_01krdgeqcxet5s7t44vh8rt9mg', $result->get_reference_id() );
        $this->assertSame( 202, $result->get_http_status() );

        $this->assert_request( 'POST', self::URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::KEY );
        $this->assertArrayNotHasKey( 'x-workspace-id', array_change_key_case( $this->last_request()['headers'], CASE_LOWER ) );
        $this->assertSame(
            [
                'to'       => '+15551234567',
                'from'     => self::FROM,
                'text'     => 'Hello from Texty',
                'category' => 'transactional',
            ],
            $this->request_json()
        );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'bird', 'success' );

        ( new Bird() )->send_result( '+15551234567', 'Hi', [ 'from' => 'TEXTY' ] );

        $this->assertSame( 'TEXTY', $this->request_json()['from'] );
    }

    public function test_phone_number_is_normalized_to_e164() {
        $this->respond_with_fixture( 'bird', 'success' );

        ( new Bird() )->send_result( '1 (555) 123-4567', 'Hi' );

        $this->assertSame( '+15551234567', $this->request_json()['to'] );
    }

    public function test_saved_category_is_sent() {
        $this->set_credentials(
            'bird',
            [
                'api_key'  => self::KEY,
                'region'   => 'us1',
                'from'     => self::FROM,
                'category' => 'authentication',
            ]
        );
        $this->respond_with_fixture( 'bird', 'success' );

        ( new Bird() )->send_result( '+15551234567', 'Your code is 1234' );

        $this->assertSame( 'authentication', $this->request_json()['category'] );
    }

    public function test_eu_region_uses_eu_host() {
        $this->set_credentials(
            'bird',
            [
                'api_key'  => self::EU_KEY,
                'region'   => 'eu1',
                'from'     => self::FROM,
                'category' => 'transactional',
            ]
        );
        $this->respond_with_fixture( 'bird', 'success' );

        ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assert_request( 'POST', self::EU_URL );
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::EU_KEY );
    }

    public function test_region_falls_back_to_key_prefix_and_category_to_transactional() {
        $this->set_credentials(
            'bird',
            [
                'api_key' => self::EU_KEY,
                'from'    => self::FROM,
            ]
        );
        $this->respond_with_fixture( 'bird', 'success' );

        ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assert_request( 'POST', self::EU_URL );
        $this->assertSame( 'transactional', $this->request_json()['category'] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'bird', 'invalid-credentials' );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'E02072', $result->get_error_code() );
        $this->assertSame( 'This API key is not valid. (InvalidAPIKey)', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'bird', 'invalid-number' );

        $result = ( new Bird() )->send_result( '+1555', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'E00000', $result->get_error_code() );
        $this->assertSame(
            'The recipient is not a valid phone number. (SMSInvalidRecipient) to: not a routable phone number',
            $result->get_error_message()
        );
        $this->assertSame( 422, $result->get_http_status() );
    }

    public function test_validation_error_details_are_included() {
        $this->respond_with_fixture( 'bird', 'validation-error' );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'E01001', $result->get_error_code() );
        $this->assertSame(
            'Request validation failed. (ValidationError) contact_id: this field is reserved and not yet supported',
            $result->get_error_message()
        );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'bird', 'server-error' );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'E00500', $result->get_error_code() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_non_json_error_falls_back_to_http_status_code() {
        $this->respond_with_fixture( 'bird', 'server-error-html' );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bird_http_503', $result->get_error_code() );
        $this->assertNotSame( '', $result->get_error_message() );
        $this->assertSame( 503, $result->get_http_status() );
    }

    public function test_2xx_without_message_id_is_a_failure() {
        $this->respond_with( 202, [ 'status' => 'scheduled' ] );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bird_http_202', $result->get_error_code() );
    }

    public function test_non_202_status_with_id_is_a_failure() {
        $this->respond_with( 200, [ 'id' => 'sms_01krdgeqcxet5s7t44vh8rt9mg' ] );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'bird_http_200', $result->get_error_code() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials( 'bird', [ 'api_key' => '', 'from' => '' ] );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_missing_sender_never_calls_the_api() {
        $this->set_credentials( 'bird', [ 'api_key' => self::KEY, 'from' => '' ] );

        $result = ( new Bird() )->send_result( '+15551234567', 'Hi' );

        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'bird', 'validate-ok' );

        $creds = ( new Bird() )->validate(
            $this->validate_request(
                'bird',
                [
                    'api_key'  => self::KEY,
                    'region'   => 'us1',
                    'from'     => self::FROM,
                    'category' => 'marketing',
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'  => self::KEY,
                'region'   => 'us1',
                'from'     => self::FROM,
                'category' => 'marketing',
            ],
            $creds
        );
        $this->assert_request( 'GET', self::URL . '?limit=1' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::KEY );
    }

    public function test_validate_fills_region_from_key_and_default_category() {
        $this->respond_with_fixture( 'bird', 'validate-ok' );

        $creds = ( new Bird() )->validate(
            $this->validate_request(
                'bird',
                [
                    'api_key' => self::EU_KEY,
                    'from'    => 'Texty',
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'  => self::EU_KEY,
                'region'   => 'eu1',
                'from'     => 'Texty',
                'category' => 'transactional',
            ],
            $creds
        );
        $this->assert_request( 'GET', self::EU_URL . '?limit=1' );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'bird', 'validate-fail' );

        $result = ( new Bird() )->validate(
            $this->validate_request( 'bird', [ 'api_key' => self::KEY, 'from' => self::FROM ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'E02072', $result->get_error_code() );
        $this->assertSame( 'This API key is not valid. (InvalidAPIKey)', $result->get_error_message() );
        $this->assertSame( 'auth_error', $result->get_error_data()['error']['type'] );
    }

    public function test_validate_returns_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Bird() )->validate(
            $this->validate_request( 'bird', [ 'api_key' => self::KEY, 'from' => self::FROM ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_api_key_and_sender() {
        $result = ( new Bird() )->validate( $this->validate_request( 'bird', [ 'api_key' => '' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_non_workspace_key() {
        $result = ( new Bird() )->validate(
            $this->validate_request( 'bird', [ 'api_key' => 'bm_org_masterkey', 'from' => self::FROM ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'bird_invalid_key_format', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_region_mismatch() {
        $result = ( new Bird() )->validate(
            $this->validate_request(
                'bird',
                [
                    'api_key' => self::EU_KEY,
                    'region'  => 'us1',
                    'from'    => self::FROM,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'bird_region_mismatch', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_unknown_region() {
        $result = ( new Bird() )->validate(
            $this->validate_request(
                'bird',
                [
                    'api_key' => self::KEY,
                    'region'  => 'ap1',
                    'from'    => self::FROM,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'bird_invalid_region', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_bad_sender() {
        $result = ( new Bird() )->validate(
            $this->validate_request( 'bird', [ 'api_key' => self::KEY, 'from' => 'ThisSenderIsTooLong' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'bird_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_unknown_category() {
        $result = ( new Bird() )->validate(
            $this->validate_request(
                'bird',
                [
                    'api_key'  => self::KEY,
                    'from'     => self::FROM,
                    'category' => 'promo',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'bird_invalid_category', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_api_key_is_a_password_field() {
        $settings = ( new Bird() )->get_settings();

        $this->assertSame( [ 'api_key', 'region', 'from', 'category' ], array_keys( $settings ) );
        $this->assertSame( 'password', $settings['api_key']['type'] );
        $this->assertSame( self::KEY, $settings['api_key']['value'] );
        $this->assertSame( 'select', $settings['region']['type'] );
        $this->assertSame( [ 'us1', 'eu1' ], array_keys( $settings['region']['options'] ) );
        $this->assertSame( 'select', $settings['category']['type'] );
        $this->assertSame(
            [ 'transactional', 'marketing', 'authentication', 'service' ],
            array_keys( $settings['category']['options'] )
        );
        $this->assertTrue( $settings['from']['alphanumeric'] );
    }

    public function test_metadata() {
        $meta = ( new Bird() )->metadata();

        $this->assertSame( Metadata::STATUS_BETA, $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertTrue( $meta['features']['sender_id'] );
        $this->assertFalse( $meta['features']['template'] );
        $this->assertFalse( $meta['features']['balance_check'] );
    }

    public function test_direct_send_keeps_legacy_return_shape() {
        $this->respond_with_fixture( 'bird', 'success' );
        $this->respond_with_fixture( 'bird', 'invalid-number' );

        $ok   = ( new Bird() )->send( '+15551234567', 'Hi' );
        $fail = ( new Bird() )->send( '+1555', 'Hi' );

        $this->assertIsArray( $ok );
        $this->assertSame( 'sms_01krdgeqcxet5s7t44vh8rt9mg', $ok['reference_id'] );
        $this->assertWPError( $fail );
        $this->assertSame( 'E00000', $fail->get_error_code() );
    }
}
