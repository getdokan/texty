<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Sinch;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Sinch
 */
class SinchTest extends GatewayTestCase {

    const PLAN_ID = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    const TOKEN   = 'sinch-token-1234';
    const URL     = 'https://eu.sms.api.sinch.com/xms/v1/a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6/batches';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'sinch',
            [
                'service_plan_id' => self::PLAN_ID,
                'api_token'       => self::TOKEN,
                'region'          => 'eu',
                'from'            => '+15550100100',
            ]
        );
    }

    /**
     * Valid validate() input
     *
     * @return array
     */
    private function valid_creds() {
        return [
            'service_plan_id' => self::PLAN_ID,
            'api_token'       => self::TOKEN,
            'region'          => 'eu',
            'from'            => '+15550100100',
        ];
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'sinch', 'success' );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '01FC66621XXXXX119Z8PMV1QPQ', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', self::URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'from' => '+15550100100',
                'to'   => [ '+15551231234' ],
                'body' => 'Hello',
                'type' => 'mt_text',
            ],
            $this->request_json()
        );
    }

    public function test_recipient_is_normalized_to_e164() {
        $this->respond_with_fixture( 'sinch', 'success' );

        ( new Sinch() )->send_result( '+1 (555) 123-1234', 'Hello' );

        $this->assertSame( [ '+15551231234' ], $this->request_json()['to'] );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'sinch', 'success' );

        ( new Sinch() )->send_result( '15551231234', 'Hello', [ 'from' => 'MyShop' ] );

        $this->assertSame( 'MyShop', $this->request_json()['from'] );
    }

    public function test_from_is_omitted_when_empty() {
        $this->set_credentials(
            'sinch',
            [
                'service_plan_id' => self::PLAN_ID,
                'api_token'       => self::TOKEN,
                'region'          => 'eu',
                'from'            => '',
            ]
        );
        $this->respond_with_fixture( 'sinch', 'success' );

        ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertArrayNotHasKey( 'from', $this->request_json() );
    }

    public function test_missing_region_defaults_to_us() {
        $this->set_credentials(
            'sinch',
            [
                'service_plan_id' => self::PLAN_ID,
                'api_token'       => self::TOKEN,
            ]
        );
        $this->respond_with_fixture( 'sinch', 'success' );

        ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assert_request( 'POST', 'https://us.sms.api.sinch.com/xms/v1/' . self::PLAN_ID . '/batches' );
    }

    public function test_base_url_filter_overrides_host() {
        $filter = function () {
            return 'https://dedicated.example.com/';
        };
        add_filter( 'texty_gateway_base_url', $filter );

        $this->respond_with_fixture( 'sinch', 'success' );

        ( new Sinch() )->send_result( '15551231234', 'Hello' );

        remove_filter( 'texty_gateway_base_url', $filter );

        $this->assert_request( 'POST', 'https://dedicated.example.com/xms/v1/' . self::PLAN_ID . '/batches' );
    }

    public function test_invalid_credentials_with_empty_body() {
        $this->respond_with_fixture( 'sinch', 'invalid-credentials' );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'sinch_unauthorized', $result->get_error_code() );
        $this->assertSame(
            'Invalid Sinch API token or service plan ID. Check that the region matches the one your service plan was created in.',
            $result->get_error_message()
        );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_credentials_with_json_body() {
        $this->respond_with_fixture( 'sinch', 'invalid-credentials-json' );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unauthorized', $result->get_error_code() );
        $this->assertSame( 'Authentication token is invalid for this service plan.', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'sinch', 'invalid-number' );

        $result = ( new Sinch() )->send_result( '1', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'syntax_invalid_parameter_format', $result->get_error_code() );
        $this->assertSame(
            "The format of parameter 'to[0]' is invalid; value '+1' is not a valid MSISDN or group ID.",
            $result->get_error_message()
        );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_forbidden_maps_code_and_text() {
        $this->respond_with_fixture( 'sinch', 'forbidden' );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'missing_callback_url', $result->get_error_code() );
        $this->assertSame( 'Requesting delivery report without any callback URL.', $result->get_error_message() );
    }

    public function test_not_found_with_empty_body() {
        $this->respond_with_fixture( 'sinch', 'not-found' );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'sinch_not_found', $result->get_error_code() );
        $this->assertSame( 'Sinch service plan not found. Check the Service Plan ID and the region.', $result->get_error_message() );
        $this->assertSame( 404, $result->get_http_status() );
    }

    public function test_server_error_with_empty_body() {
        $this->respond_with_fixture( 'sinch', 'server-error' );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'sinch_error', $result->get_error_code() );
        $this->assertSame( 'Sinch returned an unexpected response (HTTP 500).', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_201_without_id_is_a_failure() {
        $this->respond_with( 201, [ 'canceled' => false ] );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_200_is_not_success() {
        $this->respond_with( 200, [ 'id' => '01FC66621XXXXX119Z8PMV1QPQ' ] );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'sinch', [ 'service_plan_id' => self::PLAN_ID ] );

        $result = ( new Sinch() )->send_result( '15551231234', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'sinch', 'validate-ok' );

        $input         = $this->valid_creds();
        $input['from'] = ' +1 555 010 0100 ';

        $creds = ( new Sinch() )->validate( $this->validate_request( 'sinch', $input ) );

        $this->assertSame( $this->valid_creds(), $creds );
        $this->assert_request( 'GET', self::URL . '?page_size=1' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );
    }

    public function test_validate_defaults_empty_region_to_us() {
        $this->respond_with_fixture( 'sinch', 'validate-ok' );

        $input           = $this->valid_creds();
        $input['region'] = '';

        $creds = ( new Sinch() )->validate( $this->validate_request( 'sinch', $input ) );

        $this->assertSame( 'us', $creds['region'] );
        $this->assert_request( 'GET', 'https://us.sms.api.sinch.com/xms/v1/' . self::PLAN_ID . '/batches?page_size=1' );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'sinch', 'validate-fail' );

        $input              = $this->valid_creds();
        $input['api_token'] = 'wrong-token';

        $result = ( new Sinch() )->validate( $this->validate_request( 'sinch', $input ) );

        $this->assertWPError( $result );
        $this->assertSame( 'sinch_unauthorized', $result->get_error_code() );
        $this->assert_request( 'GET', self::URL . '?page_size=1' );
    }

    public function test_validate_rejects_unknown_service_plan() {
        $this->respond_with_fixture( 'sinch', 'not-found' );

        $result = ( new Sinch() )->validate( $this->validate_request( 'sinch', $this->valid_creds() ) );

        $this->assertWPError( $result );
        $this->assertSame( 'sinch_not_found', $result->get_error_code() );
    }

    public function test_validate_rejects_invalid_region() {
        $input           = $this->valid_creds();
        $input['region'] = 'mars';

        $result = ( new Sinch() )->validate( $this->validate_request( 'sinch', $input ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_sinch_invalid_region', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_long_alphanumeric_sender() {
        $input         = $this->valid_creds();
        $input['from'] = 'MyVeryLongShop';

        $result = ( new Sinch() )->validate( $this->validate_request( 'sinch', $input ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_sinch_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Sinch() )->validate( $this->validate_request( 'sinch', $this->valid_creds() ) );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_rejects_missing_credentials() {
        $result = ( new Sinch() )->validate(
            $this->validate_request( 'sinch', [ 'service_plan_id' => '', 'api_token' => self::TOKEN ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_api_token_is_a_secret_field() {
        $settings = ( new Sinch() )->get_settings();

        $this->assertSame( 'password', $settings['api_token']['type'] );
        $this->assertSame( [ 'service_plan_id', 'api_token', 'region', 'from' ], array_keys( $settings ) );
    }

    public function test_region_is_a_select_and_from_is_optional_alphanumeric() {
        $settings = ( new Sinch() )->get_settings();

        $this->assertSame( 'select', $settings['region']['type'] );
        $this->assertSame( [ 'us', 'eu', 'au', 'br', 'ca' ], array_keys( $settings['region']['options'] ) );
        $this->assertSame( 'eu', $settings['region']['value'] );
        $this->assertFalse( $settings['from']['required'] );
        $this->assertTrue( $settings['from']['alphanumeric'] );
    }

    public function test_meta_lists_hosting_regions() {
        $meta = ( new Sinch() )->metadata();

        $this->assertSame( [ 'us', 'eu', 'au', 'br', 'ca' ], $meta['regions'] );
        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
    }
}
