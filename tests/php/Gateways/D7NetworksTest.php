<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\D7Networks;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\D7Networks
 */
class D7NetworksTest extends GatewayTestCase {

    const TOKEN = 'd7-token-1234567890';

    const SEND_URL = 'https://api.d7networks.com/messages/v1/send';

    const BALANCE_URL = 'https://api.d7networks.com/messages/v1/balance';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'd7networks',
            [
                'api_token'  => self::TOKEN,
                'originator' => 'TEXTY',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'd7networks', 'success' );

        $result = ( new D7Networks() )->send_result( '+880 1712-345678', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'd9835609-a4e0-10ea-a26a-eeafbe700fef', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'messages'        => [
                    [
                        'channel'     => 'sms',
                        'recipients'  => [ '+8801712345678' ],
                        'content'     => 'Hello',
                        'msg_type'    => 'text',
                        'data_coding' => 'auto',
                    ],
                ],
                'message_globals' => [
                    'originator' => 'TEXTY',
                ],
            ],
            $this->request_json()
        );
        $this->assert_all_responses_used();
    }

    public function test_number_with_00_prefix_is_normalized() {
        $this->respond_with_fixture( 'd7networks', 'success' );

        ( new D7Networks() )->send_result( '008801712345678', 'Hello' );

        $this->assertSame( [ '+8801712345678' ], $this->request_json()['messages'][0]['recipients'] );
    }

    public function test_from_option_overrides_originator_and_unicode_is_honoured() {
        $this->respond_with_fixture( 'd7networks', 'success' );

        ( new D7Networks() )->send_result(
            '8801712345678',
            'হ্যালো',
            [
                'from'    => 'SHOP',
                'unicode' => true,
            ]
        );

        $body = $this->request_json();

        $this->assertSame( 'SHOP', $body['message_globals']['originator'] );
        $this->assertSame( 'unicode', $body['messages'][0]['data_coding'] );
        $this->assertSame( 'হ্যালো', $body['messages'][0]['content'] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'd7networks', 'invalid-credentials' );

        $result = ( new D7Networks() )->send_result( '+8801712345678', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'ACCESS_TOKEN_SIGNATURE_VERIFICATION_FAILED', $result->get_error_code() );
        $this->assertSame( 'Access token signature verification failed', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_insufficient_credit_with_list_detail() {
        $this->respond_with_fixture( 'd7networks', 'insufficient-credit' );

        $result = ( new D7Networks() )->send_result( '+8801712345678', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'INSUFFICIENT_CREDIT', $result->get_error_code() );
        $this->assertSame( 'It looks like your requests failed due to insufficient credit on our account.', $result->get_error_message() );
        $this->assertSame( 402, $result->get_http_status() );
    }

    public function test_invalid_number() {
        $this->respond_with_fixture( 'd7networks', 'invalid-number' );

        $result = ( new D7Networks() )->send_result( '123', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'value_error', $result->get_error_code() );
        $this->assertSame( 'body.messages.0.recipients.0: Invalid recipient number', $result->get_error_message() );
        $this->assertSame( 422, $result->get_http_status() );
    }

    public function test_rejected_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'd7networks', 'rejected' );

        $result = ( new D7Networks() )->send_result( '+8801712345678', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertNull( $result->get_reference_id() );
        $this->assertSame( 'd7networks_rejected', $result->get_error_code() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'd7networks', 'server-error' );

        $result = ( new D7Networks() )->send_result( '+8801712345678', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'd7networks_http_500', $result->get_error_code() );
        $this->assertSame( 'Internal Server Error', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new D7Networks() )->send_result( '+8801712345678', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'd7networks', [ 'api_token' => '', 'originator' => 'TEXTY' ] );

        $result = ( new D7Networks() )->send_result( '+8801712345678', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'd7networks', 'validate-ok' );

        $creds = ( new D7Networks() )->validate(
            $this->validate_request(
                'd7networks',
                [
                    'api_token'  => ' ' . self::TOKEN . ' ',
                    'originator' => ' TEXTY ',
                ]
            )
        );

        $this->assertSame(
            [
                'api_token'  => self::TOKEN,
                'originator' => 'TEXTY',
            ],
            $creds
        );

        $this->assert_request( 'GET', self::BALANCE_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );
        $this->assert_request_header( 'Accept', 'application/json' );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'd7networks', 'validate-fail' );

        $result = ( new D7Networks() )->validate(
            $this->validate_request(
                'd7networks',
                [
                    'api_token'  => 'wrong',
                    'originator' => 'TEXTY',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'ACCESS_TOKEN_SIGNATURE_VERIFICATION_FAILED', $result->get_error_code() );
        $this->assertSame( 'Access token signature verification failed', $result->get_error_message() );
    }

    public function test_validate_falls_back_to_format_only_when_balance_endpoint_is_gone() {
        $this->respond_with( 404, '' );

        $creds = ( new D7Networks() )->validate(
            $this->validate_request(
                'd7networks',
                [
                    'api_token'  => self::TOKEN,
                    'originator' => 'TEXTY',
                ]
            )
        );

        $this->assertSame(
            [
                'api_token'  => self::TOKEN,
                'originator' => 'TEXTY',
            ],
            $creds
        );
    }

    public function test_validate_requires_token_and_originator() {
        $result = ( new D7Networks() )->validate(
            $this->validate_request( 'd7networks', [ 'api_token' => self::TOKEN ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_metadata() {
        $meta = ( new D7Networks() )->metadata();

        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertTrue( $meta['features']['balance_check'] );
        $this->assertFalse( $meta['features']['template'] );
    }
}
