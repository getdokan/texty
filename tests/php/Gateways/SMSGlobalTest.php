<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Metadata;
use Texty\Gateways\SMSGlobal;
use Texty\Http\MacAuth;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\SMSGlobal
 */
class SMSGlobalTest extends GatewayTestCase {

    const KEY         = 'key12345';
    const SECRET      = 'secret12345';
    const SEND_URL    = 'https://api.smsglobal.com/v2/sms';
    const BALANCE_URL = 'https://api.smsglobal.com/v2/user/credit-balance';
    const HEADER_RE   = '/^MAC id="([^"]+)", ts="(\d+)", nonce="([^"]{1,32})", mac="([A-Za-z0-9+\/]+=*)"$/';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'smsglobal',
            [
                'api_key'    => self::KEY,
                'api_secret' => self::SECRET,
                'origin'     => 'MyBrand',
            ]
        );
    }

    /**
     * Assert the last request carries a well-formed MAC header whose
     * signature matches the method and URL actually requested.
     *
     * @param string $method
     * @param string $url
     *
     * @return void
     */
    private function assert_mac_header( $method, $url ) {
        $headers = array_change_key_case( $this->last_request()['headers'], CASE_LOWER );

        $this->assertArrayHasKey( 'authorization', $headers );
        $this->assertSame( 1, preg_match( self::HEADER_RE, $headers['authorization'], $m ), 'Malformed MAC header: ' . $headers['authorization'] );
        $this->assertSame( self::KEY, $m[1] );
        $this->assertEqualsWithDelta( time(), (int) $m[2], 60 );

        $expected = ( new MacAuth( self::KEY, self::SECRET, (int) $m[2], $m[3] ) )->header( $method, $url );

        $this->assertSame( $expected, $headers['authorization'] );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'smsglobal', 'success' );

        $result = ( new SMSGlobal() )->send_result( '+61 400 000 000', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '5211897953', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_mac_header( 'POST', self::SEND_URL );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assertSame(
            [
                'destination' => '61400000000',
                'message'     => 'Hello',
                'origin'      => 'MyBrand',
            ],
            $this->request_json()
        );
    }

    public function test_successful_send_with_unwrapped_body() {
        $this->respond_with_fixture( 'smsglobal', 'success-unwrapped' );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Test sms from node sdk' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '5211897953', $result->get_reference_id() );
    }

    public function test_falls_back_to_part_id_without_outgoing_id() {
        $this->respond_with( 202, '{"messages":[{"id":6359736682344313,"status":"Processing"}]}', [ 'content-type' => 'application/json' ] );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '6359736682344313', $result->get_reference_id() );
    }

    public function test_from_option_overrides_saved_origin() {
        $this->respond_with_fixture( 'smsglobal', 'success' );

        ( new SMSGlobal() )->send_result( '61400000000', 'Hi', [ 'from' => 'TEXTY' ] );

        $this->assertSame( 'TEXTY', $this->request_json()['origin'] );
    }

    public function test_origin_is_omitted_when_empty() {
        $this->set_credentials(
            'smsglobal',
            [
                'api_key'    => self::KEY,
                'api_secret' => self::SECRET,
                'origin'     => '',
            ]
        );
        $this->respond_with_fixture( 'smsglobal', 'success' );

        ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertSame(
            [
                'destination' => '61400000000',
                'message'     => 'Hi',
            ],
            $this->request_json()
        );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'smsglobal', 'invalid-credentials' );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsglobal_invalid_credentials', $result->get_error_code() );
        $this->assertStringContainsString( 'Unknown Authentication Error', $result->get_error_message() );
        $this->assertStringContainsString( 'clock', $result->get_error_message() );
        $this->assertSame( 403, $result->get_http_status() );
        $this->assertSame( 'Unknown Authentication Error', $result->get_raw_response() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_malformed_auth_header_error() {
        $this->respond_with_fixture( 'smsglobal', 'missing-auth' );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertSame( 'smsglobal_invalid_credentials', $result->get_error_code() );
        $this->assertStringContainsString( 'Authorization header is empty or format is incorrect.', $result->get_error_message() );
    }

    public function test_401_is_treated_as_invalid_credentials() {
        $this->respond_with( 401, 'Unauthorized', [ 'content-type' => 'text/plain' ] );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertSame( 'smsglobal_invalid_credentials', $result->get_error_code() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_phone_number_rejected_by_provider() {
        $this->respond_with_fixture( 'smsglobal', 'invalid-number' );

        $result = ( new SMSGlobal() )->send_result( '999999999999999', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsglobal_400', $result->get_error_code() );
        $this->assertSame( 'Bad Request', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_invalid_phone_number_rejected_locally() {
        $result = ( new SMSGlobal() )->send_result( '+12', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsglobal_invalid_number', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_field_validation_error() {
        $this->respond_with_fixture( 'smsglobal', 'invalid-origin' );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsglobal_origin', $result->get_error_code() );
        $this->assertSame( 'Origin is invalid.', $result->get_error_message() );
    }

    public function test_insufficient_credit() {
        $this->respond_with_fixture( 'smsglobal', 'insufficient-credit' );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsglobal_insufficient_credit', $result->get_error_code() );
        $this->assertSame( 'Payment Required', $result->get_error_message() );
        $this->assertSame( 402, $result->get_http_status() );
    }

    public function test_failed_status_inside_2xx_is_a_failure() {
        $this->respond_with(
            200,
            [
                'messages' => [
                    [
                        'id'          => '1',
                        'outgoing_id' => 2,
                        'status'      => 'Failed',
                    ],
                ],
            ]
        );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsglobal_message_failed', $result->get_error_code() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_2xx_without_message_id_is_a_failure() {
        $this->respond_with( 202, [ 'messages' => [] ] );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'smsglobal', 'server-error' );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'smsglobal_server_error', $result->get_error_code() );
        $this->assertSame( 'Internal Server Error', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials(
            'smsglobal',
            [
                'api_key'    => self::KEY,
                'api_secret' => '',
            ]
        );

        $result = ( new SMSGlobal() )->send_result( '61400000000', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'smsglobal', 'validate-ok' );

        $creds = ( new SMSGlobal() )->validate(
            $this->validate_request(
                'smsglobal',
                [
                    'api_key'    => ' ' . self::KEY . ' ',
                    'api_secret' => self::SECRET,
                    'origin'     => 'MyBrand',
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'    => self::KEY,
                'api_secret' => self::SECRET,
                'origin'     => 'MyBrand',
            ],
            $creds
        );
        $this->assert_request( 'GET', self::BALANCE_URL );
        $this->assert_request_is_safe();
        $this->assert_mac_header( 'GET', self::BALANCE_URL );
        $this->assert_request_header( 'Accept', 'application/json' );
    }

    public function test_validate_accepts_postpaid_account() {
        $this->respond_with_fixture( 'smsglobal', 'validate-postpaid' );

        $creds = ( new SMSGlobal() )->validate(
            $this->validate_request(
                'smsglobal',
                [
                    'api_key'    => self::KEY,
                    'api_secret' => self::SECRET,
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'    => self::KEY,
                'api_secret' => self::SECRET,
                'origin'     => '',
            ],
            $creds
        );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'smsglobal', 'validate-fail' );

        $result = ( new SMSGlobal() )->validate(
            $this->validate_request(
                'smsglobal',
                [
                    'api_key'    => self::KEY,
                    'api_secret' => 'wrong',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'smsglobal_invalid_credentials', $result->get_error_code() );
        $this->assertSame( 'Unknown Authentication Error', $result->get_error_data() );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new SMSGlobal() )->validate(
            $this->validate_request(
                'smsglobal',
                [
                    'api_key'    => self::KEY,
                    'api_secret' => self::SECRET,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_key_and_secret() {
        $result = ( new SMSGlobal() )->validate(
            $this->validate_request(
                'smsglobal',
                [
                    'api_key'    => '',
                    'api_secret' => self::SECRET,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_badly_formatted_origin() {
        $result = ( new SMSGlobal() )->validate(
            $this->validate_request(
                'smsglobal',
                [
                    'api_key'    => self::KEY,
                    'api_secret' => self::SECRET,
                    'origin'     => 'WAY-TOO-LONG-SENDER',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'smsglobal_invalid_origin', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_secrets_are_password_fields_and_metadata_is_beta() {
        $gateway  = new SMSGlobal();
        $settings = $gateway->get_settings();
        $meta     = $gateway->metadata();

        $this->assertSame( 'password', $settings['api_key']['type'] );
        $this->assertSame( 'password', $settings['api_secret']['type'] );
        $this->assertSame( 'text', $settings['origin']['type'] );
        $this->assertFalse( $settings['origin']['required'] );
        $this->assertSame( Metadata::STATUS_BETA, $meta['status'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertTrue( $meta['features']['balance_check'] );
        $this->assertFalse( $meta['features']['template'] );
    }
}
