<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\MSG91;
use Texty\Gateways\Secrets;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\MSG91
 */
class MSG91Test extends GatewayTestCase {

    const AUTH_KEY    = 'msg91-auth-key-1234';
    const SENDER      = 'TEXTYS';
    const TEMPLATE    = '64f1c2a9d6fc05123b4e1a22';
    const SEND_URL    = 'https://control.msg91.com/api/v5/flow';
    const BALANCE_URL = 'https://control.msg91.com/api/balance.php?authkey=msg91-auth-key-1234&type=4';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'msg91',
            [
                'auth_key'  => self::AUTH_KEY,
                'sender_id' => self::SENDER,
            ]
        );
    }

    /**
     * Send options with a template
     *
     * @param array $extra
     *
     * @return array
     */
    private function options( array $extra = [] ) {
        return array_merge( [ 'template_id' => self::TEMPLATE ], $extra );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'msg91', 'success' );

        $result = ( new MSG91() )->send_result(
            '919876543210',
            'Hello',
            $this->options( [ 'variables' => [ 'name' => 'Rahul', 'order' => 1042 ] ] )
        );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '5e1e93cad6fc054d8e759a5b', $result->get_reference_id() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'authkey', self::AUTH_KEY );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assertSame(
            [
                'template_id' => self::TEMPLATE,
                'short_url'   => '0',
                'sender'      => self::SENDER,
                'recipients'  => [
                    [
                        'mobiles' => '919876543210',
                        'name'    => 'Rahul',
                        'order'   => '1042',
                    ],
                ],
            ],
            $this->request_json()
        );
    }

    public function test_from_and_unicode_options() {
        $this->respond_with_fixture( 'msg91', 'success' );

        ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options( [ 'from' => 'OTHERS', 'unicode' => true ] ) );

        $body = $this->request_json();

        $this->assertSame( 'OTHERS', $body['sender'] );
        $this->assertSame( 1, $body['unicode'] );
    }

    public function test_message_text_and_auth_key_are_not_in_the_body() {
        $this->respond_with_fixture( 'msg91', 'success' );

        $result = ( new MSG91() )->send_result( '919876543210', 'Secret text', $this->options() );

        $body = $this->last_request()['body'];

        $this->assertStringNotContainsString( 'Secret text', $body );
        $this->assertStringNotContainsString( self::AUTH_KEY, $body );
        $this->assertStringNotContainsString( self::AUTH_KEY, (string) wp_json_encode( $result->to_array() ) );
        $this->assertContains( 'auth_key', Secrets::fields( new MSG91() ), 'auth_key must be a password field so logs redact it.' );
    }

    public function test_reserved_variables_cannot_replace_the_recipient() {
        $this->respond_with_fixture( 'msg91', 'success' );

        ( new MSG91() )->send_result(
            '919876543210',
            'Hello',
            $this->options(
                [
                    'variables' => [
                        'mobiles' => '910000000000',
                        'Mobile'  => '910000000001',
                        'OTP'     => '4821',
                        'list'    => [ 'x' ],
                    ],
                ]
            )
        );

        $this->assertSame(
            [
                'mobiles' => '919876543210',
                'OTP'     => '4821',
            ],
            $this->request_json()['recipients'][0]
        );
    }

    public function test_number_is_normalized() {
        $this->respond_with_fixture( 'msg91', 'success' );

        ( new MSG91() )->send_result( '+91 98765-43210', 'Hello', $this->options() );

        $this->assertSame( '919876543210', $this->request_json()['recipients'][0]['mobiles'] );
    }

    public function test_double_zero_prefix_is_stripped() {
        $this->respond_with_fixture( 'msg91', 'success' );

        ( new MSG91() )->send_result( '00919876543210', 'Hello', $this->options() );

        $this->assertSame( '919876543210', $this->request_json()['recipients'][0]['mobiles'] );
    }

    public function test_malformed_number_makes_no_request() {
        $result = ( new MSG91() )->send_result( '12345', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_invalid_number', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_missing_template_makes_no_request() {
        $result = ( new MSG91() )->send_result( '919876543210', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_template_required', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_metadata_marks_template_required() {
        $meta = ( new MSG91() )->metadata();

        $this->assertTrue( $meta['template_required'] );
        $this->assertTrue( $meta['features']['template'] );
        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( [ 'IN' ], $meta['regions'] );
    }

    public function test_invalid_credentials_in_200_is_a_failure() {
        $this->respond_with_fixture( 'msg91', 'invalid-credentials' );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'msg91_201', $result->get_error_code() );
        $this->assertSame( 'Invalid authkey', $result->get_error_message() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_unauthorized_401() {
        $this->respond_with_fixture( 'msg91', 'missing-credentials-401' );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'msg91_401', $result->get_error_code() );
        $this->assertSame( 'Unauthorized', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_number_rejected_by_provider() {
        $this->respond_with_fixture( 'msg91', 'invalid-number' );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'msg91_202', $result->get_error_code() );
        $this->assertSame( 'Invalid mobile number', $result->get_error_message() );
    }

    public function test_dlt_template_rejection_in_200_is_a_failure() {
        $this->respond_with_fixture( 'msg91', 'template-missing' );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'msg91_211', $result->get_error_code() );
        $this->assertSame( 'DLT Template Id Missing', $result->get_error_message() );
    }

    public function test_success_type_without_request_id_is_a_failure() {
        $this->respond_with( 200, [ 'type' => 'success' ] );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
    }

    public function test_ip_not_whitelisted_message() {
        $this->respond_with(
            200,
            [
                'type'    => 'error',
                'message' => 'IP not whitelisted',
                'code'    => '418',
            ]
        );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertSame( 'msg91_418', $result->get_error_code() );
        $this->assertStringContainsString( 'whitelisted', $result->get_error_message() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'msg91', 'server-error' );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'msg91_http_502', $result->get_error_code() );
        $this->assertSame( 'MSG91 server error', $result->get_error_message() );
        $this->assertSame( 502, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'msg91', [ 'auth_key' => '' ] );

        $result = ( new MSG91() )->send_result( '919876543210', 'Hello', $this->options() );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'msg91', 'validate-ok' );

        $creds = ( new MSG91() )->validate(
            $this->validate_request(
                'msg91',
                [
                    'auth_key'  => ' ' . self::AUTH_KEY . ' ',
                    'sender_id' => self::SENDER,
                ]
            )
        );

        $this->assertSame(
            [
                'auth_key'  => self::AUTH_KEY,
                'sender_id' => self::SENDER,
            ],
            $creds
        );

        $this->assert_request( 'GET', self::BALANCE_URL );
        $this->assert_request_is_safe();
        $this->assert_all_responses_used();
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'msg91', 'validate-fail' );

        $result = ( new MSG91() )->validate(
            $this->validate_request(
                'msg91',
                [
                    'auth_key'  => self::AUTH_KEY,
                    'sender_id' => self::SENDER,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'msg91_invalid_credentials', $result->get_error_code() );
        $this->assertSame( 'Invalid authkey', $result->get_error_message() );
        $this->assertSame( '207', $result->get_error_data()['code'] );
    }

    public function test_validate_rejects_non_numeric_200_body() {
        $this->respond_with( 200, 'Authentication failure' );

        $result = ( new MSG91() )->validate(
            $this->validate_request(
                'msg91',
                [
                    'auth_key'  => self::AUTH_KEY,
                    'sender_id' => self::SENDER,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'msg91_invalid_credentials', $result->get_error_code() );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new MSG91() )->validate(
            $this->validate_request(
                'msg91',
                [
                    'auth_key'  => self::AUTH_KEY,
                    'sender_id' => self::SENDER,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_rejects_missing_credentials() {
        $result = ( new MSG91() )->validate( $this->validate_request( 'msg91', [ 'auth_key' => '', 'sender_id' => self::SENDER ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_bad_sender_id() {
        $result = ( new MSG91() )->validate( $this->validate_request( 'msg91', [ 'auth_key' => self::AUTH_KEY, 'sender_id' => 'TX-1' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'msg91_invalid_sender_id', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }
}
