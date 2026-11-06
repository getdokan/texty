<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Plivo;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Plivo
 */
class PlivoTest extends GatewayTestCase {

    const AUTH_ID = 'MAXXXXXXXXXXXXXXXXXX';
    const TOKEN   = 'plivo-token-1234';
    const URL     = 'https://api.plivo.com/v1/Account/MAXXXXXXXXXXXXXXXXXX/Message/';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'plivo',
            [
                'auth_id' => self::AUTH_ID,
                'token'   => self::TOKEN,
                'from'    => '14155550100',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'plivo', 'success' );

        $result = ( new Plivo() )->send_result( '14155550123', 'Hello' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'db3ce55a-7f1d-11e1-8ea7-1231380bc196', $result->get_reference_id() );

        $this->assert_request( 'POST', self::URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Basic ' . base64_encode( self::AUTH_ID . ':' . self::TOKEN ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $this->assertSame(
            [
                'src'  => '14155550100',
                'dst'  => '14155550123',
                'text' => 'Hello',
            ],
            $this->request_form()
        );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'plivo', 'invalid-credentials' );

        $result = ( new Plivo() )->send_result( '14155550123', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'plivo_error', $result->get_error_code() );
        $this->assertSame( 'Authentication failed', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'plivo', 'invalid-number' );

        $result = ( new Plivo() )->send_result( 'nope', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'dst parameter not in valid format', $result->get_error_message() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'plivo', 'server-error' );

        $result = ( new Plivo() )->send_result( '14155550123', 'Hello' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'plivo', 'validate-ok' );

        $creds = ( new Plivo() )->validate(
            $this->validate_request(
                'plivo',
                [
                    'auth_id' => self::AUTH_ID,
                    'token'   => self::TOKEN,
                    'from'    => '14155550100',
                ]
            )
        );

        $this->assertSame( self::AUTH_ID, $creds['auth_id'] );
        $this->assert_request( 'GET', self::URL . '?limit=5&offset=0' );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'plivo', 'validate-fail' );

        $result = ( new Plivo() )->validate(
            $this->validate_request( 'plivo', [ 'auth_id' => self::AUTH_ID, 'token' => 'wrong-token' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'Authentication failed', $result->get_error_message() );
    }
}
