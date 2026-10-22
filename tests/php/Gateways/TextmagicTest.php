<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Textmagic;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Textmagic
 */
class TextmagicTest extends GatewayTestCase {

    const USERNAME = 'charles.conway';
    const API_KEY  = 'textmagic-key-1234';
    const SEND_URL = 'https://rest.textmagic.com/api/v2/messages';
    const USER_URL = 'https://rest.textmagic.com/api/v2/user';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'textmagic',
            [
                'username' => self::USERNAME,
                'api_key'  => self::API_KEY,
                'from'     => 'Texty',
            ]
        );
    }

    private function assert_auth_headers() {
        $this->assert_request_header( 'X-TM-Username', self::USERNAME );
        $this->assert_request_header( 'X-TM-Key', self::API_KEY );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'textmagic', 'success' );

        $result = ( new Textmagic() )->send_result( '+880 1711-000000', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '49576009', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_auth_headers();
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assertSame(
            [
                'text'   => 'Hello',
                'phones' => '8801711000000',
                'from'   => 'Texty',
            ],
            $this->request_json()
        );
        $this->assert_all_responses_used();
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'textmagic', 'success' );

        ( new Textmagic() )->send_result( '8801711000000', 'Hello', [ 'from' => '447860021130' ] );

        $this->assertSame( '447860021130', $this->request_json()['from'] );
    }

    public function test_from_is_omitted_when_empty() {
        $this->set_credentials(
            'textmagic',
            [
                'username' => self::USERNAME,
                'api_key'  => self::API_KEY,
                'from'     => '',
            ]
        );
        $this->respond_with_fixture( 'textmagic', 'success' );

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame(
            [
                'text'   => 'Hello',
                'phones' => '8801711000000',
            ],
            $this->request_json()
        );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'textmagic', 'invalid-credentials' );

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'textmagic_401', $result->get_error_code() );
        $this->assertSame( 'Wrong username and token combination.', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'textmagic', 'invalid-number' );

        $result = ( new Textmagic() )->send_result( '123', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'textmagic_400', $result->get_error_code() );
        $this->assertSame( 'phones: Invalid phone number format.', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_session_response_without_message_id_is_a_failure() {
        $this->respond_with_fixture( 'textmagic', 'session-without-message-id' );

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertNull( $result->get_reference_id() );
        $this->assertSame( 'textmagic_no_message_id', $result->get_error_code() );
        $this->assertSame( 201, $result->get_http_status() );
    }

    public function test_message_type_falls_back_to_id() {
        $this->respond_with(
            201,
            [
                'id'         => 49576010,
                'href'       => '/api/v2/messages/49576010',
                'type'       => 'message',
                'sessionId'  => 34436601,
                'bulkId'     => null,
                'messageId'  => null,
                'scheduleId' => null,
                'chatId'     => null,
            ]
        );

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '49576010', $result->get_reference_id() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'textmagic', 'server-error' );

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'textmagic_500', $result->get_error_code() );
        $this->assertSame( 'Internal server error.', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_non_json_maintenance_response() {
        $this->respond_with_fixture( 'textmagic', 'maintenance' );

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'textmagic_503', $result->get_error_code() );
        $this->assertSame( 'Textmagic returned an unexpected response (HTTP 503).', $result->get_error_message() );
        $this->assertSame( 503, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_makes_no_request() {
        $this->set_credentials( 'textmagic', [ 'username' => self::USERNAME ] );

        $result = ( new Textmagic() )->send_result( '8801711000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'textmagic', 'validate-ok' );

        $creds = ( new Textmagic() )->validate(
            $this->validate_request(
                'textmagic',
                [
                    'username' => ' ' . self::USERNAME . ' ',
                    'api_key'  => self::API_KEY,
                    'from'     => 'Texty',
                ]
            )
        );

        $this->assertSame(
            [
                'username' => self::USERNAME,
                'api_key'  => self::API_KEY,
                'from'     => 'Texty',
            ],
            $creds
        );

        $this->assert_request( 'GET', self::USER_URL );
        $this->assert_request_is_safe();
        $this->assert_auth_headers();
        $this->assert_all_responses_used();
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'textmagic', 'validate-fail' );

        $result = ( new Textmagic() )->validate(
            $this->validate_request(
                'textmagic',
                [
                    'username' => self::USERNAME,
                    'api_key'  => 'wrong-key',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'textmagic_401', $result->get_error_code() );
        $this->assertSame( 'Wrong username and token combination.', $result->get_error_message() );
        $this->assertSame(
            [
                'code'    => 401,
                'message' => 'Wrong username and token combination.',
            ],
            $result->get_error_data()
        );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Textmagic() )->validate(
            $this->validate_request(
                'textmagic',
                [
                    'username' => self::USERNAME,
                    'api_key'  => self::API_KEY,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_rejects_missing_credentials() {
        $result = ( new Textmagic() )->validate(
            $this->validate_request(
                'textmagic',
                [
                    'username' => self::USERNAME,
                    'api_key'  => '',
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_metadata() {
        $meta = ( new Textmagic() )->metadata();

        $this->assertSame( 'beta', $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertSame( [], $meta['regions'] );
        $this->assertTrue( $meta['features']['balance_check'] );
        $this->assertFalse( $meta['features']['template'] );
    }
}
