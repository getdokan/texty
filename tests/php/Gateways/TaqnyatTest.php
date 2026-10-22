<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Taqnyat;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Taqnyat
 */
class TaqnyatTest extends GatewayTestCase {

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'taqnyat',
            [
                'token'  => 'taqnyat-token-1234',
                'sender' => 'sender name 1',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'taqnyat', 'success' );

        $result = ( new Taqnyat() )->send_result( '+966500000000', 'Hello from Texty' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '5452899970', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', 'https://api.taqnyat.sa/v1/messages' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer taqnyat-token-1234' );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'recipients' => [ '966500000000' ],
                'body'       => 'Hello from Texty',
                'sender'     => 'sender name 1',
            ],
            $this->request_json()
        );
    }

    public function test_string_message_id_from_openapi() {
        $this->respond_with_fixture( 'taqnyat', 'success-string-id' );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '5829452722', $result->get_reference_id() );
    }

    public function test_number_with_00_prefix_and_spaces_is_normalized() {
        $this->respond_with_fixture( 'taqnyat', 'success' );

        ( new Taqnyat() )->send_result( '00966 50-000 0000', 'Hello' );

        $this->assertSame( [ '966500000000' ], $this->request_json()['recipients'] );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'taqnyat', 'success' );

        ( new Taqnyat() )->send_result( '966500000000', 'Hello', [ 'from' => 'OtherSender' ] );

        $this->assertSame( 'OtherSender', $this->request_json()['sender'] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'taqnyat', 'invalid-credentials' );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'taqnyat_invalid_credentials', $result->get_error_code() );
        $this->assertSame( 'invalid credentials information', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_number() {
        $this->respond_with_fixture( 'taqnyat', 'invalid-number' );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'taqnyat_invalid_number', $result->get_error_code() );
        $this->assertSame( 'Mobile(s) number(s) is not specified or incorrect', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_local_number_is_rejected_without_a_request() {
        $result = ( new Taqnyat() )->send_result( '0500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'taqnyat_invalid_number', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_rejected_recipient_inside_201_is_a_failure() {
        $this->respond_with_fixture( 'taqnyat', 'rejected-recipient' );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'taqnyat_rejected', $result->get_error_code() );
        $this->assertSame( 201, $result->get_http_status() );
        $this->assertSame( '[966500000000,]', $result->get_raw_response()['rejected'] );
    }

    public function test_insufficient_balance() {
        $this->respond_with_fixture( 'taqnyat', 'insufficient-balance' );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'taqnyat_insufficient_balance', $result->get_error_code() );
        $this->assertSame( 'Your balance is 0', $result->get_error_message() );
    }

    public function test_unregistered_sender() {
        $this->respond_with_fixture( 'taqnyat', 'unregistered-sender' );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'taqnyat_invalid_sender', $result->get_error_code() );
        $this->assertSame( 'Sender Name not active', $result->get_error_message() );
    }

    public function test_2xx_without_message_id_is_a_failure() {
        $this->respond_with( 200, [ 'statusCode' => 200 ] );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'taqnyat', 'server-error' );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'taqnyat_503', $result->get_error_code() );
        $this->assertSame( 'Service Unavailable', $result->get_error_message() );
        $this->assertSame( 503, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials( 'taqnyat', [ 'token' => '', 'sender' => 'sender name 1' ] );

        $result = ( new Taqnyat() )->send_result( '966500000000', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'taqnyat', 'validate-ok' );

        $creds = ( new Taqnyat() )->validate(
            $this->validate_request(
                'taqnyat',
                [
                    'token'  => ' taqnyat-token-1234 ',
                    'sender' => 'sender name 1',
                ]
            )
        );

        $this->assertSame(
            [
                'token'  => 'taqnyat-token-1234',
                'sender' => 'sender name 1',
            ],
            $creds
        );

        $this->assert_request( 'GET', 'https://api.taqnyat.sa/v1/messages/senders' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer taqnyat-token-1234' );
        $this->assertNull( $this->last_request()['body'] );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'taqnyat', 'validate-fail' );

        $result = ( new Taqnyat() )->validate(
            $this->validate_request( 'taqnyat', [ 'token' => 'bad', 'sender' => 'sender name 1' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'taqnyat_invalid_credentials', $result->get_error_code() );
        $this->assertSame( 'invalid credentials information', $result->get_error_message() );
        $this->assertSame( 401, $result->get_error_data()['statusCode'] );
    }

    public function test_validate_rejects_sender_with_wrong_case() {
        $this->respond_with_fixture( 'taqnyat', 'validate-ok' );

        $result = ( new Taqnyat() )->validate(
            $this->validate_request( 'taqnyat', [ 'token' => 'taqnyat-token-1234', 'sender' => 'Sender Name 1' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'taqnyat_invalid_sender', $result->get_error_code() );
    }

    public function test_validate_rejects_inactive_sender() {
        $this->respond_with_fixture( 'taqnyat', 'validate-ok' );

        $result = ( new Taqnyat() )->validate(
            $this->validate_request( 'taqnyat', [ 'token' => 'taqnyat-token-1234', 'sender' => 'sender name 2' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'taqnyat_sender_inactive', $result->get_error_code() );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Taqnyat() )->validate(
            $this->validate_request( 'taqnyat', [ 'token' => 'taqnyat-token-1234', 'sender' => 'sender name 1' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_token_and_sender() {
        $result = ( new Taqnyat() )->validate(
            $this->validate_request( 'taqnyat', [ 'token' => 'taqnyat-token-1234', 'sender' => '' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }
}
