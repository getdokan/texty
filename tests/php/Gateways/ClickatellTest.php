<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Clickatell;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Clickatell
 */
class ClickatellTest extends GatewayTestCase {

    public function set_up() {
        parent::set_up();

        $this->set_credentials( 'clickatell', [ 'key' => 'clickatell-key-1234' ] );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'clickatell', 'success' );

        $result = ( new Clickatell() )->send_result( '27999000001', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '1d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a', $result->get_reference_id() );

        $this->assert_request( 'POST', 'https://platform.clickatell.com/messages' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'clickatell-key-1234' );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assertSame(
            [
                'to'      => [ '27999000001' ],
                'content' => 'Hello',
            ],
            $this->request_json()
        );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'clickatell', 'invalid-credentials' );

        $result = ( new Clickatell() )->send_result( '27999000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '1', $result->get_error_code() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_rejected_message_inside_202_is_a_failure() {
        $this->respond_with_fixture( 'clickatell', 'invalid-number' );

        $result = ( new Clickatell() )->send_result( '123', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '105', $result->get_error_code() );
        $this->assertSame( 'Invalid destination address', $result->get_error_message() );
    }

    public function test_server_error_with_empty_body() {
        $this->respond_with_fixture( 'clickatell', 'server-error' );

        $result = ( new Clickatell() )->send_result( '27999000001', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '500', $result->get_error_code() );
    }

    public function test_validate_is_format_only() {
        $creds = ( new Clickatell() )->validate( $this->validate_request( 'clickatell', [ 'key' => ' clickatell-key-1234 ' ] ) );

        $this->assertSame( [ 'key' => 'clickatell-key-1234' ], $creds );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_missing_key() {
        $result = ( new Clickatell() )->validate( $this->validate_request( 'clickatell', [ 'key' => '' ] ) );

        $this->assertWPError( $result );
    }
}
