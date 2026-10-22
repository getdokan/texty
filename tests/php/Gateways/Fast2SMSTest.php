<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Fast2SMS;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Fast2SMS
 */
class Fast2SMSTest extends GatewayTestCase {

    const SEND_URL   = 'https://www.fast2sms.com/dev/bulkV2';
    const WALLET_URL = 'https://www.fast2sms.com/dev/wallet';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'fast2sms',
            [
                'api_key'   => 'f2s-key-1234',
                'route'     => 'q',
                'sender_id' => 'TEXTYS',
            ]
        );
    }

    /**
     * Switch the saved route to DLT
     */
    private function use_dlt_route() {
        $this->set_credentials(
            'fast2sms',
            [
                'api_key'   => 'f2s-key-1234',
                'route'     => 'dlt',
                'sender_id' => 'TEXTYS',
            ]
        );
    }

    public function test_successful_quick_send() {
        $this->respond_with_fixture( 'fast2sms', 'success' );

        $result = ( new Fast2SMS() )->send_result( '+919999999999', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'lwdtp7cjyqxvfe9', $result->get_reference_id() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'f2s-key-1234' );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'route'   => 'q',
                'message' => 'Hello',
                'numbers' => '9999999999',
            ],
            $this->request_json()
        );
    }

    public function test_quick_route_ignores_from_option() {
        $this->respond_with_fixture( 'fast2sms', 'success' );

        ( new Fast2SMS() )->send_result( '9999999999', 'Hello', [ 'from' => 'OTHERS' ] );

        $this->assertArrayNotHasKey( 'sender_id', $this->request_json() );
    }

    public function test_route_defaults_to_quick_when_unset() {
        $this->set_credentials( 'fast2sms', [ 'api_key' => 'f2s-key-1234' ] );
        $this->respond_with_fixture( 'fast2sms', 'success' );

        ( new Fast2SMS() )->send_result( '09999999999', 'Hello' );

        $body = $this->request_json();

        $this->assertSame( 'q', $body['route'] );
        $this->assertSame( '9999999999', $body['numbers'] );
    }

    public function test_successful_dlt_send() {
        $this->use_dlt_route();
        $this->respond_with_fixture( 'fast2sms', 'success' );

        $result = ( new Fast2SMS() )->send_result(
            '+919999999999',
            'Ignored on DLT',
            [
                'template_id' => '111111',
                'variables'   => [
                    'name'  => 'a',
                    'order' => 'b',
                ],
            ]
        );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'lwdtp7cjyqxvfe9', $result->get_reference_id() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'f2s-key-1234' );
        $this->assertSame(
            [
                'route'            => 'dlt',
                'sender_id'        => 'TEXTYS',
                'message'          => '111111',
                'numbers'          => '9999999999',
                'variables_values' => 'a|b',
            ],
            $this->request_json()
        );
    }

    public function test_dlt_from_option_overrides_sender_id() {
        $this->use_dlt_route();
        $this->respond_with_fixture( 'fast2sms', 'success' );

        ( new Fast2SMS() )->send_result(
            '9999999999',
            'Hello',
            [
                'from'        => 'OTHERS',
                'template_id' => '111111',
            ]
        );

        $body = $this->request_json();

        $this->assertSame( 'OTHERS', $body['sender_id'] );
        $this->assertArrayNotHasKey( 'variables_values', $body );
    }

    public function test_dlt_without_template_id_makes_no_request() {
        $this->use_dlt_route();

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_template_required', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_dlt_variable_with_pipe_makes_no_request() {
        $this->use_dlt_route();

        $result = ( new Fast2SMS() )->send_result(
            '9999999999',
            'Hello',
            [
                'template_id' => '111111',
                'variables'   => [ 'a|b' ],
            ]
        );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_invalid_variable', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_dlt_invalid_message_id() {
        $this->use_dlt_route();
        $this->respond_with_fixture( 'fast2sms', 'template-invalid' );

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello', [ 'template_id' => '999' ] );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_424', $result->get_error_code() );
        $this->assertSame( 'Invalid Message ID', $result->get_error_message() );
    }

    public function test_non_indian_number_makes_no_request() {
        $result = ( new Fast2SMS() )->send_result( '+15558675310', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_invalid_number', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'fast2sms', 'invalid-credentials' );

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_412', $result->get_error_code() );
        $this->assertSame( 'Invalid Authentication, Check Authorization Key', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_number_from_provider() {
        $this->respond_with_fixture( 'fast2sms', 'invalid-number' );

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_411', $result->get_error_code() );
        $this->assertSame( 'Invalid Numbers', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_insufficient_balance() {
        $this->respond_with_fixture( 'fast2sms', 'insufficient-balance' );

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_416', $result->get_error_code() );
    }

    public function test_return_false_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'fast2sms', 'rejected-in-200' );

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_995', $result->get_error_code() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'fast2sms', 'server-error' );

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'fast2sms_http_503', $result->get_error_code() );
        $this->assertSame( 503, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'fast2sms', [ 'api_key' => '' ] );

        $result = ( new Fast2SMS() )->send_result( '9999999999', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_ok() {
        $this->respond_with_fixture( 'fast2sms', 'validate-ok' );

        $creds = ( new Fast2SMS() )->validate(
            $this->validate_request(
                'fast2sms',
                [
                    'api_key'   => ' f2s-key-1234 ',
                    'route'     => 'DLT',
                    'sender_id' => 'TEXTYS',
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'   => 'f2s-key-1234',
                'route'     => 'dlt',
                'sender_id' => 'TEXTYS',
            ],
            $creds
        );

        $this->assert_request( 'POST', self::WALLET_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'f2s-key-1234' );
        $this->assertNull( $this->last_request()['body'] );
    }

    public function test_validate_fail() {
        $this->respond_with_fixture( 'fast2sms', 'validate-fail' );

        $result = ( new Fast2SMS() )->validate(
            $this->validate_request( 'fast2sms', [ 'api_key' => 'wrong-key' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'fast2sms_412', $result->get_error_code() );
        $this->assertSame( 'Invalid Authentication, Check Authorization Key', $result->get_error_message() );
    }

    public function test_validate_rejects_missing_key() {
        $result = ( new Fast2SMS() )->validate( $this->validate_request( 'fast2sms', [ 'api_key' => '' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_unknown_route() {
        $result = ( new Fast2SMS() )->validate(
            $this->validate_request(
                'fast2sms',
                [
                    'api_key' => 'f2s-key-1234',
                    'route'   => 'flash',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'fast2sms_invalid_route', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_bad_sender_id_on_dlt() {
        $result = ( new Fast2SMS() )->validate(
            $this->validate_request(
                'fast2sms',
                [
                    'api_key'   => 'f2s-key-1234',
                    'route'     => 'dlt',
                    'sender_id' => 'AB1',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'fast2sms_invalid_sender_id', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }
}
